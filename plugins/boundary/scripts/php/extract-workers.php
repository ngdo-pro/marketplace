<?php

declare(strict_types=1);

/**
 * Extracts message consumer/worker definitions from PHP source files.
 *
 * Detects:
 * - Classes implementing consumer interfaces (Symfony Messenger, OldSoundRabbitMq)
 * - Classes with consumer/handler naming conventions
 * - Queue name from class constants, config references, or method signatures
 *
 * Usage: php extract-workers.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class WorkerVisitor extends NodeVisitorAbstract
{
    private array $workers = [];
    private string $currentFile = '';
    private ?string $namespace = null;
    private array $useMap = [];
    private array $consumerInterfaces;
    private array $consumerSuffixes;
    private array $handlerMethods;

    private array $excludedNamespaceSegments;
    private array $excludedParentClasses;

    public function __construct(array $preset)
    {
        $this->consumerInterfaces = $preset['workers']['consumer_interfaces'] ?? [];
        $this->consumerSuffixes = $preset['workers']['consumer_suffixes'] ?? ['Consumer', 'Handler'];
        $this->handlerMethods = $preset['workers']['queue_methods'] ?? ['execute', 'handle', '__invoke', 'consume'];
        $this->excludedNamespaceSegments = $preset['workers']['excluded_namespaces']
            ?? ['ControllerPlugin', 'Initialization', 'Log'];
        $this->excludedParentClasses = $preset['workers']['excluded_parent_classes']
            ?? ['Zend_Controller_Plugin_Abstract', 'Zend_Log_Writer_Abstract', 'Zend_Log_Filter_Abstract',
                'Monolog\\Handler\\AbstractHandler', 'Monolog\\Handler\\AbstractProcessingHandler'];
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->namespace = null;
        $this->useMap = [];
    }

    public function getWorkers(): array
    {
        return $this->workers;
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

        $isConsumer = false;
        $implementedInterface = null;

        // Check implemented interfaces
        foreach ($node->implements as $interface) {
            $resolved = resolveClassName($interface, $this->useMap, $this->namespace);
            foreach ($this->consumerInterfaces as $consumerInterface) {
                if ($resolved === $consumerInterface || str_ends_with($resolved, '\\' . $consumerInterface)) {
                    $isConsumer = true;
                    $implementedInterface = $consumerInterface;
                    break 2;
                }
            }
        }

        // Check class name suffix (requires additional evidence)
        if (!$isConsumer) {
            $shortName = $node->name?->toString() ?? '';
            foreach ($this->consumerSuffixes as $suffix) {
                if (str_ends_with($shortName, $suffix)) {
                    $isConsumer = true;
                    break;
                }
            }

            // Suffix-only match: require additional evidence
            if ($isConsumer) {
                $isConsumer = $this->hasConsumerEvidence($node, $className);
            }
        }

        if (!$isConsumer) {
            return null;
        }

        // Apply exclusions (even for interface-based matches in edge cases)
        if ($this->isExcluded($node, $className)) {
            return null;
        }

        // Find the handler method and extract queue info
        $queueName = $this->extractQueueName($node);
        $handlerMethod = $this->findHandlerMethod($node);
        $messageType = $this->extractMessageType($node);

        $this->workers[] = [
            'queue'     => $queueName,
            'message'   => $messageType,
            'handler'   => $className . ($handlerMethod ? '::' . $handlerMethod : ''),
            'interface' => $implementedInterface,
            'proof'     => $this->currentFile . ':' . $node->getStartLine(),
        ];

        return null;
    }

    /**
     * Check if a suffix-matched class has evidence of being a real consumer:
     * - Has a handler method with a class-typed parameter (message/envelope)
     * - OR is in a Consumer/ or Worker/ namespace/directory
     */
    private function hasConsumerEvidence(Node\Stmt\Class_ $node, string $className): bool
    {
        // Check if in a Consumer/Worker namespace or directory
        if (preg_match('/[\\\\\/](?:Consumer|Worker|MessageHandler)s?[\\\\\/]/i', $className)
            || preg_match('/[\\\\\/](?:Consumer|Worker|MessageHandler)s?[\\\\\/]/i', $this->currentFile)) {
            return true;
        }

        // Check if a handler method has a class-typed parameter
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $name = $stmt->name->toString();
            if (!in_array($name, $this->handlerMethods, true)) {
                continue;
            }
            foreach ($stmt->params as $param) {
                if ($param->type instanceof Node\Name) {
                    // Has a class type hint — strong evidence of message handler
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the class should be excluded based on namespace or parent class.
     */
    private function isExcluded(Node\Stmt\Class_ $node, string $className): bool
    {
        // Check excluded namespace segments
        foreach ($this->excludedNamespaceSegments as $segment) {
            // PSR-4: Foo\Log\SomeHandler
            if (str_contains($className, '\\' . $segment . '\\')) {
                return true;
            }
            // ZF1 underscore pseudo-namespace: Foo_Log_SomeHandler
            if (str_contains($className, '_' . $segment . '_')) {
                return true;
            }
        }

        // Check excluded parent classes
        if ($node->extends !== null) {
            $parentClass = resolveClassName($node->extends, $this->useMap, $this->namespace);
            foreach ($this->excludedParentClasses as $excluded) {
                if ($parentClass === $excluded || str_ends_with($parentClass, '\\' . $excluded)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractQueueName(Node\Stmt\Class_ $class): ?string
    {
        // Look for QUEUE_NAME or QUEUE class constants
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $name = $const->name->toString();
                    if (in_array($name, ['QUEUE_NAME', 'QUEUE', 'ROUTING_KEY', 'EXCHANGE'])) {
                        return extractStringValue($const->value);
                    }
                }
            }
        }

        // Look for getSubscribedEvents or static $handledMessages
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod
                && $stmt->name->toString() === 'getHandledMessages') {
                // Try to extract from return statement
                return $this->extractFromReturn($stmt);
            }
        }

        return null;
    }

    private function findHandlerMethod(Node\Stmt\Class_ $class): ?string
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $name = $stmt->name->toString();
                if (in_array($name, $this->handlerMethods, true)) {
                    return $name;
                }
            }
        }
        return null;
    }

    private function extractMessageType(Node\Stmt\Class_ $class): ?string
    {
        // Look for __invoke or handle method with a typed parameter
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod
                && in_array($stmt->name->toString(), ['__invoke', 'handle', 'execute'], true)) {
                foreach ($stmt->params as $param) {
                    if ($param->type instanceof Node\Name) {
                        return resolveClassName($param->type, $this->useMap, $this->namespace);
                    }
                }
            }
        }
        return null;
    }

    private function extractFromReturn(Node\Stmt\ClassMethod $method): ?string
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                $val = extractStringValue($stmt->expr);
                if ($val !== null) return $val;
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

fwrite(STDERR, "Scanning " . count($files) . " PHP files for workers/consumers...\n");

$visitor   = new WorkerVisitor($preset);
$allWorkers = [];

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $visitor->setCurrentFile($fileInfo['relative']);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($stmts);
}

$workers = $visitor->getWorkers();

// Clean up null fields
$workers = array_map(function (array $w): array {
    return array_filter($w, fn($v) => $v !== null);
}, $workers);

outputJson($workers);
