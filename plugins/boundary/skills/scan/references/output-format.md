# boundary.json Output Format

Version: 1.0.0

## Root Structure

```json
{
  "version": "1.0.0",
  "name": "service-name",
  "generated_at": "2026-03-19T10:00:00+00:00",
  "entries": { ... },
  "outputs": { ... },
  "stats": { ... }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `version` | string | Schema version (always "1.0.0") |
| `name` | string | Project/service name (from directory name) |
| `generated_at` | string | ISO 8601 timestamp |
| `entries` | object | All entry points into the service |
| `outputs` | object | All external dependencies the service calls |
| `stats` | object | Counts for quick overview |
| `modules` | array | *(optional)* ZF1 modules with route counts |

## Entries

### entries.http

HTTP endpoints exposed by the service.

```json
{
  "method": "GET",
  "path": "/api/v1/trips/{id}",
  "name": "app_trip_show",
  "handler": "App\\Controller\\TripController::show",
  "proof": "src/Controller/TripController.php:42"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `method` | yes | HTTP method (GET, POST, PUT, PATCH, DELETE, ANY, or combined like GET\|POST) |
| `path` | yes | Route path pattern |
| `name` | no | Route name (if defined) |
| `handler` | yes | Fully qualified class::method |
| `proof` | yes | file:line where the route is defined |
| `source` | no | `"app"` or `"lib"` — origin classification (when `app_dirs` is configured) |

### entries.workers

Message consumers/handlers.

```json
{
  "queue": "booking.created",
  "message": "App\\Message\\BookingCreated",
  "handler": "App\\MessageHandler\\BookingCreatedHandler::__invoke",
  "interface": "Symfony\\Component\\Messenger\\Handler\\MessageHandlerInterface",
  "proof": "src/MessageHandler/BookingCreatedHandler.php:12"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `queue` | no | Queue or routing key name |
| `message` | no | Message class handled |
| `handler` | yes | Fully qualified handler class::method |
| `interface` | no | Consumer interface implemented |
| `proof` | yes | file:line |

### entries.cli

CLI commands.

```json
{
  "command": "app:sync-prices",
  "description": "Synchronize prices from supplier API",
  "handler": "App\\Command\\SyncPricesCommand",
  "proof": "src/Command/SyncPricesCommand.php:15"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `command` | no | Command name (from AsCommand or configure()) |
| `description` | no | Command description |
| `handler` | yes | Fully qualified command class |
| `proof` | yes | file:line |

### entries.cron

Scheduled tasks.

```json
{
  "schedule": "0 */6 * * *",
  "command": "app:sync-prices",
  "source": "crontab",
  "proof": "crontab:5"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `schedule` | no | Cron expression or @daily/@hourly |
| `command` | yes | Command or class executed |
| `source` | yes | Where detected: "crontab", "yaml", "attribute" |
| `proof` | yes | file:line |

## Outputs

### outputs.http_clients

Outbound HTTP calls to external APIs.

```json
{
  "url": "https://api.stripe.com/v1/charges",
  "method": "POST",
  "client": "GuzzleHttp\\Client",
  "proof": "src/Service/PaymentService.php:67"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `url` | no | Target URL (may be partial or contain variables) |
| `method` | no | HTTP method (or BASE_URL for client instantiation, UNKNOWN if undetermined) |
| `client` | no | HTTP client class used |
| `proof` | yes | file:line |

### outputs.databases

Database tables accessed by the service.

```json
{
  "name": "trip",
  "entity": "App\\Entity\\Trip",
  "columns": ["id", "title", "status", "destination_id", "created_at"],
  "access": "read_write",
  "proof": "src/Entity/Trip.php:10"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `name` | yes | Table name |
| `entity` | no | Doctrine entity class |
| `columns` | yes | Array of column names |
| `access` | yes | "read", "write", or "read_write" |
| `unresolved` | no | `true` if the table name is an unresolved class constant (e.g., `self::TABLE`) |
| `proof` | yes | file:line |
| `source` | no | `"app"` or `"lib"` |

### outputs.filesystem

File system operations.

```json
{
  "function": "file_put_contents",
  "path": "/tmp/exports/{filename}.csv",
  "access": "write",
  "proof": "src/Service/ExportService.php:34"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `function` | yes | Function or method name |
| `path` | no | File/directory path (may contain variables) |
| `access` | yes | "read" or "write" |
| `proof` | yes | file:line |

### outputs.messaging

Outbound message publications.

```json
{
  "broker": "rabbitmq",
  "exchange": "events",
  "routing_key": "booking.confirmed",
  "method": "basic_publish",
  "proof": "src/Event/BookingConfirmedPublisher.php:28"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `broker` | yes | "rabbitmq" or "messenger" |
| `exchange` | no | AMQP exchange name |
| `routing_key` | no | AMQP routing key |
| `message` | no | Message class (for Symfony Messenger) |
| `method` | yes | Publish method used |
| `proof` | yes | file:line |

## Modules (optional, ZF1)

When ZF1 routes are detected, a `modules` array lists each module with its route count:

```json
{
  "modules": [
    { "name": "betty", "routes": 42 },
    { "name": "default", "routes": 15 },
    { "name": "admin", "routes": 8 }
  ]
}
```

## Stats

Automatic counts for quick overview:

```json
{
  "stats": {
    "entries": {
      "http": 23,
      "workers": 4,
      "cli": 2,
      "cron": 1
    },
    "outputs": {
      "http_clients": 3,
      "databases": 14,
      "filesystem": 5,
      "messaging": 2
    },
    "by_source": {
      "app": { "http": 23, "workers": 4, "filesystem": 2, "..." : "..." },
      "lib": { "http": 0, "workers": 0, "filesystem": 90, "..." : "..." }
    }
  }
}
```
