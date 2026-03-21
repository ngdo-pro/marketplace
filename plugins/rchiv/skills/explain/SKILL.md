---
name: rchiv:explain
version: "1.1.0"
description: >
  Query architecture from the .rchiv.json file. This skill should be used when the user
  says "/rchiv:explain", "explain architecture", "query boundaries", "analyze rchiv",
  "rchiv impact analysis", or wants to ask questions about the service's architecture.
  Supports text and HTML output.
argument-hint: '["question" | --html "question" | --html]'
disable-model-invocation: true
allowed-tools: Task, Bash(gh api:*), Bash(base64 -d:*), Bash(date:*), Bash(uname:*), Bash(xdg-open:*), Bash(open:*), Read, Write
---

# Interroger l'architecture

Tu réponds à des questions sur l'architecture d'un service en analysant son `.rchiv.json`.

Toutes les interactions sont en français.

---

## Étape 0 : Routing

Parser `$ARGUMENTS` pour déterminer le mode :
- Si `--html` est présent avec une question → mode **HTML réponse**
- Si `--html` est présent sans question → mode **HTML dashboard**
- Sinon → mode **texte**

---

## Pré-requis

Vérifier qu'un fichier `.rchiv.json` existe à la racine. S'il n'existe pas, proposer `/rchiv:config`.

## Étape 1 : Charger le contexte

1. Lire le `.rchiv.json` du projet courant
2. **Déterminer si la question est cross-service.** Patterns de détection :
   - "qui utilise/appelle/consomme..." (recherche inversée)
   - "quel impact si je modifie..." (impact analysis cross-service)
   - "quels services dépendent de..." / "qui dépend de..."
   - "trace le flux de..." / "montre la chaîne..."
   - Toute mention d'un service externe ou d'une interaction inter-services
3. **Si question cross-service** et que `registry` est configuré :
   a. Fetcher `graph.json` depuis le registry via :
      ```
      gh api repos/{registry.repo}/contents/graph.json -q '.content' | base64 -d
      ```
   b. Analyser le JSON du graphe en contexte pour répondre à la question. Le graphe contient :
      - `endpoints` : par service, liste des endpoints avec leurs `callers`
      - `events` : par routing key, les `emitters` et `listeners`
      - `tables` : par service/database/table, les `readers` et `writers`
      - `services` : métadonnées de chaque service (repo, domain, purpose)
   c. Si besoin de détails supplémentaires sur 1-2 services, faire un **fallback ciblé** : fetcher le `.rchiv.json` complet du service concerné via `gh api repos/{owner}/{repo}/contents/{path}` (1-2 appels max)
   d. Si le graphe n'est pas disponible (404), tomber en fallback : fetcher `services.json` puis les `.rchiv.json` individuels
4. **Si question locale** : répondre avec les données du `.rchiv.json` local
5. Si pas de `registry` configuré : répondre uniquement avec les données locales

## Étape 2 : Analyser et répondre

Répondre à la question en raisonnant sur les données du `.rchiv.json` et/ou du graphe.

**Types de questions supportées :**

| Catégorie | Source de données | Exemples |
|-----------|-------------------|----------|
| **Passe-plat** | `.rchiv.json` local | "Quels endpoints ne font que passe-plat ?" → handlers HTTP dont les dépendances ne contiennent qu'un seul `calls` et aucun `reads`/`writes`/`emits` |
| **Impact local** | `.rchiv.json` local | "Quel est l'impact de modifier la table users ?" → tous les handlers locaux qui `reads` ou `writes` la table `users` |
| **Impact cross-service** | `graph.json` | "Quels services lisent la table users ?" → chercher dans `tables` du graphe |
| **Couplage** | `graph.json` | "Quels services dépendent de nous ?" → chercher dans `endpoints[service].callers` du graphe |
| **Événements** | `graph.json` | "Qui consomme l'event user.created ?" → chercher dans `events` du graphe |
| **Flux** | `graph.json` | "Trace le flux depuis POST /api/users" → suivre les chaînes (calls → emits → listeners → calls) |
| **Bindings** | `.rchiv.json` local | "Quels exchanges alimentent la queue X ?" → filtrer `handlers[].bindings` |
| **Tables** | `.rchiv.json` local | "Quels handlers écrivent dans la table orders ?" → filtrer `handlers[].dependencies.writes` |
| **Vue d'ensemble** | `.rchiv.json` local | "Résume ce service" → utiliser `semantic` + stats |
| **Anomalies** | `.rchiv.json` local | "Y a-t-il des handlers sans dépendances ?" → handlers avec `dependencies` vide ou absent |

## Étape 3 : Présenter les résultats

Répondre de manière structurée avec :
- La réponse directe à la question
- Les handlers/dépendances concernés (avec type, queue/path, description)
- Si cross-service : les services impliqués
- Si le graphe a été utilisé : mentionner sa date de génération (`generated_at`)
- Si des données n'ont pas pu être récupérées : le signaler clairement

**Si mode texte → STOP ici.**

**Si mode HTML → continuer ci-dessous.**

---

## Mode HTML réponse (`--html "question"`)

Générer une page HTML autonome (self-contained) contenant la réponse à la question.

**Template HTML :** Lire le fichier `references/template-explain.html`.

**Conversion Markdown → HTML :**

Convertir le contenu de la réponse Markdown en HTML en appliquant ces transformations :
- `## titre` → `<h2>titre</h2>`
- `### titre` → `<h3>titre</h3>`
- `**texte**` → `<strong>texte</strong>`
- `` `code` `` → `<code>code</code>`
- Blocs ``` → `<pre><code>...</code></pre>`
- `- item` → `<ul><li>item</li></ul>`
- `1. item` → `<ol><li>item</li></ol>`
- `| col | col |` → `<table>` avec `<th>` pour la première ligne et `<td>` pour les suivantes
- Paragraphes séparés par des lignes vides → `<p>...</p>`

**Important — Accents et caractères spéciaux :** Ne jamais encoder les caractères accentués en entités HTML. Écrire directement les caractères UTF-8 dans le HTML. Le `<meta charset="UTF-8">` dans le `<head>` garantit le bon rendu.

**Remplacement des placeholders :**
- `{SERVICE_NAME}` : champ `name` du `.rchiv.json`
- `{QUESTION}` : la question posée par l'utilisateur
- `{ANSWER_HTML}` : la réponse convertie en HTML
- `{DATE}` : date du jour (ISO 8601)
- `{COMMIT}` : champ `_meta.commit` du `.rchiv.json` (8 premiers caractères)

Passer à **Écrire et ouvrir**.

---

## Mode HTML dashboard (`--html` sans question)

Charger le `.rchiv.json` et générer un dashboard HTML complet. Toutes les sections sont générées dynamiquement à partir des données du `.rchiv.json`. Les sections vides ne sont pas générées.

**Template HTML :** Lire le fichier `references/template-dashboard.html`.

**Règles de génération du dashboard :**

- Itérer sur les données du `.rchiv.json` pour remplir chaque section
- Ne PAS générer les sections dont les données sont vides (ex: pas de section Cron si aucun handler cron)
- Ne PAS générer les liens sidebar correspondants si la section n'existe pas
- Les compteurs `{N}` dans la sidebar correspondent au nombre d'éléments de chaque section
- Les dependency pills ne sont affichées que si le handler a des dépendances correspondantes
- Les `stat-card` dans Overview comptent dynamiquement depuis les données du `.rchiv.json`
- La section `semantic` n'est affichée que si elle existe dans le `.rchiv.json`
- La section `_meta` n'est affichée que si elle existe dans le `.rchiv.json`

Passer à **Écrire et ouvrir**.

---

## Écrire et ouvrir

1. **Générer le timestamp** via `date +%s`
2. **Écrire le fichier** via l'outil Write dans `/tmp/rchiv-explain-{timestamp}.html`
3. **Détecter l'OS** via `uname -s` :
   - `Linux` → ouvrir avec `xdg-open /tmp/rchiv-explain-{timestamp}.html`
   - `Darwin` → ouvrir avec `open /tmp/rchiv-explain-{timestamp}.html`
4. **Afficher la confirmation** :

```
Page HTML générée et ouverte dans le navigateur.

/tmp/rchiv-explain-{timestamp}.html
```

---

## Règles importantes

1. **Langue** : Les interactions avec le dev sont en français.
2. **Registry** : Quand `registry` est présent, l'utiliser systématiquement pour la résolution cross-service. Si un service ne peut pas être résolu, répondre partiellement et signaler les services manquants.

---

## Done

Quand la réponse est affichée (texte ou HTML), **STOP**.
