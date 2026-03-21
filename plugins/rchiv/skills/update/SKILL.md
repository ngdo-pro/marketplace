---
name: rchiv:update
version: "1.2.0"
description: >
  Update an existing .rchiv.json with code changes since the last scan. This skill should
  be used when the user says "/rchiv:update", "update rchiv", "resync boundaries",
  "refresh boundary map", or wants to update the boundary map after code changes.
argument-hint: "[--dry-run]"
disable-model-invocation: true
allowed-tools: Task, Bash(git:*), Bash(gh api:*), Bash(base64 -d:*), Bash(grepai search:*), Bash(grepai trace:*), Bash(grepai status:*), Read, Write, Edit, Glob, Grep, AskUserQuestion
---

# Mise à jour incrémentielle du `.rchiv.json`

Tu mets à jour un fichier `.rchiv.json` existant avec les changements de code survenus depuis le dernier scan.

Toutes les interactions sont en français. La version du format est déterminée dynamiquement depuis le schéma du registry.

Le format est décrit dans `references/format.md`. La logique de résolution registry est dans `references/registry-resolution.md`. L'analyse des dépendances suit `references/dependency-analysis.md`. La découverte de handlers suit `references/handler-discovery.md`.

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

## Pré-requis

Vérifier qu'un fichier `.rchiv.json` existe à la racine. S'il n'existe pas, proposer `/rchiv:config` puis `/rchiv:init`.

Parser le schéma du registry injecté dans le contexte dynamique pour extraire la version cible. Si le résultat contient `REGISTRY_UNAVAILABLE`, fetcher le schéma manuellement :
1. Lire le registry depuis `.rchiv-config.json` : `jq -r '.registry' .rchiv-config.json`
2. `gh api repos/{repo}/contents/schemas --jq '[.[].name] | sort | last'` pour la dernière version
3. `gh api repos/{repo}/contents/schemas/{latest} --jq '.content' | base64 -d` pour le fetcher

Si la version du `.rchiv.json` est inférieure à la version du schéma, proposer `/rchiv:upgrade` à la place.

## Étape 2 : Charger l'état et identifier les fichiers modifiés

1. Lire le `.rchiv.json` existant
2. Récupérer le commit de `_meta.commit`
3. Exécuter `git diff --name-only {commit}..HEAD` pour lister les fichiers modifiés

## Étape 3 : Identifier les handlers impactés

Parmi les fichiers modifiés, identifier ceux qui contiennent des handlers existants ou de nouveaux entry points.

Toujours combiner grepai (si disponible) et Explore pour maximiser la couverture. Lancer les 2 en parallèle, puis consolider.

### 3.1 grepai (si `grepai_available`)

**Tracer les handlers impactés** — pour chaque fichier modifié, identifier les handlers qui en dépendent :

1. Extraire les symboles (classes/fonctions) modifiés via le diff
2. `grepai trace callers "{ModifiedSymbol}" --mode precise --toon`
3. Croiser les callers trouvés avec les handlers du `.rchiv.json` existant

**Ré-analyser les dépendances** — pour les fichiers qui sont eux-mêmes des handlers (controllers, consumers), appliquer le call graph itératif de `references/dependency-analysis.md` :

1. `grepai trace graph "{HandlerClass}::{method}" --depth 5 --mode precise --toon`
2. Si des noeuds terminaux ne sont pas des boundaries → relancer avec `--depth 10`
3. Répéter en incrémentant de 5 (`--depth 15`, `--depth 20`, etc.) jusqu'à atteindre les boundaries
4. Comparer avec les dépendances actuelles dans le `.rchiv.json`
5. Identifier les ajouts/suppressions de dépendances

**Détecter les nouveaux handlers** — pour les fichiers qui pourraient contenir de nouveaux handlers, appliquer `references/handler-discovery.md` :

1. `grepai search "route controller endpoint consumer command" --path {fichier_modifié} --toon`
2. Vérifier si un nouveau handler a été ajouté dans ce fichier

### 3.2 Explore (toujours)

Lancer en parallèle un agent d'exploration (Task tool, subagent_type=Explore) :

```
Voici les fichiers modifiés depuis le dernier scan :
{liste des fichiers}

Et voici les handlers actuellement dans le .rchiv.json :
{liste des handlers avec leurs fichiers sources}

Pour chaque fichier modifié :
1. Est-ce qu'il contient un handler existant ? Si oui, réanalyse ses dépendances.
   Cherche :
   - calls: appels HTTP sortants vers d'autres services (méthode, path, service cible)
   - reads: tables et colonnes lues — identifier la database. Colonnes obligatoires.
   - writes: tables et colonnes écrites — identifier la database. Colonnes obligatoires.
   - emits: messages publiés — identifier l'exchange, la routing_key et le broker
   - bindings: pour les handlers event, chercher dans les configs de broker
   - dead_letter: pour les handlers event, chercher la config de dead letter exchange
2. Est-ce qu'il introduit un nouveau handler ? Si oui, décris-le avec ses dépendances.
3. Est-ce qu'un handler existant a été supprimé ? Si oui, signale-le.
```

### 3.3 Consolidation

Fusionner les résultats grepai + Explore, dédupliquer, et signaler les dépendances trouvées par une seule source.

Appliquer les règles de boundaries de `references/dependency-analysis.md` :
- **Boundaries capturées** (calls, reads, writes, emits) → intégrer dans le `.rchiv.json`
- **Boundaries non capturées** (filesystem, cache, email, search, SDKs externes) → afficher un warning par occurrence et lister dans les zones d'incertitude du rapport

## Étape 4 : Mettre à jour le .rchiv.json

- Ajouter les nouveaux handlers
- Mettre à jour les handlers modifiés (dépendances, description, bindings)
- Supprimer les handlers qui n'existent plus
- Mettre à jour `_meta.commit` et `_meta.generated_at`
- Mettre à jour `semantic` si les changements le justifient
- Mettre à jour `connections`, `brokers`, `databases` si des services/brokers/DBs ont été ajoutés/retirés
- **Registry resolution** : fetcher `services.json` depuis le registry et résoudre les `connections` (voir `references/registry-resolution.md`). `registry_name` est toujours présent quand le service a été trouvé dans le registry.
- **Re-résolution des services non résolus** : vérifier les `connections` existantes qui n'ont pas de `registry_name`. Pour chaque service non résolu trouvé dans le registry depuis le dernier scan, mettre à jour `connections[service].registry_name` et les `calls[].registry_name` correspondants. Afficher les résolutions trouvées dans le rapport.

**Mode `--dry-run` :** au lieu d'écrire le fichier, afficher un diff textuel des changements qui seraient appliqués. Ne pas exécuter la validation en mode dry-run.

## Étape 5 : Validation finale (boucle)

Après l'écriture du `.rchiv.json`, présenter un résumé des changements au dev et demander validation via `AskUserQuestion` :

```
Le .rchiv.json a été mis à jour. Voici les changements :

- Handlers ajoutés : {N} ({liste})
- Handlers modifiés : {N} ({liste})
- Handlers supprimés : {N} ({liste})

Est-ce que le résultat est correct ? (oui / non — si non, décris ce qu'il faut corriger)
```

**Boucle de correction :**
1. Si le dev valide → passer au rapport
2. Si le dev signale des erreurs :
   a. Re-explorer si nécessaire (Task/Explore ou grepai)
   b. Appliquer les corrections
   c. Redemander validation
   d. **Boucler** tant que non validé

**Important** : ne jamais passer au rapport sans validation explicite du dev. Exception : mode `--dry-run`.

## Étape 6 : Rapport

```
.rchiv.json mis à jour !

Depuis le commit {old_commit} :
- Handlers ajoutés : {N} ({liste})
- Handlers modifiés : {N} ({liste})
- Handlers supprimés : {N} ({liste})
- Dépendances : {N} calls, {N} reads, {N} writes, {N} emits
- Couverture colonnes : {n}/{m} reads/writes avec colonnes explicites ({p}%) — si < 100%, les `["*"]` sont listés dans les zones d'incertitude
- Fichiers analysés : {N} (sur {M} fichiers modifiés au total)
- Connexions : {liste des services} (registry: {N} résolus / {M} total)
- Services re-résolus : {N} ({liste}, ou "aucun")
- Zones d'incertitude : {liste si applicable — ex: "colonnes de la table X pas certaines", "boundary non capturée : Filesystem (Flysystem) dans handler Y"}
```

---

## Règles importantes

1. **Langue** : Le `.rchiv.json` est en **français** (descriptions, purpose, summary) avec identifiants techniques en anglais. Voir `references/generation-rules.md` section "Langue et qualité des descriptions". Les interactions avec le dev sont en français.
2. **Pas de secrets** : Ne jamais inclure de credentials dans le `.rchiv.json`.
3. **JSON valide** : 2 espaces d'indentation.
4. **Références cohérentes** : les références broker/database/service doivent correspondre aux clés déclarées au niveau racine.
5. **Registry** : Utiliser systématiquement le registry pour la résolution cross-service. `registry_name` est toujours présent quand le service a été trouvé dans le registry.
6. **grepai** : Quand grepai est disponible, privilégier `--toon` pour les sorties LLM. Utiliser `--mode precise` pour le traçage critique. Si grepai retourne des résultats incomplets, basculer sur Grep/Glob/Read.
7. **Colonnes obligatoires** : Chaque `reads` et `writes` DOIT avoir un champ `columns`. Tracer les colonnes dans le code source. Si indéterminables → `"columns": ["*"]` et signaler en zone d'incertitude.

## Registry sync

Appliquer `references/registry-sync.md`.

---

## Done

Quand le registry sync est terminé (ou refusé), **STOP**.
