# boundary.config.json Format

Configuration file placed at the project root to control boundary extraction.

## Minimal Config

```json
{
  "preset": "symfony",
  "src": ["src/"]
}
```

## Full Config

```json
{
  "preset": "symfony",
  "src": ["src/", "lib/"],
  "exclude": ["vendor/", "tests/", "test/", "var/", "cache/"],
  "app_dirs": ["src/"],
  "app_only": false
}
```

## Fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `preset` | string | `"generic"` | Framework preset: `symfony`, `silex`, `zf1`, `generic` |
| `src` | string[] | `["src/"]` | Source directories to scan (relative to project root) |
| `exclude` | string[] | `["vendor/", "tests/", "test/", "var/", "cache/"]` | Directories to exclude from scanning |
| `app_dirs` | string[] | same as `src` | Directories considered "application" code. Files from `src` dirs not listed here get `"source": "lib"`. |
| `app_only` | boolean | `false` | When `true`, only entries from `app_dirs` are included in the final `boundary.json`. Entries from `lib` dirs are filtered out. |

## Presets

### `symfony`

Optimized for Symfony applications:
- Route extraction via `#[Route]` attributes and `@Route` annotations
- Symfony Messenger handler interfaces
- Symfony Console Command with `AsCommand` attribute
- Doctrine ORM Entity/Table/Column attributes and annotations
- Scheduler YAML config

### `silex`

Optimized for Silex applications:
- Route extraction via `$app->get()`, `$app->post()`, etc.
- OldSoundRabbitMqBundle consumer interface
- Symfony Console Command support
- Doctrine ORM support (same as Symfony)

### `zf1`

Optimized for Zend Framework 1 applications:
- Route extraction from Controller/Action naming conventions
- `Zend_Db_Table` subclasses for database detection
- Script/cron directory scanning

### `generic`

Framework-agnostic extraction:
- No framework-specific route detection (relies on naming conventions)
- Generic consumer/handler suffix matching
- Standard Doctrine and DBAL patterns
- Common PHP function detection (curl, file_get_contents, etc.)

## Multiple Source Directories

For projects with split source layouts:

```json
{
  "preset": "symfony",
  "src": ["src/", "app/", "legacy/", "lib/"]
}
```

## Filtering Library Code (app_dirs / app_only)

For projects with mixed app and third-party code (e.g., ZF1 apps with `lib/`), use `app_dirs` to classify entries:

```json
{
  "preset": "zf1",
  "src": ["app/", "lib/"],
  "exclude": ["vendor/", "lib/Zend/"],
  "app_dirs": ["app/"],
  "app_only": false
}
```

This adds `"source": "app"` or `"source": "lib"` to every entry in `boundary.json`. Set `"app_only": true` to completely exclude `lib/` entries from the output.

## Custom Exclusions

Override the default exclusion list:

```json
{
  "preset": "symfony",
  "src": ["src/"],
  "exclude": ["vendor/", "tests/", "var/", "node_modules/", "migrations/"]
}
```

## Preset Behavior

Presets are merged on top of the `generic` base. Framework-specific patterns supplement (not replace) generic patterns. This means:
- Symfony preset adds Route attributes on top of generic patterns
- All presets detect Guzzle, cURL, file functions, etc.
- Framework-specific patterns have priority in detection order
