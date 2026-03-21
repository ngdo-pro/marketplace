<?php

declare(strict_types=1);

/**
 * Extracts outbound messaging operations (publish/dispatch) from PHP source files.
 *
 * Detects:
 * - PhpAmqpLib basic_publish calls
 * - OldSoundRabbitMqBundle producer usage
 * - Symfony Messenger bus dispatch
 * - Exchange/routing key extraction from publish arguments
 *
 * Usage: php extract-messaging.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class MessagingVisitor extends NodeVisitorAbstract
{
    private array $publications = [];
    private string $currentFile = '';
    private ?string $namespace = null;
    private array $useMap = [];
    private array $preset;

    public function __construct(array $preset)
    {
        $this->preset = $preset;
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->namespace = null;
        $this->useMap = [];
    }

    public function getPublications(): array
    {
        return $this->publications;
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

        if (!$node instanceof Node\Expr\MethodCall) {
            return null;
        }

        $methodName = $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : null;

        if ($methodName === null) return null;

        // basic_publish on AMQPChannel
        $publishMethods = $this->preset['messaging']['publish_methods'] ?? [];
        if (in_array($methodName, $publishMethods, true)) {
            $this->detectAmqpPublish($node, $methodName);
        }

        // Symfony Messenger dispatch
        $dispatchMethods = $this->preset['messaging']['dispatch_methods'] ?? [];
        if (in_array($methodName, $dispatchMethods, true)) {
            $this->detectMessengerDispatch($node);
        }

        return null;
    }

    private function detectAmqpPublish(Node\Expr\MethodCall $node, string $methodName): void
    {
        // basic_publish($msg, $exchange, $routing_key)
        $exchange = null;
        $routingKey = null;

        if ($methodName === 'basic_publish') {
            $exchange = isset($node->args[1]) ? extractStringValue($node->args[1]->value) : null;
            $routingKey = isset($node->args[2]) ? extractStringValue($node->args[2]->value) : null;
        }

        // OldSoundRabbitMq Producer::publish($msgBody, $routingKey, $additionalProperties, $headers)
        if ($methodName === 'publish') {
            $varName = $this->resolveVarName($node->var);
            if ($varName !== null && preg_match('/producer|publisher|rabbit|amqp|bus/i', $varName)) {
                $routingKey = isset($node->args[1]) ? extractStringValue($node->args[1]->value) : null;
            } else {
                return; // Not a messaging publish
            }
        }

        $this->publications[] = array_filter([
            'broker'      => 'rabbitmq',
            'exchange'    => $exchange,
            'routing_key' => $routingKey,
            'method'      => $methodName,
            'proof'       => $this->currentFile . ':' . $node->getStartLine(),
        ], fn($v) => $v !== null);
    }

    private function detectMessengerDispatch(Node\Expr\MethodCall $node): void
    {
        // Check if called on a bus-like variable
        $varName = $this->resolveVarName($node->var);
        if ($varName === null) return;

        if (!preg_match('/bus|messenger|dispatcher|command|event|query/i', $varName)) {
            return;
        }

        // Extract message class from first argument
        $messageClass = null;
        if (isset($node->args[0])) {
            $arg = $node->args[0]->value;

            if ($arg instanceof Node\Expr\New_ && $arg->class instanceof Node\Name) {
                $messageClass = resolveClassName($arg->class, $this->useMap, $this->namespace);
            } elseif ($arg instanceof Node\Expr\Variable && is_string($arg->name)) {
                // Can't determine class from variable
                $messageClass = '$' . $arg->name;
            }
        }

        $this->publications[] = array_filter([
            'broker'  => 'messenger',
            'message' => $messageClass,
            'method'  => 'dispatch',
            'proof'   => $this->currentFile . ':' . $node->getStartLine(),
        ], fn($v) => $v !== null);
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

fwrite(STDERR, "Scanning " . count($files) . " PHP files for messaging operations...\n");

$visitor = new MessagingVisitor($preset);

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $visitor->setCurrentFile($fileInfo['relative']);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($stmts);
}

outputJson($visitor->getPublications());
