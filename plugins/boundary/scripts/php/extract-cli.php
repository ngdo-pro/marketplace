<?php

declare(strict_types=1);

/**
 * Extracts CLI command definitions from PHP source files.
 *
 * Detects:
 * - Symfony Console Command classes (extending Command)
 * - Command name from configure(), defaultName property, or AsCommand attribute
 * - ZF1 tool providers
 *
 * Usage: php extract-cli.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class CliCommandVisitor extends NodeVisitorAbstract
{
    private array $commands = [];
    private string $currentFile = '';
    private ?string $namespace = null;
    private array $useMap = [];
    private array $baseClasses;

    public function __construct(array $preset)
    {
        $this->baseClasses = $preset['cli']['command_base_classes'] ?? [];
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->namespace = null;
        $this->useMap = [];
    }

    public function getCommands(): array
    {
        return $this->commands;
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

        // Check if extends a command base class
        $isCommand = false;
        if ($node->extends !== null) {
            $parentClass = resolveClassName($node->extends, $this->useMap, $this->namespace);
            foreach ($this->baseClasses as $baseClass) {
                if ($parentClass === $baseClass || str_ends_with($parentClass, '\\' . basename(str_replace('\\', '/', $baseClass)))) {
                    $isCommand = true;
                    break;
                }
            }
        }

        // Check for AsCommand attribute
        $asCommandName = null;
        $asCommandDesc = null;
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();
                if ($attrName === 'AsCommand'
                    || str_ends_with($attrName, '\\AsCommand')) {
                    $isCommand = true;
                    // First arg = name
                    if (isset($attr->args[0])) {
                        $asCommandName = extractStringValue($attr->args[0]->value);
                    }
                    // Second arg or 'description' named arg
                    foreach ($attr->args as $arg) {
                        if ($arg->name?->toString() === 'description') {
                            $asCommandDesc = extractStringValue($arg->value);
                        }
                    }
                    if ($asCommandDesc === null && isset($attr->args[1]) && $attr->args[1]->name === null) {
                        $asCommandDesc = extractStringValue($attr->args[1]->value);
                    }
                }
            }
        }

        // Check class name suffix
        if (!$isCommand && str_ends_with($node->name?->toString() ?? '', 'Command')) {
            // Only if it has a configure() or execute() method
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod
                    && in_array($stmt->name->toString(), ['configure', 'execute'], true)) {
                    $isCommand = true;
                    break;
                }
            }
        }

        if (!$isCommand) {
            return null;
        }

        // Extract command name
        $commandName = $asCommandName
            ?? $this->extractFromDefaultName($node)
            ?? $this->extractFromConfigure($node)
            ?? $this->extractFromProperty($node, 'defaultName')
            ?? null;

        $description = $asCommandDesc
            ?? $this->extractDescriptionFromConfigure($node)
            ?? null;

        $this->commands[] = array_filter([
            'command'     => $commandName,
            'description' => $description,
            'handler'     => $className,
            'proof'       => $this->currentFile . ':' . $node->getStartLine(),
        ], fn($v) => $v !== null);

        return null;
    }

    private function extractFromDefaultName(Node\Stmt\Class_ $class): ?string
    {
        // Look for protected static $defaultName = 'app:command-name';
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === 'defaultName' && $prop->default !== null) {
                        return extractStringValue($prop->default);
                    }
                }
            }
        }
        return null;
    }

    private function extractFromConfigure(Node\Stmt\Class_ $class): ?string
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === 'configure') {
                return $this->findSetNameCall($stmt);
            }
        }
        return null;
    }

    private function extractDescriptionFromConfigure(Node\Stmt\Class_ $class): ?string
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === 'configure') {
                return $this->findSetDescriptionCall($stmt);
            }
        }
        return null;
    }

    private function findSetNameCall(Node\Stmt\ClassMethod $method): ?string
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\Expression
                && $stmt->expr instanceof Node\Expr\MethodCall) {
                $name = $this->findMethodInChain($stmt->expr, 'setName');
                if ($name !== null) return $name;
            }
        }
        return null;
    }

    private function findSetDescriptionCall(Node\Stmt\ClassMethod $method): ?string
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\Expression
                && $stmt->expr instanceof Node\Expr\MethodCall) {
                $desc = $this->findMethodInChain($stmt->expr, 'setDescription');
                if ($desc !== null) return $desc;
            }
        }
        return null;
    }

    private function findMethodInChain(Node\Expr\MethodCall $call, string $targetMethod): ?string
    {
        // Check current call
        if ($call->name instanceof Node\Identifier
            && $call->name->toString() === $targetMethod
            && isset($call->args[0])) {
            return extractStringValue($call->args[0]->value);
        }
        // Check if it's chained
        if ($call->var instanceof Node\Expr\MethodCall) {
            return $this->findMethodInChain($call->var, $targetMethod);
        }
        return null;
    }

    private function extractFromProperty(Node\Stmt\Class_ $class, string $propName): ?string
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === $propName && $prop->default !== null) {
                        return extractStringValue($prop->default);
                    }
                }
            }
        }
        return null;
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$args   = parseArgs($argv);
$config = loadConfig($args['config']);
$preset = loadMergedPreset($config['preset']);
$parser = createParser();
$files  = findPhpFiles($args['root'], $config['src'], $config['exclude']);

fwrite(STDERR, "Scanning " . count($files) . " PHP files for CLI commands...\n");

$visitor = new CliCommandVisitor($preset);

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $visitor->setCurrentFile($fileInfo['relative']);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($stmts);
}

outputJson($visitor->getCommands());
