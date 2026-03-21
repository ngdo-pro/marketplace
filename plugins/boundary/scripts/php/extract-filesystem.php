<?php

declare(strict_types=1);

/**
 * Extracts filesystem operations from PHP source files.
 *
 * Detects:
 * - Native PHP file functions (file_get_contents, file_put_contents, fopen, etc.)
 * - Symfony Filesystem component usage
 * - Flysystem adapter usage
 *
 * Usage: php extract-filesystem.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class FilesystemVisitor extends NodeVisitorAbstract
{
    private array $operations = [];
    private string $currentFile = '';
    private array $readFunctions;
    private array $writeFunctions;
    private array $filesystemClasses;

    public function __construct(array $preset)
    {
        $this->readFunctions = $preset['filesystem']['read_functions'] ?? [];
        $this->writeFunctions = $preset['filesystem']['write_functions'] ?? [];
        $this->filesystemClasses = $preset['filesystem']['filesystem_classes'] ?? [];
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
    }

    public function getOperations(): array
    {
        return $this->operations;
    }

    public function enterNode(Node $node): ?int
    {
        // Native function calls
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();

            $access = null;
            if (in_array($funcName, $this->readFunctions, true)) {
                $access = 'read';
            } elseif (in_array($funcName, $this->writeFunctions, true)) {
                $access = 'write';
            }

            if ($access !== null) {
                $path = isset($node->args[0]) ? extractStringValue($node->args[0]->value) : null;

                $this->operations[] = array_filter([
                    'function' => $funcName,
                    'path'     => $path,
                    'access'   => $access,
                    'proof'    => $this->currentFile . ':' . $node->getStartLine(),
                ], fn($v) => $v !== null);
            }
        }

        // Filesystem component method calls
        if ($node instanceof Node\Expr\MethodCall) {
            $methodName = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            if ($methodName === null) return null;

            // Detect Filesystem/Flysystem methods
            $readMethods = ['read', 'readStream', 'has', 'getMetadata', 'getSize',
                            'getMimetype', 'getTimestamp', 'getVisibility', 'listContents',
                            'exists'];
            $writeMethods = ['write', 'writeStream', 'put', 'putStream', 'update',
                             'rename', 'copy', 'delete', 'deleteDir', 'createDir',
                             'setVisibility', 'dumpFile', 'appendToFile', 'mkdir',
                             'remove', 'chmod', 'chown', 'chgrp', 'touch'];

            $access = null;
            if (in_array($methodName, $readMethods, true)) {
                $access = 'read';
            } elseif (in_array($methodName, $writeMethods, true)) {
                $access = 'write';
            }

            if ($access !== null) {
                // Check if called on a filesystem-like variable
                $varName = $this->resolveVarName($node->var);
                if ($varName !== null && preg_match('/filesystem|flysystem|storage|fs/i', $varName)) {
                    $path = isset($node->args[0]) ? extractStringValue($node->args[0]->value) : null;

                    $this->operations[] = array_filter([
                        'function' => $varName . '->' . $methodName,
                        'path'     => $path,
                        'access'   => $access,
                        'proof'    => $this->currentFile . ':' . $node->getStartLine(),
                    ], fn($v) => $v !== null);
                }
            }
        }

        return null;
    }

    private function resolveVarName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $node->name;
        }
        if ($node instanceof Node\Expr\PropertyFetch
            && $node->name instanceof Node\Identifier) {
            return $node->name->toString();
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

fwrite(STDERR, "Scanning " . count($files) . " PHP files for filesystem operations...\n");

$visitor = new FilesystemVisitor($preset);

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $visitor->setCurrentFile($fileInfo['relative']);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($stmts);
}

outputJson($visitor->getOperations());
