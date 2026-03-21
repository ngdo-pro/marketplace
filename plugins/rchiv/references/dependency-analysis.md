# Analyse des dépendances

Toujours combiner **grepai** (si disponible) et **Explore** pour maximiser la couverture. grepai trace les call graphs rapidement ; Explore complète en lisant le code source pour valider et trouver ce que grepai peut manquer.

## Stratégie d'analyse

Pour chaque batch de handlers, lancer en parallèle :

### 1. grepai trace (si `grepai_available`)

#### Call graph itératif

Commencer avec `--depth 5`, puis augmenter de 5 tant que les boundaries n'ont pas été atteintes :

1. `grepai trace graph "{ClassName}::{methodName}" --depth 5 --mode precise --toon`
2. Vérifier si les noeuds terminaux du graph sont des boundaries (voir ci-dessous)
3. Si des noeuds terminaux ne sont pas des boundaries (méthodes internes, abstractions intermédiaires) → relancer avec `--depth 10`
4. Répéter en incrémentant de 5 (`--depth 15`, `--depth 20`, etc.) jusqu'à atteindre les boundaries

Lancer les `grepai trace graph` en parallèle par batches de 5-10 handlers.

#### Validation croisée avec `trace callers`

Pour les dépendances critiques (clients HTTP, publishers), vérifier avec :
`grepai trace callers "{ClientClass}::{method}" --mode precise --toon`
pour s'assurer qu'aucun handler n'a été oublié.

### 2. Explore (toujours)

Lancer en parallèle des agents d'exploration (Task tool, subagent_type=Explore) par batch de 5-10 handlers :

```
Pour chaque handler ci-dessous, trace les dépendances en lisant le code source.
Cherche :
- calls: appels HTTP sortants vers d'autres services (méthode, path, service cible)
- reads: tables et colonnes lues (SELECT, find, findBy, etc.) — identifier la database. **Colonnes obligatoires** : tracer les colonnes SQL jusqu'aux SELECT, findBy, requêtes DQL/QueryBuilder. Si les colonnes exactes ne peuvent pas être déterminées (views, ORM dynamique, SELECT *), mettre `["*"]`.
- writes: tables et colonnes écrites (INSERT, UPDATE, persist, etc.) — identifier la database. **Colonnes obligatoires** : tracer les colonnes des INSERT/UPDATE/persist/flush. Si les colonnes exactes ne peuvent pas être déterminées (ORM full-entity persist, dynamic queries), mettre `["*"]`.
- emits: messages publiés (dispatch, publish, emit) — identifier l'exchange, la routing_key et le broker
- bindings: pour les handlers event, chercher dans les configs de broker (rabbitmq-definition-*.yaml, swarrot.yaml, etc.) quels exchange/routing_key sont bindés à la queue
- dead_letter: pour les handlers event, chercher la config de dead letter exchange

Handlers à analyser :
{liste des handlers avec fichiers sources}
```

### 3. Consolidation

Fusionner les résultats grepai + Explore, dédupliquer, et signaler les dépendances trouvées par une seule source.

Pour les noms logiques des services appelés : chercher dans les fichiers de config identifiés précédemment. Si des fichiers infra ont été fournis (voir `framework-detection.md`), corréler les env vars avec les boundaries découvertes (ex: `PAYMENT_SERVICE_URL` → service "payment", `DATABASE_URL` → connexion DB principale). Si un appel sortant ne peut pas être résolu en nom logique, demander au dev.

## Identifier les boundaries

Les noeuds terminaux attendus sont des boundaries. Certaines sont capturées dans le `.rchiv.json`, d'autres non :

**Capturées dans le format :**
- Appels à des clients HTTP (Guzzle, HttpClient, etc.) → `calls`
- Appels à des repositories, EntityManager, Connection, PDO → `reads`/`writes`
- Appels à des publishers, dispatchers (Swarrot, Messenger) → `emits`

**Non capturées (warning)** — ces boundaries stoppent le trace mais ne sont pas dans le format actuel. Afficher un warning dans le terminal pour chaque occurrence :
- Filesystem : Flysystem, S3/GCS SDK, `file_get_contents`/`file_put_contents`
- Cache : Redis (Predis, phpredis), Memcached, Symfony Cache
- Email : SwiftMailer, Symfony Mailer, SMTP
- Search : Elasticsearch, Algolia
- External SDKs : Stripe, payment providers, etc.

Format du warning :
```
⚠️  Boundary non capturée : {type} ({classe}::{méthode}) dans le handler {handler} — non supporté par le format .rchiv.json actuel
```

Lister toutes les boundaries non capturées dans les zones d'incertitude du rapport final.

Si un noeud terminal est une méthode interne (service, use case, helper), c'est que la depth est insuffisante.

## Noeuds ambigus

Pour les noeuds ambigus (le graph montre un appel mais la nature de la boundary n'est pas claire), lire le fichier source avec Read pour confirmer.

## Bindings et dead_letter

grepai ne trace pas les configs de broker. Les bindings et dead letter DOIVENT être recherchés **systématiquement** pour chaque handler de type `event`. Ne pas se fier au code source uniquement — les bindings sont déclarés dans la configuration du broker, pas dans le code PHP.

### Stratégie de recherche des bindings

**Étape 1 — Identifier les fichiers de config broker** (à faire une seule fois, en Phase 1) :

Chercher dans l'ordre :
1. `config/packages/swarrot.yaml` ou `config/packages/swarrot.yml` (Swarrot)
2. `config/packages/messenger.yaml` ou `config/packages/messenger.yml` (Symfony Messenger)
3. `rabbitmq-definition*.yaml`, `rabbitmq-definition*.json` (définitions RabbitMQ directes)
4. Fichiers Terraform/Helm qui déclarent les bindings RabbitMQ (`*.tf`, `values*.yaml` contenant `rabbitmq`)
5. `docker-compose*.yml` contenant des définitions RabbitMQ

**Étape 2 — Pour chaque handler event, extraire** :

**Swarrot** : dans la config YAML, chercher la queue du handler. La queue est liée à un exchange+routing_key dans la section `consumers:` ou via les définitions RabbitMQ :
```yaml
# swarrot.yaml — le consumer déclare la queue
consumers:
  agency_created:
    processor: App\Processor\AgencyCreatedProcessor
    middleware_stack: [...]
    extras:
      queue: agency_created

# rabbitmq-definition.yaml — le binding lie queue → exchange + routing_key
bindings:
  - source: agency_network
    destination: agency_created
    routing_key: agency.created
```

**Symfony Messenger** : dans la config YAML, chercher le transport du handler :
```yaml
# messenger.yaml
transports:
  agency_events:
    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
    options:
      exchange:
        name: agency_network
        type: topic
      queues:
        agency_created:
          binding_keys: ['agency.created']
```

**Étape 3 — Dead letter** : chercher dans les mêmes configs la configuration DLX :
- Swarrot : `dead_letter_exchange` dans la config du consumer ou dans les définitions RabbitMQ (`x-dead-letter-exchange`, `x-dead-letter-routing-key`)
- Messenger : `dead_letter_queue` dans les options du transport

### Résultat attendu pour chaque handler event

```json
{
  "bindings": [
    { "exchange": "agency_network", "routing_key": "agency.created", "broker": "rabbitmq" }
  ],
  "dead_letter": {
    "exchange": "dlx.agency_network",
    "queue": "dlq.agency_created"
  }
}
```

Si les fichiers de config broker n'existent pas ou ne contiennent pas les bindings, demander au dev via `AskUserQuestion`.
