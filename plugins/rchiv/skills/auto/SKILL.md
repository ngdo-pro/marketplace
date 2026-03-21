---
name: rchiv:auto
version: "1.0.0"
description: >
  Generate a .rchiv.json in one shot without per-batch validation.
  This skill should be used when the user says "/rchiv:auto", "rchiv auto",
  "rchiv oneshot", "rchiv quick", "génère le rchiv en auto", or wants to generate
  a .rchiv.json quickly without validating each batch of handlers.
disable-model-invocation: true
allowed-tools: Task, Bash(git:*), Bash(gh api:*), Bash(base64 -d:*), Bash(grepai search:*), Bash(grepai trace:*), Bash(grepai status:*), Read, Write, Edit, Glob, Grep, AskUserQuestion
---

# Génération one-shot d'un `.rchiv.json`

Mode automatique : découverte du framework, listing des entrypoints, analyse des dépendances, écriture du `.rchiv.json` — **sans validation batch par batch**. Le dev ne valide qu'à la fin.

Toutes les interactions sont en français. La version du format est déterminée dynamiquement depuis le schéma du registry.

Le format est décrit dans `references/format.md`. La logique de résolution registry est dans `references/registry-resolution.md`. Les règles de génération sont dans `references/generation-rules.md`.

---

## Contexte dynamique

- grepai status: !`grepai status --no-ui 2>&1 || echo "GREPAI_UNAVAILABLE"`
- Registry schema: !`registry=$(jq -r '.registry' .rchiv-config.json 2>/dev/null || echo 'Evaneos/rchiv-registry') && latest=$(gh api "repos/$registry/contents/schemas" --jq '[.[].name] | sort | last') && gh api "repos/$registry/contents/schemas/$latest" --jq '.content' | base64 -d 2>&1 || echo "REGISTRY_UNAVAILABLE"`

## Étape 0 : Gates

### Config obligatoire

Vérifier que `.rchiv-config.json` existe à la racine du projet.

**S'il n'existe pas** → afficher :
> "Lance `/rchiv:config` d'abord pour configurer le projet."

→ **STOP**. Ne rien faire d'autre.

### Fichier existant

Vérifier qu'un fichier `.rchiv.json` n'existe PAS déjà. S'il existe, proposer `/rchiv:update` ou demander au dev s'il veut écraser.

### Détection grepai

Interpréter le résultat de `grepai status` dans le contexte dynamique ci-dessus :
- Si le résultat contient des fichiers indexés → `grepai_available = true`
- Si le résultat contient `GREPAI_UNAVAILABLE` ou une erreur → `grepai_available = false` (utiliser Grep/Glob/Read + agents Explore)

---

## Étape 1 : Schema + framework + config

### 1.1 Schema parsing

Parser le schéma du registry injecté dans le contexte dynamique pour extraire : version cible, champs requis/optionnels, types de handlers supportés, structure des dépendances, URL `$schema`. **Le schéma du registry prime sur la spec hardcodée.**

Si le résultat contient `REGISTRY_UNAVAILABLE`, fetcher le schéma manuellement :
1. Lire le registry depuis `.rchiv-config.json` : `jq -r '.registry' .rchiv-config.json`
2. `gh api repos/{repo}/contents/schemas --jq '[.[].name] | sort | last'` pour la dernière version
3. `gh api repos/{repo}/contents/schemas/{latest} --jq '.content' | base64 -d` pour le fetcher

### 1.2 Détection framework/config

Toujours en **mode discovery** — pas de question au dev. Appliquer `references/framework-detection.md` pour :
- Détecter le framework (Pyrite, Symfony, Silex, Zend)
- Détecter les brokers, databases, libraries internes
- Identifier les fichiers de config pertinents

NE PAS demander validation au dev à ce stade — stocker les résultats et continuer.

---

## Étape 2 : Découverte des entrypoints

Appliquer la procédure décrite dans `references/handler-discovery.md` (grepai search si disponible + Task/Explore).

Stocker les entrypoints en mémoire (pas de fichier `.rchiv-entrypoints.json`). Pour chaque entrypoint, conserver :
- Type, identifiant (method+path, queue, schedule, command)
- Description courte
- Fichier source et ligne

NE PAS demander validation au dev — continuer directement.

---

## Étape 3 : Analyse des dépendances (sans validation intermédiaire)

### 3.1 Grouper en batches

Grouper les entrypoints en batches de **5 à 10** (plus gros que le mode init car pas de validation intermédiaire), par type puis par proximité de fichier source.

### 3.2 Traiter tous les batches

Pour chaque batch, lancer l'analyse en parallèle via Task/Explore et/ou grepai. Appliquer `references/dependency-analysis.md` :

```
Pour chaque handler ci-dessous, trace les dépendances en lisant le code source.
Cherche :
- calls: appels HTTP sortants vers d'autres services (méthode, path, service cible)
- reads: tables et colonnes lues — identifier la database. **Colonnes obligatoires** : tracer les colonnes SQL (SELECT, findBy, DQL, QueryBuilder). Si indéterminables → `["*"]`.
- writes: tables et colonnes écrites — identifier la database. **Colonnes obligatoires** : tracer les colonnes (INSERT, UPDATE, persist, flush). Si indéterminables → `["*"]`.
- emits: messages publiés — identifier l'exchange, la routing_key et le broker
- bindings: pour les handlers event, chercher dans les configs de broker quels exchange/routing_key sont bindés à la queue
- dead_letter: pour les handlers event, chercher la config de dead letter exchange
- tags: déterminer les tags du handler (voir references/generation-rules.md section "Tags handlers")

Handlers à analyser :
{liste des handlers avec fichiers sources}
```

**Paralléliser au maximum** : lancer plusieurs batches en parallèle (Task tool) quand les handlers n'ont pas de dépendances entre eux.

Afficher la progression dans le terminal :
```
⏳ Batch {X}/{Y} — {N} handlers en cours d'analyse...
✅ Batch {X}/{Y} terminé — {calls} calls, {reads} reads, {writes} writes, {emits} emits trouvés
```

### 3.3 Consolidation

Fusionner les résultats de tous les batches :
- Dédupliquer les connexions, brokers, databases
- Vérifier la cohérence des références croisées

### 3.4 Registry resolution

Appliquer `references/registry-resolution.md`. C'est le **seul point d'interaction** avant la validation finale — le matching progressif peut nécessiter des confirmations du dev pour les services non résolus.

---

## Étape 4 : Écriture du `.rchiv.json`

Écrire le fichier complet d'un coup :

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
  "handlers": [ ... ],
  "connections": { ... }
}
```

Appliquer les règles de `references/generation-rules.md` (omissions, `$schema` dynamique, tags, langue FR, descriptions riches, nommage DB technique, etc.).

---

## Étape 5 : Validation finale

Présenter un résumé structuré au dev et demander validation via `AskUserQuestion` :

```
Le .rchiv.json a été généré en mode auto. Voici un résumé :

- Handlers : {N} (HTTP: {n}, Events: {n}, Cron: {n}, CLI: {n})
- Connexions : {liste des services avec registry_name}
- Brokers : {liste}
- Databases : {liste}
- Libraries : {liste ou "aucune"}
- Couverture colonnes : {n}/{m} reads/writes avec colonnes explicites ({p}% — le reste utilise ["*"])
- Zones d'incertitude : {liste si applicable}

Relis le `.rchiv.json` et dis-moi si c'est correct. (oui / corrections à apporter)
```

**Boucle de correction** : corriger et re-valider tant que le dev n'a pas validé.

Après validation, **rapport final** :

```
.rchiv.json généré avec succès !

- Handlers : {N} (HTTP: {n}, Events: {n}, Cron: {n}, CLI: {n})
- Dépendances : {N} calls, {N} reads, {N} writes, {N} emits
- Couverture colonnes : {n}/{m} reads/writes avec colonnes explicites ({p}%)
- Brokers : {liste des brokers}
- Databases : {liste des databases}
- Connexions : {liste des services} (registry: {N} résolus / {M} total)
- Libraries : {liste}
- Registry : {repo ou "non configuré"}
- Fichiers analysés : {N}
- Zones d'incertitude : {liste si applicable}
```

## Registry sync

Appliquer `references/registry-sync.md`.

---

## Règles importantes

1. **Langue** : Le `.rchiv.json` est en **français** (descriptions, purpose, summary) avec identifiants techniques en anglais (noms de tables, routing keys, paths). Les interactions avec le dev sont en français. Voir `references/generation-rules.md` section "Langue et qualité des descriptions".
2. **Pas d'interaction avant la fin** : ne PAS demander de validation intermédiaire — le dev valide uniquement à l'étape 5. Exception : la résolution registry (étape 3.4) peut demander des confirmations si des services ne matchent pas.
3. **Colonnes obligatoires** : Chaque `reads` et `writes` DOIT avoir un champ `columns`. Tracer les colonnes dans le code source. Si indéterminables → `"columns": ["*"]` et signaler en zone d'incertitude.
4. **Tags obligatoires** : Chaque handler DOIT avoir des `tags` — au moins un tag de canal. Voir `references/generation-rules.md` section "Tags handlers".
5. **Pas de secrets** : Ne jamais inclure de credentials, tokens ou URLs avec credentials dans le `.rchiv.json`.
6. **Git** : Toujours récupérer le commit et le repo depuis git, ne pas les deviner.
7. **JSON valide** : 2 espaces d'indentation.
8. **Références cohérentes** : broker/database/service doivent correspondre aux clés déclarées.
9. **Registry** : Quand `registry` est présent, l'utiliser systématiquement pour la résolution cross-service.
10. **grepai** : Quand grepai est disponible, privilégier `--toon` pour les sorties LLM. Utiliser `--mode precise` pour le traçage critique. Si grepai retourne des résultats incomplets, basculer sur Grep/Glob/Read.
11. **Parallélisme** : Maximiser les Task parallèles pour l'analyse des dépendances. C'est le principal avantage du mode auto par rapport au mode init.

---

## Done

Quand le registry sync est terminé (ou refusé), **STOP**. Ne pas enchaîner d'autre action.
