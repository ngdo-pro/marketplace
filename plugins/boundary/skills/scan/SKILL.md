---
name: boundary:scan
description: >-
  This skill should be used when the user asks to "scan boundaries",
  "map a PHP codebase", "extract entries and outputs", "find HTTP endpoints",
  "list database tables", "detect workers", "map service boundaries",
  "list API routes", "find external calls", "generate boundary file",
  or mentions boundary extraction on a PHP codebase. Orchestrates
  deterministic PHP AST scripts to extract all entries (HTTP, workers, CLI, cron)
  and outputs (HTTP clients, databases, filesystem, messaging) into boundary.json.
argument-hint: "[path to PHP project root]"
allowed-tools:
  - Bash
  - Read
  - Write
  - Edit
  - Glob
  - Grep
  - AskUserQuestion
---

# Boundary Scan

Orchestrate PHP AST extraction scripts to produce a reliable `boundary.json` mapping all entry points and external outputs of a PHP codebase.

## Prerequisites

Ensure the PHP extraction scripts have their dependencies installed. Check once per session:

```bash
cd <plugin_root_path>/scripts/php && [ -d vendor ] || composer install --no-interaction
```

Resolve `<plugin_root_path>` to the actual plugin installation directory before running.

## Workflow

### Step 1: Config

Check if `boundary.config.json` exists at the project root. If not, ask the user which preset to use (symfony, silex, zf1, generic) and which source directories to scan.

Create a minimal config. Refer to **`references/config-format.md`** for the schema.

### Step 2: Run Extractors

Create a temporary results directory and run all 8 extraction scripts. Each script outputs JSON to stdout.

```bash
PLUGIN_DIR="<plugin_root_path>"
PROJECT_ROOT="<project_root>"
CONFIG="$PROJECT_ROOT/boundary.config.json"
RESULTS_DIR="$PROJECT_ROOT/.boundary-results"
mkdir -p "$RESULTS_DIR"

# Run all extractors (parallelize up to 4 at a time)
for extractor in http-routes workers cli crons http-clients database filesystem messaging; do
  php "$PLUGIN_DIR/scripts/php/extract-${extractor}.php" --root "$PROJECT_ROOT" --config "$CONFIG" > "$RESULTS_DIR/${extractor}.json" 2>"$RESULTS_DIR/${extractor}.log" &
  [ $(jobs -r | wc -l) -ge 4 ] && wait -n
done
wait
```

Replace `<plugin_root_path>` and `<project_root>` with actual paths before running.

### Step 3: Check for Errors

Read each `.log` file in the results directory. Report parse errors or warnings to the user. Common issues:
- Source directory not found → wrong `src` paths in config
- Parse errors → PHP syntax errors in source files (skip those files)
- Preset not found → wrong preset name in config

### Step 4: Consolidate

Run the consolidation script:

```bash
php "$PLUGIN_DIR/scripts/php/consolidate.php" --root "$PROJECT_ROOT" --results "$RESULTS_DIR" --config "$CONFIG" > "$PROJECT_ROOT/boundary.json"
```

### Step 5: Present Summary

Read the generated `boundary.json` and present a summary to the user:

```
## Boundary Scan Results — {project_name}

### Entries
- {n} HTTP endpoints
- {n} workers/consumers
- {n} CLI commands
- {n} cron jobs

### Outputs
- {n} external HTTP calls
- {n} database tables ({n} columns total)
- {n} filesystem operations
- {n} messaging publications

Generated: boundary.json
```

### Step 6: Cleanup

Remove the `.boundary-results/` directory after successful consolidation.

## Handling Failures

If an extractor fails completely (empty JSON or error exit code):
1. Check the log file for details
2. Report which extractor failed and why
3. Continue with remaining extractors — partial results are still valuable
4. Mark the failed sections in the summary

## Script Details

Each script accepts `--root` and `--config` flags. All use `nikic/php-parser` for AST analysis and produce JSON on stdout with diagnostics on stderr.

| Script | Extracts |
|--------|----------|
| `extract-http-routes.php` | Symfony Route attributes/annotations, Silex `$app->get()`, ZF1 controllers |
| `extract-workers.php` | Consumer/handler classes by interface or naming convention |
| `extract-cli.php` | Symfony Console commands, AsCommand attributes |
| `extract-crons.php` | Crontab files, AsCronTask attributes, YAML scheduler config |
| `extract-http-clients.php` | Guzzle, Symfony HttpClient, cURL calls |
| `extract-database.php` | Doctrine entities (tables + columns), raw SQL queries |
| `extract-filesystem.php` | Native file functions, Flysystem/Filesystem component |
| `extract-messaging.php` | AMQP publish, OldSoundRabbitMq producer, Messenger dispatch |

## Additional Resources

- **`references/output-format.md`** — Full boundary.json schema with examples
- **`references/config-format.md`** — boundary.config.json options and preset descriptions

## Language

All user-facing output MUST match the user's language.
