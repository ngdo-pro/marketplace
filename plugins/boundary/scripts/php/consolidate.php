<?php

declare(strict_types=1);

/**
 * Consolidates all extraction results into a single boundary.json file.
 *
 * Reads individual JSON files from a results directory and merges them
 * into the final boundary.json format.
 *
 * Usage: php consolidate.php --root /path/to/project --results /path/to/results/ [--config boundary.config.json]
 *
 * Expected files in results directory:
 *   http-routes.json, workers.json, cli.json, crons.json,
 *   http-clients.json, database.json, filesystem.json, messaging.json
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// CLI args (extended)
// ---------------------------------------------------------------------------

$args = ['root' => getcwd(), 'results' => '.boundary-results', 'config' => null];

for ($i = 1, $n = count($argv); $i < $n; $i++) {
    match ($argv[$i]) {
        '--root'    => $args['root']    = $argv[++$i] ?? $args['root'],
        '--results' => $args['results'] = $argv[++$i] ?? $args['results'],
        '--config'  => $args['config']  = $argv[++$i] ?? $args['config'],
        default     => null,
    };
}

$resultsDir = $args['results'];
if (!is_dir($resultsDir)) {
    fwrite(STDERR, "Results directory not found: $resultsDir\n");
    exit(1);
}

// Load config if available (for app_dirs, app_only, src)
$config = null;
if ($args['config'] !== null && file_exists($args['config'])) {
    $config = json_decode(file_get_contents($args['config']), true);
}

$srcDirs  = $config['src'] ?? ['src/'];
$appDirs  = $config['app_dirs'] ?? $srcDirs; // Default: all src dirs are "app"
$appOnly  = $config['app_only'] ?? false;

// ---------------------------------------------------------------------------
// Load results
// ---------------------------------------------------------------------------

function loadResult(string $dir, string $filename): array
{
    $path = rtrim($dir, '/') . '/' . $filename;
    if (!file_exists($path)) {
        fwrite(STDERR, "Warning: $filename not found, skipping\n");
        return [];
    }

    $data = json_decode(file_get_contents($path), true);
    if ($data === null) {
        fwrite(STDERR, "Warning: invalid JSON in $filename, skipping\n");
        return [];
    }

    return $data;
}

$httpRoutes  = loadResult($resultsDir, 'http-routes.json');
$workers     = loadResult($resultsDir, 'workers.json');
$cli         = loadResult($resultsDir, 'cli.json');
$crons       = loadResult($resultsDir, 'crons.json');
$httpClients = loadResult($resultsDir, 'http-clients.json');
$database    = loadResult($resultsDir, 'database.json');
$filesystem  = loadResult($resultsDir, 'filesystem.json');
$messaging   = loadResult($resultsDir, 'messaging.json');

// ---------------------------------------------------------------------------
// Source classification: add "source" field to every entry based on proof path
// ---------------------------------------------------------------------------

function addSourceField(array $entries, array $appDirs, array $srcDirs): array
{
    return array_map(function (array $entry) use ($appDirs, $srcDirs): array {
        $proof = $entry['proof'] ?? '';
        $entry['source'] = classifySource($proof, $appDirs, $srcDirs);
        return $entry;
    }, $entries);
}

function filterAppOnly(array $entries): array
{
    return array_values(array_filter($entries, fn(array $e) => ($e['source'] ?? 'app') === 'app'));
}

$httpRoutes  = addSourceField($httpRoutes, $appDirs, $srcDirs);
$workers     = addSourceField($workers, $appDirs, $srcDirs);
$cli         = addSourceField($cli, $appDirs, $srcDirs);
$crons       = addSourceField($crons, $appDirs, $srcDirs);
$httpClients = addSourceField($httpClients, $appDirs, $srcDirs);
$filesystem  = addSourceField($filesystem, $appDirs, $srcDirs);
$messaging   = addSourceField($messaging, $appDirs, $srcDirs);

// app_only filtering
if ($appOnly) {
    $httpRoutes  = filterAppOnly($httpRoutes);
    $workers     = filterAppOnly($workers);
    $cli         = filterAppOnly($cli);
    $crons       = filterAppOnly($crons);
    $httpClients = filterAppOnly($httpClients);
    $filesystem  = filterAppOnly($filesystem);
    $messaging   = filterAppOnly($messaging);
}

// ---------------------------------------------------------------------------
// Build consolidated database section
// ---------------------------------------------------------------------------

function buildDatabaseSection(array $database, array $appDirs, array $srcDirs, bool $appOnly): array
{
    $entities = $database['entities'] ?? [];
    $rawQueries = $database['raw_queries'] ?? [];

    // Group entities by table
    $tables = [];
    foreach ($entities as $entity) {
        $tableName = $entity['table'];
        $source = classifySource($entity['proof'] ?? '', $appDirs, $srcDirs);
        $tables[$tableName] = [
            'name'    => $tableName,
            'entity'  => $entity['entity'] ?? null,
            'columns' => array_map(fn($c) => $c['name'], $entity['columns'] ?? []),
            'access'  => 'read_write', // Entities are typically both
            'proof'   => $entity['proof'],
            'source'  => $source,
        ];
    }

    // Merge raw query tables
    foreach ($rawQueries as $query) {
        $tableName = $query['table'];
        $source = classifySource($query['proof'] ?? '', $appDirs, $srcDirs);

        if (!isset($tables[$tableName])) {
            $entry = [
                'name'    => $tableName,
                'columns' => [],
                'access'  => $query['access'],
                'proof'   => $query['proof'],
                'source'  => $source,
            ];
            if (!empty($query['unresolved'])) {
                $entry['unresolved'] = true;
            }
            $tables[$tableName] = $entry;
        } else {
            // Upgrade access level if needed
            if ($tables[$tableName]['access'] === 'read' && $query['access'] === 'write') {
                $tables[$tableName]['access'] = 'read_write';
            } elseif ($tables[$tableName]['access'] === 'write' && $query['access'] === 'read') {
                $tables[$tableName]['access'] = 'read_write';
            }
            // Promote source to "app" if any query is from app
            if ($source === 'app') {
                $tables[$tableName]['source'] = 'app';
            }
        }
    }

    // Sort by table name
    ksort($tables);

    $result = array_values($tables);

    // app_only filtering
    if ($appOnly) {
        $result = array_values(array_filter($result, fn(array $t) => ($t['source'] ?? 'app') === 'app'));
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Extract ZF1 modules from HTTP route handlers
// ---------------------------------------------------------------------------

function extractModules(array $httpRoutes): array
{
    $modules = [];

    foreach ($httpRoutes as $route) {
        $handler = $route['handler'] ?? '';
        // ZF1 pattern: Module_ControllerController::actionAction
        if (preg_match('/^([A-Z][a-zA-Z0-9]*)_\w+Controller::/', $handler, $m)) {
            $moduleName = strtolower($m[1]);
            $modules[$moduleName] = ($modules[$moduleName] ?? 0) + 1;
        } else {
            // Default module (no underscore prefix)
            $modules['default'] = ($modules['default'] ?? 0) + 1;
        }
    }

    if (empty($modules)) {
        return [];
    }

    ksort($modules);

    // Format as array of objects
    $result = [];
    foreach ($modules as $name => $count) {
        $result[] = ['name' => $name, 'routes' => $count];
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Stats per source
// ---------------------------------------------------------------------------

function countBySource(array $entries, string $source): int
{
    return count(array_filter($entries, fn(array $e) => ($e['source'] ?? 'app') === $source));
}

// ---------------------------------------------------------------------------
// Build boundary.json
// ---------------------------------------------------------------------------

$projectName = basename(realpath($args['root']) ?: $args['root']);
$databases = buildDatabaseSection($database, $appDirs, $srcDirs, $appOnly);
$modules = extractModules($httpRoutes);

$boundary = [
    'version'      => '1.0.0',
    'name'         => $projectName,
    'generated_at' => date('c'),
    'entries'      => [
        'http'    => $httpRoutes,
        'workers' => $workers,
        'cli'     => $cli,
        'cron'    => $crons,
    ],
    'outputs'      => [
        'http_clients' => $httpClients,
        'databases'    => $databases,
        'filesystem'   => $filesystem,
        'messaging'    => $messaging,
    ],
    'stats'        => [
        'entries' => [
            'http'    => count($httpRoutes),
            'workers' => count($workers),
            'cli'     => count($cli),
            'cron'    => count($crons),
        ],
        'outputs' => [
            'http_clients' => count($httpClients),
            'databases'    => count($databases),
            'filesystem'   => count($filesystem),
            'messaging'    => count($messaging),
        ],
        'by_source' => [
            'app' => [
                'http'         => countBySource($httpRoutes, 'app'),
                'workers'      => countBySource($workers, 'app'),
                'cli'          => countBySource($cli, 'app'),
                'cron'         => countBySource($crons, 'app'),
                'http_clients' => countBySource($httpClients, 'app'),
                'databases'    => countBySource($databases, 'app'),
                'filesystem'   => countBySource($filesystem, 'app'),
                'messaging'    => countBySource($messaging, 'app'),
            ],
            'lib' => [
                'http'         => countBySource($httpRoutes, 'lib'),
                'workers'      => countBySource($workers, 'lib'),
                'cli'          => countBySource($cli, 'lib'),
                'cron'         => countBySource($crons, 'lib'),
                'http_clients' => countBySource($httpClients, 'lib'),
                'databases'    => countBySource($databases, 'lib'),
                'filesystem'   => countBySource($filesystem, 'lib'),
                'messaging'    => countBySource($messaging, 'lib'),
            ],
        ],
    ],
];

// Add modules if detected (ZF1)
if (!empty($modules)) {
    $boundary['modules'] = $modules;
}

outputJson($boundary);
