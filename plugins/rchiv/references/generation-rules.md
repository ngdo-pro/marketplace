# Règles de génération du .rchiv.json

## Règles d'omission

- `method` et `path` uniquement pour les handlers HTTP
- `queue`, `bindings`, `dead_letter` uniquement pour les handlers event
- `schedule` (cron expression) uniquement pour les handlers cron
- `command` uniquement pour les handlers CLI et cron
- `status` : omettre si `"active"` (c'est le défaut)
- Omettre les clés de dépendances vides (ne pas écrire `"calls": []`)
- Omettre `bindings` si le tableau est vide
- Omettre `dead_letter` s'il n'y en a pas
- Omettre `tags` si le tableau est vide
- Omettre `libraries` si le tableau est vide
- Omettre `registry` s'il n'est pas configuré
- Omettre `registry_name` dans `calls` et `connections` si le nom local est identique à la clé du registry

## Tags handlers

Chaque handler DOIT avoir des tags qui décrivent sa nature fonctionnelle et son canal d'exposition. Les tags permettent de filtrer et regrouper les handlers.

**Tags de canal (obligatoire — au moins un)** : décrit comment le handler est exposé.
- `webhook/{source}` — endpoint déclenché par un système externe (ex: `webhook/salesforce`)
- `api/internal` — API consommée uniquement par d'autres services internes
- `api/public` — API consommée par des clients externes (mobile, SPA, partenaires)
- `gui/bo` — page ou action du backoffice (interface humaine)
- `gui/bo-command` — action de mutation déclenchée depuis le backoffice
- `gui/front` — page ou action du frontend public

**Tags de domaine (recommandé)** : décrit le domaine fonctionnel du handler.
- Utiliser les noms des bounded contexts du projet (ex: `capacity-review`, `agency-lifecycle`, `routing`, `onboarding`)
- Déduire les bounded contexts depuis la structure des dossiers (`src/BoundedContext/`, `src/Module/`), les configs Deptrac, ou les namespaces PHP

Exemple :
```json
{ "tags": ["webhook/salesforce", "agency-lifecycle"] }
{ "tags": ["gui/bo-command", "capacity-review"] }
{ "tags": ["api/internal", "routing"] }
```

## Sections déclaratives

- `connections` ne contient que les services effectivement appelés dans les `calls`
- `brokers` ne contient que les brokers effectivement utilisés dans les `bindings` et `emits`
- `databases` ne contient que les databases effectivement référencées dans les `reads` et `writes`
- `libraries` ne contient que les packages internes à l'organisation (pas les packages publics/communautaires)

## Nommage des databases

La clé de chaque database dans `databases` est le **nom technique réel de la base** tel que configuré dans l'infrastructure. C'est ce nom qui sera référencé dans les `reads` et `writes` des handlers.

**Règle** : extraire le nom réel de la base depuis la configuration (variable `DATABASE_URL`, config Doctrine, fichiers d'infra Helm/Crossplane). Exemples : `evaneos_com`, `billing_db`, `legacy_berthe`.

Sources pour trouver le nom :
1. `DATABASE_URL` ou `DATABASE_*` dans `.env` / `.env.dist` (extraire le nom de la base du DSN, ex: `postgresql://user:pass@host/evaneos_com` → `evaneos_com`)
2. Config Doctrine : `doctrine.dbal.connections.*.dbname` ou `doctrine.dbal.connections.*.url`
3. Fichiers d'infra (Helm values, Crossplane, docker-compose) qui déclarent le nom de la base

Ne PAS utiliser :
- Des noms génériques (`main`, `default`, `primary`) — trop ambigus en contexte multi-services
- Le nom de la variable d'environnement (`DATABASE_URL`)
- Le nom de la connexion Doctrine (`default`) sauf s'il correspond au nom réel de la base

## `$schema` dynamique

Si un schéma a été fetché, utiliser la valeur de `properties.$schema.const` du schéma fetché. Sinon, construire l'URL à partir du `registry.repo` : `https://raw.githubusercontent.com/{registry.repo}/main/schemas/v{version}.json`. Si aucun registry n'est configuré, omettre complètement le champ `$schema` du JSON généré.

## Colonnes obligatoires

Chaque `reads` et `writes` DOIT avoir un champ `columns`. Tracer les colonnes dans le code source (SQL, QueryBuilder, ORM mappings). Si les colonnes exactes ne peuvent pas être déterminées → `"columns": ["*"]` et signaler en zone d'incertitude.

## Git metadata

- Récupérer le commit hash via `git rev-parse HEAD`
- Récupérer le repo via `git remote get-url origin`

## Formatting

- JSON valide et bien formaté (2 espaces d'indentation)
- Références cohérentes : les valeurs de `broker` dans bindings/emits doivent correspondre à des clés de `brokers`. Les valeurs de `database` dans reads/writes doivent correspondre à des clés de `databases`. Les valeurs de `service` dans calls doivent correspondre à des clés de `connections`.
- Pas de secrets : ne jamais inclure de credentials, tokens ou URLs avec credentials

## Langue et qualité des descriptions

**Langue** : le `.rchiv.json` est en **français**. Les identifiants techniques (noms de tables, routing keys, paths) restent en anglais. Les descriptions (`purpose`, `summary`, `description` des handlers/brokers/databases/connections) sont en français.

**Qualité** : les descriptions doivent être **spécifiques au domaine métier**, pas génériques. Elles doivent mentionner les entités métier manipulées, les systèmes impliqués, et le contexte fonctionnel.

Mauvais :
- `"Main RabbitMQ broker for inter-service messaging via Symfony Messenger"`
- `"Main PostgreSQL database for agency network data"`

Bon :
- `"Broker RabbitMQ principal — exchanges topic agency_network (routage, capacités), fanout agency (sync legacy), topic apiv2.dossier.commands (réassignation dossiers)"`
- `"Base PostgreSQL principale — schémas agency_network (partenariats CQRS), agency_network_data (projections read), agency_network_public (vues Backoffice), billing (comptes), geo (destinations)""`

**Pour `semantic`** :
- `purpose` : décrire en 1-2 phrases ce que le service fait dans l'écosystème, avec les domaines fonctionnels couverts
- `domain` : le domaine métier principal (pas un synonyme approximatif — vérifier les configs Deptrac, les namespaces, la doc interne)
- `tags` : inclure les patterns architecturaux identifiés (ex: `cqrs`, `ddd`, `berthe-legacy`) en plus des domaines fonctionnels
- `summary` : mentionner les bounded contexts identifiés, les systèmes connectés clés, et les patterns architecturaux

Les interactions avec le dev sont en français.
