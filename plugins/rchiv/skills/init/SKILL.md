---
name: rchiv:init
version: "1.2.0"
description: >
  Generate a .rchiv.json iteratively in 3 interactive phases (config → entrypoints → dependencies).
  This skill should be used when the user says "/rchiv:init", "rchiv init",
  "rchiv generate", "generate rchiv", or wants to generate a .rchiv.json step by step for large
  services after running /rchiv:config.
disable-model-invocation: true
allowed-tools: Task, Bash(git:*), Bash(gh api:*), Bash(base64 -d:*), Bash(grepai search:*), Bash(grepai trace:*), Bash(grepai status:*), Read, Write, Edit, Glob, Grep, AskUserQuestion
---

# Génération itérative d'un `.rchiv.json`

Ce mode découpe la génération en **3 phases interactives** avec persistance intermédiaire, pensé pour les gros services ou quand on veut valider chaque étape.

Toutes les interactions sont en français. La version du format est déterminée dynamiquement depuis le schéma du registry.

Le format est décrit dans `references/format.md`. La logique de résolution registry est dans `references/registry-resolution.md`. Les règles de génération sont dans `references/generation-rules.md`.

---

## Contexte dynamique

- grepai status: !`grepai status --no-ui 2>&1 || echo "GREPAI_UNAVAILABLE"`
- Registry schema: !`registry=$(jq -r '.registry' .rchiv-config.json 2>/dev/null || echo 'Evaneos/rchiv-registry') && latest=$(gh api "repos/$registry/contents/schemas" --jq '[.[].name] | sort | last') && gh api "repos/$registry/contents/schemas/$latest" --jq '.content' | base64 -d 2>&1 || echo "REGISTRY_UNAVAILABLE"`

## Étape 0 : Gate — init obligatoire

Vérifier que `.rchiv-config.json` existe à la racine du projet.

**S'il n'existe pas** → afficher :
> "Lance `/rchiv:config` d'abord pour configurer le projet."

→ **STOP**. Ne rien faire d'autre.

## Étape 1 : Détection grepai

Interpréter le résultat de `grepai status` dans le contexte dynamique ci-dessus :
- Si le résultat contient des fichiers indexés → `grepai_available = true`
- Si le résultat contient `GREPAI_UNAVAILABLE` ou une erreur → `grepai_available = false` (utiliser Grep/Glob/Read + agents Explore)

---

## Fichiers produits

| Fichier | Rôle | Écrit par |
|---------|------|-----------|
| `.rchiv.json` | Structure finale (config + handlers enrichis) | Phase 1, puis Phase 3 |
| `.rchiv-entrypoints.json` | Liste intermédiaire des handlers (sans dépendances) | Phase 2 |

`.rchiv-entrypoints.json` est un fichier de travail temporaire, supprimé à la fin de la Phase 3.

## Pré-requis

Vérifier qu'un fichier `.rchiv.json` n'existe PAS déjà à la racine du projet. S'il existe, proposer `/rchiv:update`

**Détection de reprise :** Si `.rchiv.json` existe ET `.rchiv-entrypoints.json` existe aussi, c'est une session itérative interrompue. Proposer au dev via `AskUserQuestion` :

> "J'ai détecté une session itérative en cours ({N} entrypoints dans `.rchiv-entrypoints.json`, {M} handlers déjà enrichis dans `.rchiv.json`). Tu veux reprendre ou recommencer ?"

- **Reprendre** → sauter à la Phase 3
- **Recommencer** → supprimer les deux fichiers et repartir de zéro

---

## Phase 1 : Registry + configuration

### 1.1 Schema parsing

Parser le schéma du registry injecté dans le contexte dynamique pour extraire : version cible, champs requis/optionnels, types de handlers supportés, structure des dépendances, URL `$schema`. **Le schéma du registry prime sur la spec hardcodée.**

Si le résultat contient `REGISTRY_UNAVAILABLE`, fetcher le schéma manuellement :
1. Lire le registry depuis `.rchiv-config.json` : `jq -r '.registry' .rchiv-config.json`
2. `gh api repos/{repo}/contents/schemas --jq '[.[].name] | sort | last'` pour la dernière version
3. `gh api repos/{repo}/contents/schemas/{latest} --jq '.content' | base64 -d` pour le fetcher

### 1.2 Choix du mode

Poser la question au dev via `AskUserQuestion` :

> "Tu connais bien ce projet ou tu veux que j'explore d'abord ?"

- **Mode guidé** : interview du dev (3 questions sur entry points, config, services externes)
- **Mode discovery** : auto-détection framework + exploration (voir `references/framework-detection.md`)

### 1.3 Détection framework/config

Appliquer la procédure correspondante au mode choisi (guidé ou discovery) en utilisant `references/framework-detection.md` pour la détection framework et la détection complémentaire (brokers, databases, libraries).

À la fin de la détection, présenter les trouvailles au dev via `AskUserQuestion` :

```
Voici ce que j'ai trouvé pour la configuration du service :

- Framework : {framework}
- Brokers : {liste avec types}
- Databases : {liste avec types}
- Libraries internes : {liste}
- Fichiers de config : {liste}

Est-ce que c'est correct et complet ?
```

### 1.4 Écriture du `.rchiv.json` initial

Après validation, écrire le `.rchiv.json` initial avec la structure de base :

```json
{
  "$schema": "...",
  "version": "{version du schéma}",
  "name": "{nom du service}",
  "semantic": { "purpose": "...", "domain": "...", "tags": [...], "summary": "..." },
  "_meta": { "repo": "...", "commit": "...", "generated_at": "..." },
  "registry": { "repo": "..." },
  "brokers": { ... },
  "databases": { ... },
  "libraries": [ ... ],
  "handlers": [],
  "connections": {}
}
```

Appliquer les règles de `references/generation-rules.md` (omettre les sections vides, `$schema` dynamique, etc.).

Confirmer : "**Phase 1 terminée.** Le `.rchiv.json` a été créé avec la structure de base (sans handlers)."

---

## Phase 2 : Liste des entrypoints

### 2.1 Découverte des handlers

Appliquer la procédure décrite dans `references/handler-discovery.md` (grepai search si disponible, sinon Task/Explore).

### 2.2 Écriture de `.rchiv-entrypoints.json`

Écrire la liste dans **`.rchiv-entrypoints.json`** (PAS dans `.rchiv.json`) :

```json
{
  "service": "{nom du service — identique à .rchiv.json}",
  "generated_at": "{ISO 8601}",
  "entrypoints": [
    {
      "type": "http",
      "method": "GET",
      "path": "/api/users",
      "description": "Récupère la liste des utilisateurs",
      "source": "src/Controller/UserController.php:42",
      "done": false
    },
    {
      "type": "event",
      "queue": "payment.completed",
      "description": "Active le compte après paiement",
      "source": "src/EventListener/PaymentListener.php:15",
      "done": false
    }
  ]
}
```

**Format des entrypoints :**
- Chaque entrypoint a les champs d'identification du handler (type + identifiant) + `description` + `source` (fichier:ligne)
- PAS de `dependencies` à ce stade
- PAS de `status`, `tags`, `bindings`, `dead_letter` à ce stade

### 2.3 Validation

Demander au dev de relire `.rchiv-entrypoints.json` et valider via `AskUserQuestion` :

```
J'ai écrit {N} entrypoints dans `.rchiv-entrypoints.json` (HTTP: {n}, Events: {n}, Cron: {n}, CLI: {n}).

Relis le fichier et dis-moi si la liste est complète et correcte.
```

Si le dev signale des manques ou erreurs, ajuster le fichier et re-valider.

Confirmer : "**Phase 2 terminée.** Prêt pour l'analyse des dépendances."

---

## Phase 3 : Parcours interactif des dépendances

### 3.1 Charger l'état

- Lire `.rchiv.json` (structure + handlers déjà enrichis)
- Lire `.rchiv-entrypoints.json` (liste complète des entrypoints)
- Calculer les entrypoints restants à analyser : ceux de `.rchiv-entrypoints.json` qui n'ont pas `"done": true`

### 3.2 Grouper en batches

Grouper en batches de 3 à 5 entrypoints, par type puis par proximité de fichier source.

### 3.3 Pour chaque batch

a. Présenter le batch au dev via `AskUserQuestion` :
```
Batch {X}/{Y} — Je vais analyser les dépendances de ces {N} handlers :

- {type} {identifiant} — {description} ({source})
- ...

C'est parti ?
```

b. Tracer les dépendances en appliquant `references/dependency-analysis.md` (grepai trace graph si disponible, sinon Task/Explore) :
```
Pour chaque handler ci-dessous, trace les dépendances en lisant le code source.
Cherche :
- calls: appels HTTP sortants vers d'autres services (méthode, path, service cible)
- reads: tables et colonnes lues — identifier la database. **Colonnes obligatoires** : tracer les colonnes SQL (SELECT, findBy, DQL, QueryBuilder). Si indéterminables → `["*"]`.
- writes: tables et colonnes écrites — identifier la database. **Colonnes obligatoires** : tracer les colonnes (INSERT, UPDATE, persist, flush). Si indéterminables → `["*"]`.
- emits: messages publiés — identifier l'exchange, la routing_key et le broker
- bindings: pour les handlers event, chercher dans les configs de broker quels exchange/routing_key sont bindés à la queue
- dead_letter: pour les handlers event, chercher la config de dead letter exchange

Handlers à analyser :
{liste des handlers avec fichiers sources}
```

c. Présenter les trouvailles via `AskUserQuestion` :
```
Voici les dépendances trouvées pour ce batch :

**{handler 1 — type identifiant}:**
- calls: {liste ou "aucun"}
- reads: {liste ou "aucun"}
- writes: {liste ou "aucun"}
- emits: {liste ou "aucun"}
- bindings: {liste ou "aucun" — si event}

**{handler 2}:**
...

Est-ce correct ?
```

d. **Boucle de correction** : si le dev signale des erreurs, corriger et re-présenter.

e. Après validation, mettre à jour les deux fichiers :

   **`.rchiv.json`** :
   - Ajouter les handlers enrichis au tableau `handlers` avec :
     - `dependencies` (calls, reads, writes, emits)
     - `bindings` et `dead_letter` pour les handlers event (voir `references/dependency-analysis.md` section "Bindings et dead_letter")
     - `tags` : obligatoire — au moins un tag de canal + tag de domaine si applicable (voir `references/generation-rules.md` section "Tags handlers")
     - `status` si différent de `"active"`
   - Mettre à jour `connections` si de nouveaux services ont été découverts
   - Mettre à jour `brokers` si de nouveaux brokers ont été découverts
   - Mettre à jour `databases` si de nouvelles databases ont été découvertes

   **`.rchiv-entrypoints.json`** :
   - Marquer les entrypoints traités avec `"done": true` pour permettre la reprise en cas d'interruption

f. Confirmer : "Batch {X}/{Y} terminé. {remaining} entrypoints restants."

### 3.4 Après le dernier batch

a. **Registry resolution** : appliquer `references/registry-resolution.md` (si registry configuré)

b. **Nettoyage** : supprimer `.rchiv-entrypoints.json`

c. **Validation finale** : présenter un résumé structuré au dev et demander validation via `AskUserQuestion` :

```
Le .rchiv.json a été généré. Voici un résumé :

- Handlers : {N} (HTTP: {n}, Events: {n}, Cron: {n}, CLI: {n})
- Connexions : {liste des services}
- Brokers : {liste}
- Databases : {liste}
- Couverture colonnes : {n}/{m} reads/writes avec colonnes explicites ({p}% — le reste utilise ["*"])

Est-ce que le résultat est correct ? (oui / non — si non, décris ce qu'il faut corriger)
```

**Boucle de correction** : corriger et re-valider tant que le dev n'a pas validé.

d. **Rapport final** :

```
.rchiv.json généré avec succès !

- Handlers : {N} (HTTP: {n}, Events: {n}, Cron: {n}, CLI: {n})
- Dépendances : {N} calls, {N} reads, {N} writes, {N} emits
- Couverture colonnes : {n}/{m} reads/writes avec colonnes explicites ({p}%) — si < 100%, les `["*"]` sont listés dans les zones d'incertitude
- Brokers : {liste des brokers}
- Databases : {liste des databases}
- Connexions : {liste des services} (registry: {N} résolus / {M} total)
- Registry : {repo ou "non configuré"}
- Fichiers analysés : {N}
- Zones d'incertitude : {liste si applicable — ex: "colonnes de la table X pas certaines"}
```

---

## Règles importantes

1. **Langue** : Le `.rchiv.json` est en **français** (descriptions, purpose, summary) avec identifiants techniques en anglais (noms de tables, routing keys, paths). Les interactions avec le dev sont en français. Voir `references/generation-rules.md` section "Langue et qualité des descriptions".
2. **Idempotence** : Si `.rchiv.json` existe déjà (sans `.rchiv-entrypoints.json`), router vers `/rchiv:update`.
3. **Colonnes obligatoires** : Chaque `reads` et `writes` DOIT avoir un champ `columns`. Tracer les colonnes dans le code source. Si indéterminables → `"columns": ["*"]` et signaler en zone d'incertitude.
4. **Pas de secrets** : Ne jamais inclure de credentials, tokens ou URLs avec credentials dans le `.rchiv.json`.
5. **Git** : Toujours récupérer le commit et le repo depuis git, ne pas les deviner.
6. **JSON valide** : 2 espaces d'indentation.
7. **Références cohérentes** : broker/database/service doivent correspondre aux clés déclarées.
8. **Registry** : Quand `registry` est présent, l'utiliser systématiquement pour la résolution cross-service.
9. **grepai** : Quand grepai est disponible, privilégier `--toon` pour les sorties LLM. Utiliser `--mode precise` pour le traçage critique. Si grepai retourne des résultats incomplets, basculer sur Grep/Glob/Read.

## Registry sync

Appliquer `references/registry-sync.md`.

---

## Done

Quand le registry sync est terminé (ou refusé), **STOP**. Ne pas enchaîner d'autre action.
