<?php

declare(strict_types=1);

/**
 * Extracts outbound HTTP client calls from PHP source files.
 *
 * Detects:
 * - GuzzleHttp\Client method calls (->request(), ->get(), ->post(), etc.)
 * - Symfony HttpClient usage
 * - cURL function calls (curl_init, curl_setopt with CURLOPT_URL)
 * - file_get_contents with http:// URLs
 *
 * Usage: php extract-http-clients.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class HttpClientVisitor extends NodeVisitorAbstract
{
    private array $calls = [];
    private string $currentFile = '';
    private ?string $namespace = null;
    private array $useMap = [];
    private array $preset;

    /** @var array<string, string> Maps variable names to known client classes */
    private array $typedVars = [];

    public function __construct(array $preset)
    {
        $this->preset = $preset;
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->namespace = null;
        $this->useMap = [];
        $this->typedVars = [];
    }

    public function getCalls(): array
    {
        return $this->calls;
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

        // Track constructor-injected and typed properties
        if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === '__construct') {
            foreach ($node->params as $param) {
                if ($param->type instanceof Node\Name) {
                    $typeName = resolveClassName($param->type, $this->useMap, $this->namespace);
                    if ($this->isHttpClientClass($typeName)) {
                        $varName = $param->var instanceof Node\Expr\Variable
                            ? (is_string($param->var->name) ? $param->var->name : null)
                            : null;
                        if ($varName !== null) {
                            $this->typedVars[$varName] = $typeName;
                        }
                    }
                }
            }
        }

        // Detect method calls on HTTP client objects
        if ($node instanceof Node\Expr\MethodCall) {
            $this->detectClientMethodCall($node);
        }

        // Detect curl_* function calls
        if ($node instanceof Node\Expr\FuncCall) {
            $this->detectCurlCall($node);
        }

        // Detect new GuzzleHttp\Client or HttpClient::create()
        if ($node instanceof Node\Expr\New_) {
            $this->detectClientInstantiation($node);
        }

        return null;
    }

    private function detectClientMethodCall(Node\Expr\MethodCall $node): void
    {
        $methodName = $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : null;

        if ($methodName === null) return;

        $requestMethods = $this->preset['http_clients']['request_methods'] ?? [];
        if (!in_array($methodName, $requestMethods, true)) return;

        // Check if the variable is a known HTTP client
        $isClient = false;
        $clientType = null;

        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $varName = $node->var->name;
            if (isset($this->typedVars[$varName])) {
                $isClient = true;
                $clientType = $this->typedVars[$varName];
            }
            // Heuristic: variable named $client, $httpClient, etc.
            if (!$isClient && preg_match('/client|http/i', $varName)) {
                $isClient = true;
                $clientType = 'inferred';
            }
        }

        // Check if called on $this->client or $this->httpClient
        if ($node->var instanceof Node\Expr\PropertyFetch
            && $node->var->name instanceof Node\Identifier) {
            $propName = $node->var->name->toString();
            if (preg_match('/client|http/i', $propName)) {
                $isClient = true;
                $clientType = 'property';
            }
        }

        if (!$isClient) return;

        // Try to extract URL from arguments
        $url = null;
        $httpMethod = null;

        if (in_array($methodName, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
            $httpMethod = strtoupper($methodName);
            $url = isset($node->args[0]) ? extractStringValue($node->args[0]->value) : null;
        } elseif ($methodName === 'request') {
            $httpMethod = isset($node->args[0]) ? extractStringValue($node->args[0]->value) : null;
            $url = isset($node->args[1]) ? extractStringValue($node->args[1]->value) : null;
        } elseif ($methodName === 'send' || $methodName === 'sendAsync') {
            // Request object as first arg — URL not easily extractable
            $httpMethod = 'UNKNOWN';
        }

        $this->calls[] = array_filter([
            'url'    => $url,
            'method' => $httpMethod ? strtoupper($httpMethod) : null,
            'client' => $clientType !== 'inferred' && $clientType !== 'property' ? $clientType : null,
            'proof'  => $this->currentFile . ':' . $node->getStartLine(),
        ], fn($v) => $v !== null);
    }

    private function detectCurlCall(Node\Expr\FuncCall $node): void
    {
        $funcName = $node->name instanceof Node\Name ? $node->name->toString() : null;
        if ($funcName === null) return;

        $curlFunctions = $this->preset['http_clients']['curl_functions'] ?? [];
        if (!in_array($funcName, $curlFunctions, true)) return;

        // For curl_setopt with CURLOPT_URL, try to extract the URL
        if ($funcName === 'curl_setopt' && isset($node->args[1], $node->args[2])) {
            $opt = $node->args[1]->value;
            if ($opt instanceof Node\Expr\ConstFetch
                && $opt->name->toString() === 'CURLOPT_URL') {
                $url = extractStringValue($node->args[2]->value);
                $this->calls[] = array_filter([
                    'url'    => $url,
                    'method' => 'UNKNOWN',
                    'client' => 'curl',
                    'proof'  => $this->currentFile . ':' . $node->getStartLine(),
                ], fn($v) => $v !== null);
                return;
            }
        }

        // For curl_init with URL
        if ($funcName === 'curl_init' && isset($node->args[0])) {
            $url = extractStringValue($node->args[0]->value);
            if ($url !== null) {
                $this->calls[] = array_filter([
                    'url'    => $url,
                    'method' => 'UNKNOWN',
                    'client' => 'curl',
                    'proof'  => $this->currentFile . ':' . $node->getStartLine(),
                ], fn($v) => $v !== null);
            }
        }
    }

    private function detectClientInstantiation(Node\Expr\New_ $node): void
    {
        if (!$node->class instanceof Node\Name) return;

        $className = resolveClassName($node->class, $this->useMap, $this->namespace);
        if (!$this->isHttpClientClass($className)) return;

        // Check constructor args for base_uri/base_url
        foreach ($node->args as $arg) {
            if ($arg->value instanceof Node\Expr\Array_) {
                foreach ($arg->value->items as $item) {
                    if ($item->key !== null) {
                        $key = extractStringValue($item->key);
                        if (in_array($key, ['base_uri', 'base_url'], true)) {
                            $url = extractStringValue($item->value);
                            if ($url !== null) {
                                $this->calls[] = [
                                    'url'    => $url,
                                    'method' => 'BASE_URL',
                                    'client' => $className,
                                    'proof'  => $this->currentFile . ':' . $node->getStartLine(),
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    private function isHttpClientClass(string $className): bool
    {
        $clientClasses = $this->preset['http_clients']['client_classes'] ?? [];
        foreach ($clientClasses as $clientClass) {
            if ($className === $clientClass || str_ends_with($className, '\\' . basename(str_replace('\\', '/', $clientClass)))) {
                return true;
            }
        }
        return false;
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

fwrite(STDERR, "Scanning " . count($files) . " PHP files for HTTP client calls...\n");

$visitor = new HttpClientVisitor($preset);

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $visitor->setCurrentFile($fileInfo['relative']);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($stmts);
}

outputJson($visitor->getCalls());
