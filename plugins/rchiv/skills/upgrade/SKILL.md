---
name: rchiv:upgrade
version: "1.1.0"
description: >
  Migrate a .rchiv.json to the latest format version (v0.5.0). This skill should be used
  when the user says "/rchiv:upgrade", "upgrade rchiv", "migrate rchiv",
  "update rchiv format", or wants to update an older .rchiv.json to the current format.
argument-hint: "[--dry-run]"
disable-model-invocation: true
allowed-tools: Task, Bash(git:*), Bash(gh api:*), Bash(base64 -d:*), Bash(grepai search:*), Bash(grepai trace:*), Bash(grepai status:*), Read, Write, Edit, Glob, Grep, AskUserQuestion
---

# Migration vers la dernière version

Tu migres un fichier `.rchiv.json` (ou `.rchiv` pour les anciennes versions) vers le format v0.5.0.

Toutes les interactions sont en français. Le format complet est dans `references/format.md`. La logique de résolution registry est dans `references/registry-resolution.md`.

---

## Étape 0 : Détection grepai

1. Exécuter `grepai status --no-ui` dans le répertoire du projet
2. **Si la commande réussit** (exit code 0) et affiche des fichiers indexés → `grepai_available = true`
3. **Si la commande échoue** → `grepai_available = false`

---

## Pré-requis

Vérifier qu'un fichier `.rchiv.json` (ou `.rchiv` pour les anciennes versions) existe à la racine. S'il n'existe pas, proposer `/rchiv:config`.
Vérifier que la version est < 0.5.0. Si déjà en 0.5.0, indiquer qu'aucun upgrade n'est nécessaire.

## Étape 1 : Détecter la version et appliquer les migrations

Lire le `.rchiv.json` (ou `.rchiv`) existant et identifier la version via le champ `version`.

Les migrations sont appliquées **séquentiellement** de la version courante jusqu'à la dernière :

### Migrations v0.1.0 → v0.2.0

| Champ v0.1.0 | Transformation v0.2.0 |
|--------------|----------------------|
| `$schema: "rchiv/v0.1.0"` | → `"rchiv/v0.2.0"` |
| `version: "0.1.0"` | → `"0.2.0"` |
| Handler `event` | → renommer en `queue` |
| Handler `channel` | → supprimer (remplacé par `broker` dans bindings) |
| Handler `tags: ["not-deployed"]` | → `status: "not-deployed"`, supprimer le tag de `tags` |
| `emits[].event` | → `emits[].routing_key` |
| `emits[].channel` | → supprimer (remplacé par `broker`) |
| `dependencies.listens` | → supprimer (remplacé par `bindings` sur le handler) |
| *(nouveau)* | Initialiser `brokers: {}`, `databases: {}` |

### Migrations v0.2.0 → v0.3.0

| Champ v0.2.0 | Transformation v0.3.0 |
|--------------|----------------------|
| `$schema: "rchiv/v0.2.0"` | → `"rchiv/v0.3.0"` |
| `version: "0.2.0"` | → `"0.3.0"` |
| `connections[].registry_name` | *(nouveau)* — à résoudre contre le registry |
| `calls[].registry_name` | *(nouveau)* — à résoudre contre le registry |
| `registry` | *(nouveau)* — à configurer si le dev le souhaite |

### Migrations v0.3.0 → v0.4.0

| Champ v0.3.0 | Transformation v0.4.0 |
|--------------|----------------------|
| `$schema: "rchiv/v0.3.0"` | → `"https://raw.githubusercontent.com/{registry.repo}/main/schemas/v0.4.0.json"` (construit depuis `registry.repo` ; si pas de registry → omettre `$schema`) |
| `version: "0.3.0"` | → `"0.4.0"` |
| *(nouveau)* | Ajouter `libraries: []` — détecter les packages internes depuis composer.json/package.json |

### Migrations v0.4.0 → v0.5.0

| Champ v0.4.0 | Transformation v0.5.0 |
|--------------|----------------------|
| `$schema: ".../v0.4.0.json"` | → `.../v0.5.0.json` |
| `version: "0.4.0"` | → `"0.5.0"` |
| Fichier `.rchiv` | → Renommer en `.rchiv.json` (`mv .rchiv .rchiv.json`) |
| Registry paths `{org}/{repo}/.rchiv` | → `{org}/{repo}/.rchiv.json` |

## Étape 2 : Re-explorer pour les champs manquants

### Si migration depuis v0.1.0 (champs v0.2.0 à remplir)

Utiliser le **Task tool avec subagent_type=Explore** pour remplir les champs qui n'existaient pas en v0.1.0 :

```
Explore le projet pour trouver les configurations suivantes :

1. BROKERS : chercher les connexions message broker distinctes
   - Fichiers : swarrot.yaml, messenger.yaml, rabbitmq-definition-*.yaml, .env (*RABBITMQ*, *AMQP*, *KAFKA*, *SQS*)
   - Pour chaque broker : nom logique, type (rabbitmq/kafka/sqs), description

2. DATABASES : chercher les connexions base de données distinctes
   - Fichiers : doctrine.yaml, database config, .env (*DATABASE_URL*)
   - Pour chaque database : nom logique, type (postgresql/mysql/mongodb), description

3. BINDINGS : pour chaque handler event (queue), chercher les bindings
   - Fichiers : rabbitmq-definition-*.yaml, swarrot.yaml, config broker
   - Pour chaque queue : exchange source, routing_key, broker associé

4. DEAD LETTER : pour chaque handler event, chercher la config DLX
   - Fichiers : rabbitmq-definition-*.yaml, config broker
   - Pour chaque queue : exchange DLX, queue DLQ

5. EMITS broker : pour chaque emit, identifier le broker et l'exchange
   - Résoudre les noms de broker pour chaque message publié

6. READS/WRITES database : pour chaque read/write, identifier la database
   - Résoudre quelle connexion DB est utilisée dans chaque repository
```

### Si migration depuis v0.3.0 ou v0.4.0 (champs v0.4.0+ à remplir)

Utiliser le **Task tool avec subagent_type=Explore** pour détecter les libraries internes :

```
Explore le projet pour trouver les libraries internes (packages maintenus par l'organisation) :

1. Lire composer.json (PHP) : chercher les dépendances dont le vendor est l'organisation (ex: evaneos/*)
2. Lire package.json (JS/TS) : chercher les dépendances avec le scope de l'organisation (ex: @evaneos/*)
3. Pour chaque library trouvée : extraire le nom du package et la contrainte de version
4. Ignorer les packages publics/communautaires (symfony/*, laravel/*, react, etc.)
```

### Résolution registry (toute migration vers v0.5.0, si pas déjà configuré)

Demander au dev via `AskUserQuestion` :

> "Quel est le repo GitHub du registry central ? (ex: `Evaneos/rchiv-registry`)"

Si le dev fournit un repo, appliquer la logique de `references/registry-resolution.md`.

## Étape 3 : Assembler et valider

1. Appliquer toutes les transformations mécaniques
2. Remplir les sections découvertes par exploration (brokers, databases, bindings, etc.)
3. Ajouter la section `registry` si configurée
4. Ajouter les `registry_name` résolus
5. Présenter un diff au dev pour validation via `AskUserQuestion`

**Mode `--dry-run` :** afficher le diff sans écrire le fichier.

## Étape 4 : Écrire et rapport

Écrire le `.rchiv.json` v0.5.0 à la racine du projet.

```
.rchiv.json migré de v{old} vers v0.5.0 !

Changements :
- Brokers ajoutés : {N} ({liste})
- Databases ajoutées : {N} ({liste})
- Libraries détectées : {N} ({liste})
- Bindings ajoutés : {N} (sur {M} handlers event)
- Dead letter configurés : {N}
- Emits enrichis : {N} (exchange + routing_key + broker)
- Reads/writes enrichis : {N} (champ database ajouté)
- Handlers avec status mis à jour : {N}
- $schema : URL GitHub raw
- Registry : {repo ou "non configuré"}
- Connexions résolues : {N} / {M}
```

---

## Règles importantes

1. **Langue** : Le `.rchiv.json` est en **français** (descriptions, purpose, summary) avec identifiants techniques en anglais. Voir `references/generation-rules.md` section "Langue et qualité des descriptions". Les interactions avec le dev sont en français.
2. **Pas de secrets** : Ne jamais inclure de credentials dans le `.rchiv.json`.
3. **JSON valide** : 2 espaces d'indentation.
4. **Références cohérentes** : broker/database/service references must match top-level keys.
5. **grepai** : Privilégier `--toon`, `--mode precise` pour le traçage. Fallback sur Grep/Glob si résultats incomplets.

## Registry sync

Appliquer `references/registry-sync.md`.

---

## Done

Quand le registry sync est terminé (ou refusé), **STOP**.
