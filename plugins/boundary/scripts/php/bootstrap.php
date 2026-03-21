<?php

declare(strict_types=1);

/**
 * Shared bootstrap for all boundary extraction scripts.
 *
 * Provides: CLI argument parsing, config/preset loading, file scanning,
 * AST parser creation, and JSON output.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\ParserFactory;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------

function parseArgs(array $argv): array
{
    $args = [
        'root'   => getcwd(),
        'config' => 'boundary.config.json',
    ];

    for ($i = 1, $n = count($argv); $i < $n; $i++) {
        match ($argv[$i]) {
            '--root'   => $args['root']   = $argv[++$i] ?? $args['root'],
            '--config' => $args['config'] = $argv[++$i] ?? $args['config'],
            default    => null,
        };
    }

    $args['root'] = rtrim(realpath($args['root']) ?: $args['root'], '/');

    return $args;
}

// ---------------------------------------------------------------------------
// Config & presets
// ---------------------------------------------------------------------------

function loadConfig(string $configPath): array
{
    if (!file_exists($configPath)) {
        fwrite(STDERR, "Config file not found: $configPath\n");
        exit(1);
    }

    $config = json_decode(file_get_contents($configPath), true);
    if ($config === null) {
        fwrite(STDERR, "Invalid JSON in config: $configPath\n");
        exit(1);
    }

    // Defaults
    $config['src']     = $config['src'] ?? ['src/'];
    $config['exclude'] = $config['exclude'] ?? ['vendor/', 'tests/', 'test/', 'var/', 'cache/'];
    $config['preset']  = $config['preset'] ?? 'generic';

    return $config;
}

function loadPreset(string $presetName): array
{
    $path = __DIR__ . '/presets/' . $presetName . '.json';
    if (!file_exists($path)) {
        fwrite(STDERR, "Preset not found: $presetName (looked at $path)\n");
        exit(1);
    }

    $preset = json_decode(file_get_contents($path), true);
    if ($preset === null) {
        fwrite(STDERR, "Invalid JSON in preset: $path\n");
        exit(1);
    }

    return $preset;
}

/**
 * Merge the generic preset as a base, then overlay the framework-specific preset.
 */
function loadMergedPreset(string $presetName): array
{
    $generic = loadPreset('generic');

    if ($presetName === 'generic') {
        return $generic;
    }

    $specific = loadPreset($presetName);

    return arrayMergeDeep($generic, $specific);
}

function arrayMergeDeep(array $base, array $overlay): array
{
    foreach ($overlay as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            if (array_is_list($value) && array_is_list($base[$key])) {
                // Merge indexed arrays (deduplicate)
                $base[$key] = array_values(array_unique(array_merge($base[$key], $value)));
            } else {
                $base[$key] = arrayMergeDeep($base[$key], $value);
            }
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

// ---------------------------------------------------------------------------
// File scanning
// ---------------------------------------------------------------------------

function findPhpFiles(string $root, array $srcDirs, array $excludeDirs = []): array
{
    $files = [];

    foreach ($srcDirs as $srcDir) {
        $fullPath = $root . '/' . ltrim($srcDir, '/');
        if (!is_dir($fullPath)) {
            fwrite(STDERR, "Warning: source directory not found: $fullPath\n");
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = ltrim(str_replace($root, '', $file->getPathname()), '/');

            $excluded = false;
            foreach ($excludeDirs as $excludeDir) {
                if (str_starts_with($relativePath, $excludeDir)) {
                    $excluded = true;
                    break;
                }
            }

            if (!$excluded) {
                $files[] = [
                    'absolute' => $file->getPathname(),
                    'relative' => $relativePath,
                ];
            }
        }
    }

    return $files;
}

// ---------------------------------------------------------------------------
// AST parser
// ---------------------------------------------------------------------------

function createParser(): PhpParser\Parser
{
    return (new ParserFactory())->createForNewestSupportedVersion();
}

function parseFile(PhpParser\Parser $parser, string $filePath): ?array
{
    $code = file_get_contents($filePath);
    if ($code === false) {
        return null;
    }

    try {
        return $parser->parse($code);
    } catch (\PhpParser\Error $e) {
        fwrite(STDERR, "Parse error in $filePath: {$e->getMessage()}\n");
        return null;
    }
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

function outputJson(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

// ---------------------------------------------------------------------------
// Utility: extract string value from AST node
// ---------------------------------------------------------------------------

function extractStringValue(PhpParser\Node $node): ?string
{
    if ($node instanceof PhpParser\Node\Scalar\String_) {
        return $node->value;
    }
    if ($node instanceof PhpParser\Node\Scalar\InterpolatedString) {
        // Return partial string for interpolated strings
        $parts = [];
        foreach ($node->parts as $part) {
            if ($part instanceof PhpParser\Node\InterpolatedStringPart) {
                $parts[] = $part->value;
            } else {
                $parts[] = '{...}';
            }
        }
        return implode('', $parts);
    }
    if ($node instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
        $left = extractStringValue($node->left);
        $right = extractStringValue($node->right);
        if ($left !== null && $right !== null) {
            return $left . $right;
        }
    }
    if ($node instanceof PhpParser\Node\Expr\ClassConstFetch) {
        $class = $node->class instanceof PhpParser\Node\Name
            ? $node->class->toString()
            : '?';
        $name = $node->name instanceof PhpParser\Node\Identifier
            ? $node->name->toString()
            : '?';
        return $class . '::' . $name;
    }

    return null;
}

/**
 * Classify a proof path as "app" or "lib" based on configured app directories.
 */
function classifySource(string $proof, array $appDirs, array $srcDirs): string
{
    // Extract file path from proof (remove :line suffix)
    $filePath = preg_replace('/:\d+$/', '', $proof);

    // If no app_dirs configured, everything is "app"
    if (empty($appDirs)) {
        return 'app';
    }

    foreach ($appDirs as $appDir) {
        if (str_starts_with($filePath, rtrim($appDir, '/'))) {
            return 'app';
        }
    }

    // File is in src but not in app_dirs → lib
    foreach ($srcDirs as $srcDir) {
        if (str_starts_with($filePath, rtrim($srcDir, '/'))) {
            return 'lib';
        }
    }

    return 'app'; // Default fallback
}

/**
 * Resolve fully-qualified class name from a Name node, considering use statements.
 */
function resolveClassName(PhpParser\Node\Name $name, array $useMap, ?string $namespace): string
{
    $nameStr = $name->toString();

    // Already fully qualified
    if ($name instanceof PhpParser\Node\Name\FullyQualified) {
        return $nameStr;
    }

    // Check use map (first part of the name)
    $parts = explode('\\', $nameStr);
    $firstPart = $parts[0];
    if (isset($useMap[$firstPart])) {
        $parts[0] = $useMap[$firstPart];
        return implode('\\', $parts);
    }

    // Prepend namespace
    if ($namespace !== null) {
        return $namespace . '\\' . $nameStr;
    }

    return $nameStr;
}
