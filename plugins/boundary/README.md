# boundary

Reliable PHP codebase boundary mapper using deterministic AST analysis.

## What it does

Scans a PHP codebase and extracts all service boundaries into a structured `boundary.json`:

**Entries** (what goes in):
- HTTP endpoints (Symfony, Silex, ZF1)
- Workers/consumers (RabbitMQ, Symfony Messenger)
- CLI commands (Symfony Console)
- Cron jobs (crontab, scheduler config, attributes)

**Outputs** (what goes out):
- HTTP client calls (Guzzle, cURL, Symfony HttpClient)
- Database access (Doctrine entities with tables + columns, raw SQL)
- Filesystem operations (read/write)
- Messaging publications (AMQP, Symfony Messenger)

## How it works

PHP scripts using `nikic/php-parser` perform AST analysis — no heuristics, no guessing. Framework-specific presets configure extraction patterns.

## Prerequisites

- PHP >= 8.1
- Composer (for installing php-parser)

## Usage

### Scan

```
/boundary:scan /path/to/project
```

Creates `boundary.config.json` (if needed) and `boundary.json`.

### Verify

```
/boundary:verify
```

Interactive audit of boundary.json — section by section with proof verification.

## Configuration

Minimal `boundary.config.json` at project root:

```json
{
  "preset": "symfony",
  "src": ["src/"]
}
```

Available presets: `symfony`, `silex`, `zf1`, `generic`.

## Output

`boundary.json` — structured JSON with entries, outputs, and `file:line` proofs for every item. Designed to feed into `.rchiv.json` generation.
