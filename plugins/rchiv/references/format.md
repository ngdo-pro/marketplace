# Format .rchiv.json — Référence

## Sections racine

| Section | Requis | Description |
|---------|--------|-------------|
| `$schema` | non | `"https://raw.githubusercontent.com/{registry.repo}/main/schemas/{version}.json"` — construit dynamiquement depuis `registry.repo` et la version du schéma. Omettre si pas de registry configuré. |
| `version` | oui | Version extraite du schéma du registry |
| `name` | oui | Nom logique du service |
| `semantic` | non | Couche sémantique (purpose, domain, tags, summary) |
| `_meta` | non | Métadonnées de génération (repo, commit, generated_at) |
| `registry` | non | Configuration du registry central ({repo}) |
| `brokers` | non | Message brokers utilisés (nom -> {type, description}) |
| `databases` | non | Bases de données utilisées (nom -> {type, description}) |
| `libraries` | non | Libraries internes utilisées (tableau de {name, version}) |
| `handlers` | oui | Tableau des entry points du service |
| `connections` | non | Services externes appelés (nom -> {description, registry_name?}) |

## Registry

```json
{
  "repo": "org/architecture-registry"
}
```

Le registry est un repo GitHub contenant un fichier `services.json` à la racine :

```json
{
  "member": "org/member-service/.rchiv.json",
  "billing": "org/billing-api/.rchiv.json",
  "notification": "org/notification-service/.rchiv.json"
}
```

Chaque clé est le **nom canonique** du service. La valeur est le chemin GitHub vers son `.rchiv.json`.

## Databases

```json
{
  "evaneos_com": {
    "type": "postgresql",
    "description": "Base PostgreSQL principale — schémas agency_network (CQRS write), agency_network_data (projections read), billing (comptes)"
  }
}
```

- La clé est le **nom technique réel** de la base de données — voir `generation-rules.md` section "Nommage des databases"
- `description` : en français, mentionner les schémas et leur rôle fonctionnel

## Connections

```json
{
  "travelerApi": {
    "description": "Service de gestion des membres/voyageurs",
    "registry_name": "member"
  }
}
```

- La clé (`travelerApi`) est le nom utilisé **localement** dans le code
- `registry_name` est la clé dans `services.json` du registry — toujours présent si le service a été trouvé dans le registry

## Types de handlers

| Type | Champs spécifiques |
|------|-------------------|
| `http` | `method`, `path` |
| `event` | `queue`, `bindings[]`, `dead_letter` |
| `cron` | `schedule`, `command` |
| `cli` | `command` |
| `grpc` | `service`, `method` |
| `graphql` | `operation`, `field` |

## Champs communs à tous les handlers

| Champ | Requis | Description |
|-------|--------|-------------|
| `type` | oui | Type de handler (enum) |
| `description` | oui | Description lisible (en français, spécifique au domaine métier) |
| `status` | non | `"active"` (défaut) / `"not-deployed"` / `"deprecated"` |
| `tags` | oui* | Tags décrivant le canal d'exposition et le domaine — voir `generation-rules.md` section "Tags handlers". *Omettre uniquement si aucun tag applicable. |
| `dependencies` | non | Dépendances typées |

## Bindings (handlers event)

```json
{
  "exchange": "exchange.name",
  "routing_key": "routing.key.or.pattern.*",
  "broker": "broker-name"
}
```

Les routing keys avec wildcards sont stockées telles quelles.

## Dead letter (handlers event)

```json
{
  "exchange": "dlx.exchange.name",
  "queue": "dlq.queue.name"
}
```

## Libraries

```json
{
  "libraries": [
    { "name": "@evaneos/front-config", "version": "^5.3.0" },
    { "name": "evaneos/sites", "version": "^2.1" }
  ]
}
```

- Ne lister que les packages internes à l'organisation (vendor/scope de l'org)
- La version est la contrainte telle que déclarée dans le package manager (composer.json, package.json)

## Types de dépendances

| Dépendance | Format | Description |
|------------|--------|-------------|
| `calls` | `{ service, registry_name?, method, path }` | Appels HTTP sortants vers d'autres services |
| `reads` | `{ table, columns[], database }` | Tables et colonnes lues — `columns` obligatoire (utiliser `["*"]` si indéterminable) |
| `writes` | `{ table, columns[], database }` | Tables et colonnes écrites — `columns` obligatoire (utiliser `["*"]` si indéterminable) |
| `emits` | `{ exchange, routing_key, broker }` | Messages publiés vers un broker |

- `service` : nom logique tel qu'utilisé dans le code du projet
- `registry_name` : nom canonique dans le registry — toujours présent si le service a été trouvé dans le registry

## Principes

- **Unified handlers** — tous les types dans un seul tableau `handlers`
- **Granularité par endpoint** — chaque handler porte ses propres dépendances
- **Colonnes obligatoires** — `columns` est requis dans chaque `reads`/`writes`. Lister les colonnes explicites quand traçables, sinon `["*"]`
- **Déclaratif** — `connections`, `brokers`, `databases`, `libraries` déclarent les ressources ; les handlers y font référence par nom
- **Sémantique** — section globale `semantic` + `description` par handler
- **Symétrie** — les `bindings` (entrants) et `emits` (sortants) utilisent la même structure exchange/routing_key/broker
- **Registry** — résolution centralisée des noms de services via un repo GitHub partagé
