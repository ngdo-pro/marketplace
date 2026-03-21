# Verify Workflow Details

## Verification Patterns by Section

### HTTP Endpoints
- Check route path matches the attribute/annotation
- Verify HTTP method is correct
- Confirm handler class and method exist

### Workers
- Verify the consumer interface is implemented
- Check queue name matches config
- Confirm handler method exists

### CLI Commands
- Verify command name matches configure() or AsCommand
- Check the command class extends Command

### Databases
- Verify table name matches Entity annotation
- Check columns match property annotations
- Confirm access level (read/write) is accurate

### HTTP Clients
- Verify URL is an external service (not internal)
- Check HTTP method is correct
- Confirm the client class is actually an HTTP client

### Filesystem
- Distinguish temp file operations from meaningful I/O
- Filter out logging/caching that's not a true boundary
- Focus on operations that cross service boundaries

### Messaging
- Verify exchange/routing key matches config
- Check the publisher is actually publishing (not just importing)
- Confirm broker type (rabbitmq vs messenger)

## Correction Examples

### Adding a Missing HTTP Route

When grep finds a route not in boundary.json:

```json
{
  "method": "POST",
  "path": "/api/v1/bookings",
  "handler": "App\\Controller\\BookingController::create",
  "proof": "src/Controller/BookingController.php:78"
}
```

Add it to `entries.http` array and increment `stats.entries.http`.

### Removing a False Positive

When an extracted item is not actually a boundary:
- Filesystem operations on temp/cache files are not real boundaries
- HTTP client calls to the service itself (not external) should be removed
- Test helper methods matching consumer patterns are false positives

Remove from the relevant array and decrement the stat.

### Fixing a Proof Line Number

When source code has changed since the scan:
1. Grep for the handler/function in the source file
2. Find the correct line number
3. Update the `proof` field

### Changing Access Level

When a database table's access is incorrect:
```json
// Before: detected as read_write because of an ORM entity
{ "name": "audit_log", "access": "read_write" }

// After: this service only writes to audit_log
{ "name": "audit_log", "access": "write" }
```

## Complementary Grep Patterns

### For HTTP Routes (catch what AST might miss)

```
# Symfony annotations in docblocks
@Route\s*\(

# Symfony attributes
#\[Route\(

# Silex routes
\$app->(get|post|put|patch|delete|match)\(

# Manual route registration
->add(Route|Prefix)\(
addRoute\(

# ZF1 actions
function\s+\w+Action\s*\(
```

### For Workers (catch what AST might miss)

```
# Interface implementations
implements\s+.*Consumer
implements\s+.*Handler.*Interface
implements\s+.*Subscriber

# RabbitMQ
basic_consume\(
ConsumerInterface
old_sound_rabbit_mq

# Symfony Messenger
#\[AsMessageHandler
MessageHandlerInterface
```

### For Databases (catch what AST might miss)

```
# Doctrine entities
#\[ORM\\Entity
#\[ORM\\Table
@ORM\\Entity
@ORM\\Table

# Raw SQL
FROM\s+[`"]?\w+[`"]?
INSERT\s+INTO
UPDATE\s+\w+\s+SET
DELETE\s+FROM

# QueryBuilder
->from\(
->join\(
->leftJoin\(
->createQueryBuilder\(
```

### For HTTP Clients (catch what AST might miss)

```
# Guzzle
new\s+Client\(
new\s+GuzzleHttp
->request\(
->getAsync\(

# cURL
curl_init
curl_setopt.*CURLOPT_URL

# Symfony HttpClient
HttpClient::create
->withOptions\(

# Native
file_get_contents\s*\(\s*['"]https?://
```

### For Messaging (catch what AST might miss)

```
# AMQP
basic_publish\(
->publish\(

# Symfony Messenger
->dispatch\(.*new\s+
MessageBusInterface

# OldSound
Producer
producer.*->publish
```

## Filtering Noise

### Filesystem Operations to Ignore

- `file_exists()` checks on config files (not a boundary)
- Log writes via Monolog/PSR-3 (handled by logging, not a boundary)
- Cache reads/writes (internal, not a boundary)
- Autoloader file operations
- Temp file creation in `/tmp/` for short-lived operations

### HTTP Client Calls to Scrutinize

- Calls to `localhost` or `127.0.0.1` — likely internal, not a boundary
- Calls with variable URLs — note the pattern but may need manual review
- Calls in test fixtures or factories — false positives

### Database Queries to Scrutinize

- Schema migration queries (not runtime boundaries)
- Test fixture setup queries
- Information schema queries (metadata, not data access)

## Interactive Presentation Format

### Batch Presentation (HTTP Routes Example)

```
## HTTP Endpoints (1-10 of 23)

| # | Method | Path | Handler | Proof |
|---|--------|------|---------|-------|
| 1 | GET | /api/v1/trips | TripController::index | src/Controller/TripController.php:25 |
| 2 | GET | /api/v1/trips/{id} | TripController::show | src/Controller/TripController.php:42 |
| 3 | POST | /api/v1/trips | TripController::create | src/Controller/TripController.php:67 |
| ... | ... | ... | ... | ... |

Corrections? (remove #, fix #, add missing, or "ok" for next batch)
```

### Database Table Presentation

```
## Database Tables (1-5 of 14)

### 1. trip (read_write)
Columns: id, title, status, destination_id, created_at, updated_at
Entity: App\Entity\Trip
Proof: src/Entity/Trip.php:10

### 2. booking (read_write)
Columns: id, trip_id, user_id, status, amount, booked_at
Entity: App\Entity\Booking
Proof: src/Entity/Booking.php:8

Corrections?
```
