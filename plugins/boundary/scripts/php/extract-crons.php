<?php

declare(strict_types=1);

/**
 * Extracts cron job definitions.
 *
 * Detects:
 * - Crontab files (crontab, cron.txt)
 * - Symfony Scheduler attributes (#[AsCronTask])
 * - Supervisor/systemd timer configs
 * - YAML/config-based cron definitions
 *
 * Usage: php extract-crons.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

// ---------------------------------------------------------------------------
// Visitor: AsCronTask / AsPeriodicTask attributes
// ---------------------------------------------------------------------------

class CronAttributeVisitor extends NodeVisitorAbstract
{
    private array $crons = [];
    private string $currentFile = '';
    private ?string $namespace = null;
    private array $useMap = [];

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->namespace = null;
        $this->useMap = [];
    }

    public function getCrons(): array
    {
        return $this->crons;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $this->useMap[$alias] = $use->name->toString();
            }
        }

        if (!$node instanceof Node\Stmt\Class_) {
            return null;
        }

        $className = ($this->namespace ? $this->namespace . '\\' : '')
            . ($node->name?->toString() ?? '');

        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();
                if (str_contains($attrName, 'CronTask')
                    || str_contains($attrName, 'PeriodicTask')
                    || str_contains($attrName, 'AsSchedule')) {

                    $schedule = null;
                    foreach ($attr->args as $arg) {
                        if ($arg->name === null || $arg->name->toString() === 'expression'
                            || $arg->name->toString() === 'schedule') {
                            $schedule = extractStringValue($arg->value);
                            if ($schedule !== null) break;
                        }
                    }

                    $this->crons[] = array_filter([
                        'schedule' => $schedule,
                        'command'  => $className,
                        'source'   => 'attribute',
                        'proof'    => $this->currentFile . ':' . $node->getStartLine(),
                    ], fn($v) => $v !== null);
                }
            }
        }

        return null;
    }
}

// ---------------------------------------------------------------------------
// Parser: crontab files
// ---------------------------------------------------------------------------

function parseCrontabFile(string $filePath, string $relativePath): array
{
    $crons = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        // Skip comments and empty lines
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Skip variable assignments (KEY=VALUE)
        if (preg_match('/^\w+=/', $line)) {
            continue;
        }

        // Standard crontab: 5 time fields + command
        // Also handle @reboot, @daily, @hourly, etc.
        if (preg_match('/^(@\w+|(?:[^\s]+\s+){5})(.+)$/', $line, $matches)) {
            $schedule = trim($matches[1]);
            $command = trim($matches[2]);

            $crons[] = [
                'schedule' => $schedule,
                'command'  => $command,
                'source'   => 'crontab',
                'proof'    => $relativePath . ':' . ($lineNum + 1),
            ];
        }
    }

    return $crons;
}

// ---------------------------------------------------------------------------
// Parser: YAML cron config (basic pattern matching, not full YAML parser)
// ---------------------------------------------------------------------------

function parseYamlCronConfig(string $filePath, string $relativePath): array
{
    $crons = [];
    $content = file_get_contents($filePath);
    if ($content === false) return [];

    $lines = explode("\n", $content);
    $currentTask = null;
    $currentSchedule = null;
    $currentCommand = null;

    foreach ($lines as $lineNum => $line) {
        // Look for schedule/cron expressions
        if (preg_match('/^\s+(?:schedule|cron|expression):\s*[\'"]?([^\'"\s#]+)/', $line, $m)) {
            $currentSchedule = $m[1];
        }
        if (preg_match('/^\s+(?:command|task):\s*[\'"]?([^\'"\s#]+)/', $line, $m)) {
            $currentCommand = $m[1];
        }
        // Task name (top-level key under schedules/crons)
        if (preg_match('/^(\s{2,4})(\w[\w.-]+):/', $line, $m)) {
            // Save previous task
            if ($currentTask !== null && ($currentSchedule || $currentCommand)) {
                $crons[] = array_filter([
                    'schedule' => $currentSchedule,
                    'command'  => $currentCommand ?? $currentTask,
                    'source'   => 'yaml',
                    'proof'    => $relativePath . ':' . ($lineNum + 1),
                ], fn($v) => $v !== null);
            }
            $currentTask = $m[2];
            $currentSchedule = null;
            $currentCommand = null;
        }
    }

    // Save last task
    if ($currentTask !== null && ($currentSchedule || $currentCommand)) {
        $crons[] = array_filter([
            'schedule' => $currentSchedule,
            'command'  => $currentCommand ?? $currentTask,
            'source'   => 'yaml',
            'proof'    => $relativePath . ':' . count($lines),
        ], fn($v) => $v !== null);
    }

    return $crons;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$args   = parseArgs($argv);
$config = loadConfig($args['config']);
$preset = loadMergedPreset($config['preset']);
$parser = createParser();
$root   = $args['root'];

$allCrons = [];

// 1. Scan PHP files for cron attributes
$files = findPhpFiles($root, $config['src'], $config['exclude']);
fwrite(STDERR, "Scanning " . count($files) . " PHP files for cron definitions...\n");

$visitor = new CronAttributeVisitor();

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $visitor->setCurrentFile($fileInfo['relative']);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($stmts);
}
$allCrons = array_merge($allCrons, $visitor->getCrons());

// 2. Parse crontab files
$crontabFiles = $preset['cron']['config_files'] ?? [];
foreach ($crontabFiles as $file) {
    $fullPath = $root . '/' . $file;
    if (!file_exists($fullPath)) continue;

    if (str_ends_with($file, '.yaml') || str_ends_with($file, '.yml')) {
        $allCrons = array_merge($allCrons, parseYamlCronConfig($fullPath, $file));
    } else {
        $allCrons = array_merge($allCrons, parseCrontabFile($fullPath, $file));
    }
}

// 3. Scan config directories for additional cron-related files
$cronDirs = $preset['cron']['config_dirs'] ?? [];
foreach ($cronDirs as $dir) {
    $fullDir = $root . '/' . $dir;
    if (!is_dir($fullDir)) continue;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $f) {
        $name = $f->getFilename();
        $relativePath = ltrim(str_replace($root, '', $f->getPathname()), '/');

        // Only files with cron/scheduler in name
        if (!preg_match('/cron|scheduler|schedule/i', $name)) {
            continue;
        }

        if (str_ends_with($name, '.yaml') || str_ends_with($name, '.yml')) {
            $allCrons = array_merge($allCrons, parseYamlCronConfig($f->getPathname(), $relativePath));
        }
    }
}

outputJson($allCrons);
