---
name: rchiv:help
version: "1.2.0"
description: >
  Display available rchiv commands and usage. This skill should be used when the user
  says "/rchiv:help", "/rchiv", "rchiv help", "rchiv commands", or asks what rchiv can do.
disable-model-invocation: true
---

# rchiv — Architecture boundary mapper

Afficher le message suivant, puis **STOP** :

```
## rchiv — Architecture boundary mapper

Génère et maintient un fichier `.rchiv.json` décrivant les boundaries d'un service :
handlers (HTTP, events, cron, CLI…) et leurs dépendances.

### Skills disponibles

| Commande | Description |
|----------|-------------|
| `/rchiv:help` | Affiche cette aide |
| `/rchiv:config` | Configure le projet (registry, prérequis) → écrit `.rchiv-config.json` |
| `/rchiv:auto` | Génération one-shot automatique (valide uniquement à la fin) |
| `/rchiv:init` | Génération en 3 phases interactives (config → entrypoints → dépendances) |
| `/rchiv:update` | Met à jour le `.rchiv.json` avec les changements depuis le dernier scan |
| `/rchiv:update --dry-run` | Prévisualise les changements sans écrire |
| `/rchiv:fix` | Corrige le `.rchiv.json` existant (interactif) |
| `/rchiv:fix "desc"` | Corrige un problème spécifique dans le `.rchiv.json` |
| `/rchiv:upgrade` | Migre un `.rchiv.json` vers la dernière version (v0.5.0) |
| `/rchiv:upgrade --dry-run` | Prévisualise la migration sans écrire |
| `/rchiv:explain "question"` | Interroge l'architecture à partir du `.rchiv.json` |
| `/rchiv:explain --html "question"` | Génère une page HTML standalone avec la réponse |
| `/rchiv:explain --html` | Génère un dashboard HTML complet du service |


### Workflow

1. `/rchiv:config` — Configurer le projet (une seule fois)
2. `/rchiv:auto` — Générer le `.rchiv.json` (rapide, valide à la fin)
3. `/rchiv:update` — Resynchroniser après des modifications de code

### Exemples

- `/rchiv:config` — Première utilisation, configurer le registry
- `/rchiv:auto` — Générer le `.rchiv.json` en one-shot
- `/rchiv:init` — Générer le `.rchiv.json` phase par phase (gros services)
- `/rchiv:update` — Après des modifications de code, resynchroniser
- `/rchiv:fix` — Corriger des erreurs dans le `.rchiv.json`
- `/rchiv:fix "la description du handler X est fausse"` — Corriger un problème spécifique
- `/rchiv:explain "quels endpoints sont des passe-plats ?"` — Analyser l'architecture
- `/rchiv:explain "quel est l'impact de modifier la table users ?"` — Analyse d'impact
- `/rchiv:explain --html "quels endpoints sont des passe-plats ?"` — Réponse en page HTML dark theme
- `/rchiv:explain --html` — Dashboard HTML complet du service dans le navigateur
```

Ne rien faire d'autre. Ne pas lancer d'analyse, ne pas poser de question.
