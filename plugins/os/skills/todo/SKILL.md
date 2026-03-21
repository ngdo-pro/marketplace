---
name: os:todo
version: "1.0"
description: >
  This skill should be used when the user says "/os:todo", "ajouter un todo",
  "todo list", "todo done", "todo edit", "todo delete", "add a task",
  "new task", "mes todos", "quelles tâches", "mark as done", "tâche terminée",
  "j'ai fini", "supprimer un todo", or wants to manage actionable todos
  stored in the Obsidian vault.
---

# Skill `/os:todo`

Tu es un assistant qui gère des todos actionables centralisés dans le vault Obsidian (`~/vault/OS/todos/`). Tu interprètes les demandes en langage naturel pour déduire l'action et les champs.

## Données de référence

- **Chemin** : `~/vault/OS/todos/` (hardcodé, accessible depuis n'importe quel projet)
- **Priorités** : `urgente`, `haute`, `normale`, `basse`
- **Statuts** : `open` (dans `todos/`), `done` (dans `todos/done/`)
- **Date du jour** : utiliser `date +%Y-%m-%d` pour obtenir la date courante
- **ID** : entier auto-incrémenté, unique et immutable. Pour déterminer le prochain ID, scanner tous les fichiers `{id}-*.md` dans `todos/` et `todos/done/`, extraire le max, et incrémenter de 1.

---

## Phase 1 : Parser les arguments

À partir de `$ARGUMENTS`, détermine la sous-commande en langage naturel :

| Intention détectée | Sous-commande |
|-------------------|---------------|
| ajouter, créer, nouveau, un texte décrivant une tâche | `add` |
| lister, voir, quels todos, afficher | `list` |
| fait, terminé, done, fermer, résolu | `done` |
| modifier, changer, mettre à jour, éditer | `edit` |
| supprimer, enlever, retirer, delete | `delete` |

Si l'intention n'est pas claire, demander à l'utilisateur via `AskUserQuestion`.

---

## Phase 2A : Mode `add`

### Étape 1 : Extraire les champs

Depuis le langage naturel, déduire :

- **Titre** (obligatoire) : la tâche à faire
- **Projet** (optionnel) : tag libre déduit du contexte (ex: "rchiv", "vault", "evaneos")
- **Priorité** (défaut: `normale`) : déduite des mots-clés (urgent, important, mineur, etc.)
- **Deadline** (optionnel) : date déduite si mentionnée ("pour vendredi", "avant le 15 mars")
- **Description** (optionnel) : contexte supplémentaire

Si le titre n'est pas clair, poser une question via `AskUserQuestion`.

### Étape 2 : Générer le slug et l'ID

- **Slug** : basé sur le titre, en kebab-case, lowercase, sans accents
  - Exemple : "Fix le JSON output de rchiv init" → `fix-json-output-rchiv-init`
  - Le slug est **immutable** après création
- **ID** : scanner `~/vault/OS/todos/{id}-*.md` et `~/vault/OS/todos/done/{id}-*.md`, extraire le max, incrémenter de 1
- **Nom de fichier** : `{id}-{slug}.md` (ex: `10-fix-json-output-rchiv-init.md`)

### Étape 3 : Écrire le fichier

```bash
mkdir -p ~/vault/OS/todos/done
```

Écrire `~/vault/OS/todos/{id}-{slug}.md` :

```markdown
# {Titre}

- **ID** : {id}
- **Projet** : {tag}
- **Priorité** : {urgente|haute|normale|basse}
- **Deadline** : {YYYY-MM-DD}
- **Créé** : {YYYY-MM-DD}

---

{Description, contexte, pistes...}
```

Omettre les champs optionnels non renseignés (pas de ligne vide "Projet : " ni "Deadline : "). L'ID est **toujours** présent.

### Étape 4 : Mettre à jour l'index

Régénérer `~/vault/OS/todos/index.md` (voir Phase 2B).

### Étape 5 : Commit

Commiter via le skill `git:commit` sur le repo vault (`~/vault/OS`).

---

## Phase 2B : Mode `list`

### Étape 1 : Déterminer les filtres

Depuis le langage naturel, déduire les filtres éventuels :

- **Par projet** : "les todos rchiv" → filtre sur projet=rchiv
- **Par priorité** : "les urgents" → filtre sur priorité=urgente
- **Par statut** : "les done", "les terminés" → scanner `todos/done/` au lieu de `todos/`
- **Tout** : "tous les todos" → open + done
- **Sans filtre** : affiche tous les open

### Étape 2 : Scanner les fichiers

1. Scanner `~/vault/OS/todos/*.md` (exclure `index.md`)
2. Si filtre `done` ou `tout`, scanner aussi `~/vault/OS/todos/done/*.md`
3. Pour chaque fichier :
   - Extraire l'ID depuis le nom de fichier (`{id}-{slug}.md` → `{id}`)
   - Extraire le titre (H1)
   - Extraire projet, priorité, deadline, date de création depuis les métadonnées
4. Appliquer les filtres

### Étape 3 : Régénérer l'index

Écrire/réécrire `~/vault/OS/todos/index.md` avec les todos **open** uniquement :

```markdown
# Todos

| ID | Todo | Projet | Priorité | Deadline | Créé |
|----|------|--------|----------|----------|------|
| {id} | [[{id}-{slug}]] {titre} | {projet} | {priorité} | {YYYY-MM-DD} | {YYYY-MM-DD} |
```

Tri : par priorité (urgente > haute > normale > basse), puis par date de création (plus ancien en premier).

Colonnes optionnelles vides : laisser un `-` si pas de projet ou deadline.

### Étape 4 : Afficher le résultat

Afficher dans le terminal un résumé des todos correspondant aux filtres.

---

## Phase 2C : Mode `done`

### Étape 1 : Identifier le todo

Depuis le langage naturel ou l'ID, identifier quel todo marquer comme fait. L'utilisateur peut référencer un todo par son ID (ex: `done 3`), son slug, ou une description en langage naturel. Si ambiguïté, lister les todos open et demander via `AskUserQuestion`.

### Étape 2 : Déplacer le fichier

```bash
mv ~/vault/OS/todos/{id}-{slug}.md ~/vault/OS/todos/done/{id}-{slug}.md
```

### Étape 3 : Régénérer l'index

Relancer la génération de `~/vault/OS/todos/index.md` (Phase 2B étape 3).

### Étape 4 : Commit

Commiter via le skill `git:commit` sur le repo vault.

---

## Phase 2D : Mode `edit`

### Étape 1 : Identifier le todo

Depuis le langage naturel ou l'ID, identifier quel todo modifier et quoi changer. L'utilisateur peut référencer un todo par son ID (ex: `edit 3 priorité haute`). Si ambiguïté, demander via `AskUserQuestion`.

### Étape 2 : Modifier le fichier

Modifier le fichier `~/vault/OS/todos/{id}-{slug}.md` selon la demande (priorité, deadline, description, projet).

### Étape 3 : Régénérer l'index

Relancer la génération de `~/vault/OS/todos/index.md` (Phase 2B étape 3).

### Étape 4 : Commit

Commiter via le skill `git:commit` sur le repo vault.

---

## Phase 2E : Mode `delete`

### Étape 1 : Identifier le todo

Depuis le langage naturel ou l'ID, identifier quel todo supprimer. L'utilisateur peut référencer un todo par son ID (ex: `delete 3`). **Toujours confirmer** via `AskUserQuestion` avant de supprimer.

### Étape 2 : Supprimer le fichier

```bash
rm ~/vault/OS/todos/{id}-{slug}.md
```

### Étape 3 : Régénérer l'index

Relancer la génération de `~/vault/OS/todos/index.md` (Phase 2B étape 3).

### Étape 4 : Commit

Commiter via le skill `git:commit` sur le repo vault.

---

## Phase 3 : Résumé

Après chaque sous-commande, afficher un résumé concis :

- **add** : chemin du fichier créé, titre, projet, priorité
- **list** : nombre de todos affichés, filtres appliqués
- **done** : todo marqué comme fait, chemin de destination
- **edit** : champs modifiés, chemin du fichier
- **delete** : todo supprimé

---

## Règles importantes

1. **Langage naturel** : toujours déduire l'action et les champs depuis l'input utilisateur, ne jamais demander de syntaxe structurée
2. **Langue** : français (même convention que les recaps et idées)
3. **Slug immutable** : le slug ne change pas après création
4. **Pas de frontmatter** : pas de YAML frontmatter (cohérent avec le reste du vault)
5. **Idempotence** : `list` régénère l'index complet à chaque fois
6. **Auto-commit** : chaque opération (add/done/edit/delete) est suivie d'un commit via le skill `git:commit` sur le repo `~/vault/OS`. Si `git:commit` n'est pas disponible, exécuter directement : `cd ~/vault/OS && git add -A && git commit -m "{message descriptif}"`
7. **Créer les dossiers** : `mkdir -p ~/vault/OS/todos/done` à la première utilisation
8. **Wikilinks** : utiliser `[[slug]]` dans l'index pour navigation Obsidian
9. **Champs optionnels** : ne pas afficher les lignes de métadonnées pour les champs non renseignés
10. **Confirmation** : toujours confirmer avant un `delete`
