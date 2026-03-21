# Détection framework et configuration

Tous les projets sont en PHP. Les frameworks possibles sont : Pyrite, Symfony (3 à 7), Silex, Zend Framework 1.

## Détection framework

Détecter le framework via `composer.json` et les fichiers de config :

| Indice | Framework | Patterns de recherche |
|--------|-----------|----------------------|
| `evaneos/pyrite` dans composer.json, fichiers YAML avec clé `routes:` contenant `pattern:` + `methods:` | **Pyrite** | Fichiers de config YAML de routing (`config/routing*.yml`, `config/routes*.yml`), classes implémentant `Executable` |
| `symfony/framework-bundle` dans composer.json, `config/packages/` ou `app/config/` | **Symfony** | `src/Controller/`, `src/Command/`, `config/routes/`, `config/packages/swarrot.yaml`, `config/packages/messenger.yaml` |
| `silex/silex` dans composer.json | **Silex** | `$app->get(`, `$app->post(`, `$app->put(`, `$app->delete(`, `$app->match(` dans les fichiers PHP |
| `zendframework/zendframework1` ou `Zend_Controller_Action` | **Zend Framework 1** | Classes étendant `Zend_Controller_Action`, méthodes `*Action()`, `application.ini`, `Bootstrap.php` |

Un projet peut combiner plusieurs frameworks (ex: Pyrite + Symfony pour les commandes CLI). Vérifier tous les indices.

## Détection complémentaire (brokers, databases, libraries)

Lancer en parallèle :

**1. grepai** (si `grepai_available`) :
- `grepai search "message broker configuration RabbitMQ swarrot messenger AMQP" --toon`
- `grepai search "database configuration doctrine connection DSN DATABASE_URL" --toon`
- `grepai search "internal library evaneos package" --toon`

**2. Explore** (toujours) :
- Chercher les fichiers de config de broker (YAML, env, etc.)
- Chercher les fichiers de config de database (doctrine, env, etc.)
- Lire `composer.json` pour les packages internes (voir ci-dessous)

Consolider les résultats des deux sources.

### Détection des libraries internes

Les libraries internes sont les packages **développés et maintenus par l'organisation** (pas les packages open source communautaires).

**Étape 1 — Lire `composer.json`** et extraire TOUTES les dépendances `require` dont le vendor matche l'organisation :
- Pattern principal : `evaneos/*` (ex: `evaneos/pyrite`, `evaneos/sites`, `evaneos/front-config`)
- Patterns alternatifs à vérifier : le nom de l'organisation dans les repos privés Packagist/Satis, ou des vendors qui correspondent à des équipes internes

**Étape 2 — Filtrer les frameworks** : ne PAS inclure le framework de base du projet (ex: `evaneos/pyrite` si c'est le framework Pyrite détecté en Phase 1). Inclure uniquement les packages qui fournissent des fonctionnalités métier ou des intégrations partagées.

**Étape 3 — Vérifier `composer.lock`** (si présent) pour les versions réellement installées. Sinon, utiliser la contrainte de version de `composer.json`.

**Étape 4 — Chercher aussi les packages JS/TS internes** si le projet a un frontend :
- `package.json` : dépendances avec scope `@{org}/` (ex: `@evaneos/front-config`)

Résultat attendu :
```json
{
  "libraries": [
    { "name": "evaneos/sites", "version": "^2.1" },
    { "name": "@evaneos/front-config", "version": "^5.3.0" }
  ]
}
```

## Détection infra (optionnel)

Si le projet a des fichiers d'infrastructure (helm charts, CI pipelines), les utiliser comme source complémentaire pour détecter les services connectés. Demander au dev via `AskUserQuestion` :

> "As-tu des fichiers d'infrastructure à fournir ? (helm values, CI pipelines — optionnel, aide à identifier les services connectés)"

Si oui, lire les fichiers fournis et extraire :

- **Variables d'environnement** : noms et descriptions (JAMAIS les valeurs/secrets). Patterns : `*_URL`, `*_HOST`, `*_DSN`, `DATABASE_*`, `RABBITMQ_*`, `REDIS_*`
- **Services connectés** : databases, brokers, APIs déclarés dans le déploiement (sidecars, services k8s, connexions Crossplane, etc.)

Stocker ces infos pour la corrélation avec les boundaries découvertes lors de l'analyse des dépendances (voir `dependency-analysis.md`).
