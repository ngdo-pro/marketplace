# Découverte des handlers

## Stratégie de recherche

Toujours combiner **grepai** (si disponible) et **Explore** pour maximiser la couverture. grepai excelle pour trouver des entry points dans des emplacements inattendus ; Explore est plus fiable pour l'exploration systématique par dossier.

Découper la recherche **par type d'entry point** (HTTP, events, CLI, cron), puis pour chaque type **par dossier/module** si le repo est gros. Lancer grepai et Explore en parallèle pour chaque découpe.

Pour chaque type d'entry point :

**1. grepai** (si `grepai_available`) — recherches sémantiques par type, découpées par dossier si nécessaire :

Adapter au framework détecté et cibler par dossier pour éviter de tronquer les résultats :
- Pyrite : `grepai search "Pyrite Executable route pattern handler" --toon --path src/Controller/`
- Symfony : `grepai search "Symfony #[Route] @Route controller action" --toon --path src/Controller/Api/`
- Silex : `grepai search "Silex app get post put delete match route" --toon`
- Zend : `grepai search "Zend_Controller_Action Action method" --toon --path application/modules/{module}/`

Pour les events/CLI/cron :
- `grepai search "event consumers, message handlers, queue processors, swarrot" --toon`
- `grepai search "CLI commands, console commands, Symfony Command" --toon`
- `grepai search "cron jobs, scheduled tasks" --toon`

**Ne pas utiliser `--limit`** — préférer découper par dossier (`--path`) pour avoir des résultats complets sur chaque zone plutôt que tronquer globalement.

**2. Explore** (toujours) — exploration systématique des dossiers identifiés par la détection framework :

Pour chaque type d'entry point, lancer un agent d'exploration en parallèle (Task tool, subagent_type=Explore). Découper par sous-dossier si un dossier contient trop de fichiers :

```
Explore le dossier {dossier} et liste TOUS les handlers {type} trouvés.
Pour chaque handler, donne :
- Le type (http, event, cron, cli)
- L'identifiant (method+path pour HTTP, queue name pour events, schedule pour cron, command name pour CLI)
- Le fichier source et la ligne
- Une description courte (1 phrase) de ce que fait le handler
```

**3. Consolidation** : fusionner les résultats grepai + Explore, dédupliquer, et signaler les entry points trouvés par une seule source (potentiels faux positifs ou manques).

## Patterns par framework

### Pyrite

**HTTP :**
- Les routes sont dans des fichiers YAML de config (chercher les fichiers contenant une clé `routes:`)
- Chaque route a un `pattern:` (le path) et `methods:` (GET, POST, etc.)
- Le handler est une classe référencée dans les layers (souvent via `Executor:` ou `ExecutorExtended:`) qui implémente `Pyrite\Layer\Executor\Executable`
- Lire le YAML de routing pour lister toutes les routes, puis tracer chaque handler vers sa classe `Executable`

### Symfony (3 à 7)

**HTTP :**
- Symfony 3-4 : annotations `@Route` dans les controllers (`src/Controller/`)
- Symfony 5+ : attributs PHP 8 `#[Route]` ou annotations `@Route`
- Config YAML : `config/routes/*.yaml`, `config/routes.yaml`
- Chercher aussi les routes dans `app/config/routing.yml` (Symfony 3)

**Events/messages :**
- Swarrot : config dans `config/packages/swarrot.yaml`, processors dans `src/Processor/` ou `src/Consumer/`
- Symfony Messenger : config dans `config/packages/messenger.yaml`, handlers marqués `#[AsMessageHandler]` ou `@AsMessageHandler`
- EventSubscriber/EventListener dans `config/services.yaml` ou par attribut

**CLI :**
- Classes étendant `Symfony\Component\Console\Command\Command` dans `src/Command/`
- Attribut `#[AsCommand]` ou propriété `$defaultName`

**Cron :**
- Chercher les fichiers crontab, les configs de scheduler, ou les commandes Symfony appelées par cron

### Silex

**HTTP :**
- Routes définies par appels fluents : `$app->get('/path', callback)`, `$app->post(...)`, `$app->match(...)`
- Les callbacks peuvent être des closures ou des références à des controllers (`'ControllerClass::method'`)
- Chercher aussi `$app->mount()` pour les controller providers
- Les routes peuvent être dans `app.php`, `routes.php`, ou dans des `ControllerProvider` implémentant `ControllerProviderInterface`

### Zend Framework 1

**HTTP :**
- Controllers étendant `Zend_Controller_Action` dans `application/controllers/` ou `modules/*/controllers/`
- Chaque méthode publique `*Action()` est un handler HTTP
- Le routing suit la convention `module/controller/action` → path `/module/controller/action`
- Routes custom possibles dans `application.ini` ou `Bootstrap.php`
