---
name: rchiv:config
version: "1.2.0"
description: >
  Set up a project for rchiv: check prerequisites, configure the registry, and write
  .rchiv-config.json. This skill should be used when the user says "/rchiv:config", "config rchiv",
  "setup rchiv", "configure rchiv", or wants to prepare a project for boundary mapping.
disable-model-invocation: true
allowed-tools: Bash(grepai status:*), Bash(gh api:*), Bash(base64 -d:*), Read, Write, Glob, AskUserQuestion
---

# Setup rchiv pour un projet

Tu configures un projet pour la génération d'un `.rchiv.json`. Ce skill vérifie les prérequis, configure le registry, et écrit `.rchiv-config.json`.

Toutes les interactions sont en français.

---

## Contexte dynamique

- grepai status: !`grepai status --no-ui 2>&1 || echo "GREPAI_UNAVAILABLE"`

## Étape 1 : Détection grepai

Interpréter le résultat de `grepai status` dans le contexte dynamique ci-dessus :
- Si le résultat contient des fichiers indexés → informer le dev que grepai est disponible (accélérera la génération)
- Si le résultat contient `GREPAI_UNAVAILABLE` ou une erreur → informer le dev que grepai n'est pas disponible (fallback Grep/Glob/Read sera utilisé)

## Étape 2 : Pré-requis

Vérifier qu'un fichier `.rchiv.json` n'existe PAS déjà à la racine du projet. S'il existe, proposer `/rchiv:update` → **STOP**

## Étape 3 : Registry

Demander au dev via `AskUserQuestion` :

> "Quel registry utiliser pour ce projet ?"
>
> 1. `Evaneos/rchiv-registry`
> 2. Autre (précise le repo GitHub, ex: `org/rchiv-registry`)

Ne PAS tenter de trouver ou deviner le registry. Attendre la réponse du dev.

## Étape 4 : Écriture de `.rchiv-config.json`

Écrire le fichier `.rchiv-config.json` à la racine du projet :

```json
{
  "registry": "{repo choisi par le dev}"
}
```

## Étape 5 : Routing

Afficher au dev :

```
Setup terminé ! `.rchiv-config.json` créé avec le registry `{repo}`.

Pour générer le `.rchiv.json`, lance :
- `/rchiv:init` — génération en 3 phases (config → entrypoints → dépendances)
```

→ **STOP**. Ne rien faire d'autre.
