---
name: rchiv:fix
version: "1.1.0"
description: >
  Fix issues in an existing .rchiv.json interactively. This skill should be used when
  the user says "/rchiv:fix", "fix rchiv", "correct boundaries", "fix boundary map",
  or wants to correct errors in the boundary map.
argument-hint: '"description du problème"'
disable-model-invocation: true
allowed-tools: Task, Bash(git:*), Bash(gh api:*), Bash(base64 -d:*), Bash(grepai search:*), Bash(grepai trace:*), Bash(grepai status:*), Read, Write, Edit, Glob, Grep, AskUserQuestion
---

# Corriger le `.rchiv.json`

Tu corriges un fichier `.rchiv.json` existant en mode interactif.

Toutes les interactions sont en français.

---

## Étape 0 : Détection grepai

1. Exécuter `grepai status --no-ui` dans le répertoire du projet
2. **Si la commande réussit** (exit code 0) et affiche des fichiers indexés → `grepai_available = true`
3. **Si la commande échoue** → `grepai_available = false`

---

## Pré-requis

Vérifier qu'un fichier `.rchiv.json` existe à la racine. S'il n'existe pas, proposer `/rchiv:config`.

## Étape 1 : Charger et comprendre le problème

1. Lire le `.rchiv.json` existant
2. **Si une description du problème est fournie dans `$ARGUMENTS`** : l'utiliser comme point de départ
3. **Si aucune description n'est fournie** : demander au dev via `AskUserQuestion` :

> "Qu'est-ce qui ne va pas dans le .rchiv.json ? Décris le ou les problèmes à corriger."

## Étape 2 : Analyser et corriger

Pour chaque problème signalé :

1. **Correction simple** (description, status, nom, typo, champ manquant/en trop) : corriger directement dans le `.rchiv.json`
2. **Correction nécessitant une re-exploration** (handler manquant, dépendances incorrectes, bindings faux, etc.) :

   **Si `grepai_available` :**
   - Handler manquant : `grepai search "{description du handler attendu}" --toon` pour le localiser
   - Dépendances incorrectes : `grepai trace graph "{HandlerClass}::{method}" --depth 5 --mode precise --toon` pour re-tracer
   - Bindings faux : `grepai search "binding configuration for {queue_name}" --toon` pour trouver la config

   **Si `grepai_available` est faux :**
   Utiliser le **Task tool avec subagent_type=Explore** pour re-analyser le code source concerné.

   Puis appliquer les corrections.

Appliquer les corrections au `.rchiv.json` via l'outil Edit ou Write. Mettre à jour `_meta.generated_at` après correction.

## Étape 3 : Validation (boucle)

Présenter les corrections effectuées au dev et demander validation via `AskUserQuestion` :

```
J'ai effectué les corrections suivantes :

{liste des corrections avec avant/après}

Est-ce que c'est bon maintenant ? (oui / non — si non, décris ce qu'il faut encore corriger)
```

**Boucle de correction :**
1. Si le dev valide → passer au rapport
2. Si le dev signale d'autres problèmes :
   a. Retour à l'Étape 2 avec les nouveaux problèmes
   b. Appliquer les corrections
   c. Redemander validation
   d. **Boucler** tant que non validé

## Étape 4 : Rapport

```
.rchiv.json corrigé !

Corrections appliquées :
- {liste des corrections}
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
