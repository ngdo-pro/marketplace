---
name: os:idea
version: "1.0"
description: >
  This skill should be used when the user says "/os:idea", "nouvelle idée",
  "new idea", "idea create", "idea iterate", "idea list", "j'ai une idée",
  "brainstorm", "capture an idea", "iterate on an idea", "list my ideas",
  or wants to capture, iterate on, or list ideas stored in Obsidian.
---

# Skill `/os:idea`

Tu es un assistant qui aide à capturer des idées et les faire mûrir. Tu gères un espace `~/vault/OS/ideas/` dans le vault Obsidian avec 3 sous-commandes : `create`, `iterate`, `list`.

---

## Phase 1 : Parser les arguments

À partir de `$ARGUMENTS`, détermine la sous-commande :

| Argument | Sous-commande |
|----------|---------------|
| vide, `create`, ou texte libre sans slug existant | `create` |
| `iterate {slug} "direction..."` | `iterate` |
| `list` | `list` |

**Détection de slug existant :** avant de décider entre `create` et `iterate`, vérifier si `~/vault/OS/ideas/{argument}.md` existe. Si oui, proposer `iterate` au lieu de `create`.

---

## Phase 2A : Mode `create`

### Étape 1 : Mini-interview

Si `$ARGUMENTS` contient du texte (hors "create"), l'utiliser comme point de départ pour l'idée.

Lancer une mini-interview via `AskUserQuestion` (3-5 questions adaptées au contexte) :

- C'est quoi l'idée ? (si pas déjà dans les arguments)
- Quel problème ou opportunité ça adresse ?
- D'où ça vient ? (contexte, déclencheur)
- Pour qui / dans quel cadre ?
- Questions spécifiques selon le type d'idée détecté

Adapter les questions au contexte : ne pas poser de questions dont la réponse est déjà dans les arguments. Poser des questions qui aident à structurer la pensée.

### Étape 2 : Générer le slug

- Titre en kebab-case, lowercase, sans accents
- Exemple : "Cache distribué pour les sessions" → `cache-distribue-pour-les-sessions`
- Le slug est **immutable** après création

### Étape 3 : Écrire le fichier

```bash
mkdir -p ~/vault/OS/ideas/
```

Écrire `~/vault/OS/ideas/{slug}.md` avec le template suivant :

```markdown
# {Titre de l'idée}

{Corps structuré par Claude à partir de l'interview.
Texte libre, sections qui émergent naturellement du contenu.
Peut inclure des [[wikilinks]] vers d'autres fichiers du vault si pertinent.}

---

## Itérations

### {YYYY-MM-DD} — Création
{Résumé de ce qui a été capturé lors de l'interview initiale.}
```

**Règles pour le corps :**
- Structurer de façon naturelle (pas de template rigide)
- Utiliser des sous-sections si l'idée est riche
- Ajouter des `[[wikilinks]]` vers d'autres idées ou fichiers du vault si pertinent
- Écrire en français

### Étape 4 : Mettre à jour l'index

Si `~/vault/OS/ideas/index.md` existe, y ajouter la nouvelle entrée dans le tableau au format :

```
| [[{slug}]] | {Première phrase du corps} | {YYYY-MM-DD} | {YYYY-MM-DD} | 1 |
```

Insérer la ligne en respectant le tri par date de dernière itération (plus récente en premier).

---

## Phase 2B : Mode `iterate`

### Étape 1 : Charger le contexte

1. Lire `~/vault/OS/ideas/{slug}.md`
2. Si le fichier contient des `[[wikilinks]]`, lire les fichiers liés pour enrichir le contexte
3. Résumer brièvement l'état actuel de l'idée à l'utilisateur

### Étape 2 : Dialogue interactif

Lancer un dialogue orienté par la direction donnée en argument :

1. Claude résume l'idée et la direction demandée
2. Claude challenge l'idée et propose des pistes, guidé par la direction
3. L'utilisateur répond, rebondit
4. Le dialogue continue jusqu'à ce que Claude demande si l'utilisateur veut conclure
5. Quand l'utilisateur confirme, passer à l'étape 3

Utiliser `AskUserQuestion` pour structurer les échanges quand des choix se présentent.

### Étape 3 : Mettre à jour le fichier

1. **Réécrire le corps** de l'idée (tout ce qui est avant le `---`) pour refléter l'état actuel après la session de brainstorm
2. **Ajouter une entrée** dans le log d'itérations :

```markdown
### {YYYY-MM-DD} — "{direction donnée}"
{Résumé des évolutions de cette session}
```

3. Ajouter des `[[wikilinks]]` si pertinent (liens vers d'autres idées ou fichiers du vault)
4. Mettre à jour `~/vault/OS/ideas/index.md` si il existe (mettre à jour la date de dernière itération et le compteur)

---

## Phase 2C : Mode `list`

### Étape 1 : Scanner les idées

1. Scanner `~/vault/OS/ideas/*.md` (exclure `index.md`)
2. Pour chaque fichier :
   - Extraire le titre (H1)
   - Extraire la première phrase du corps (après le H1, avant toute sous-section)
   - Compter les entrées `###` dans `## Itérations` → nombre d'itérations
   - Date de création = date de la première itération (premier `###` sous `## Itérations`)
   - Dernière itération = date de la dernière entrée `###`

### Étape 2 : Générer l'index

Écrire/réécrire `~/vault/OS/ideas/index.md` :

```markdown
# Idées

| Idée | Description | Créée le | Dernière itération | Itérations |
|------|-------------|----------|--------------------|------------|
| [[{slug}]] | {Première phrase} | {YYYY-MM-DD} | {YYYY-MM-DD} | {N} |
```

Trier par date de dernière itération (plus récente en premier).

### Étape 3 : Afficher un résumé

Afficher dans le terminal un résumé des idées avec le nombre total et les plus récentes.

---

## Phase 3 : Résumé

Après chaque sous-commande, afficher un résumé concis :

- **create** : chemin du fichier créé, titre, suggestion de `/os:idea iterate {slug} "direction"` pour la prochaine session
- **iterate** : chemin du fichier mis à jour, résumé des changements, nombre total d'itérations
- **list** : nombre d'idées, chemin de l'index généré

---

## Règles importantes

1. **Langue** : Français (même convention que les recaps)
2. **Liens** : Utiliser des `[[wikilinks]]` Obsidian pour les liens cross-vault
3. **Idempotence** : `/os:idea list` régénère l'index complet à chaque fois
4. **Slug immutable** : le slug ne change pas après création (git gère le renommage si besoin)
5. **Pas de frontmatter** : pas de YAML frontmatter dans les fichiers idées (cohérent avec le reste du vault)
6. **Pas de statuts** : l'idée évolue organiquement, le log d'itérations montre la progression
7. **Créer le dossier** : `mkdir -p ~/vault/OS/ideas/` à la première utilisation
8. **Wikilinks contextuels** : lire les fichiers liés lors d'un `iterate` pour enrichir le contexte
