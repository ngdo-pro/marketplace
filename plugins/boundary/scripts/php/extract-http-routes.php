<?php

declare(strict_types=1);

/**
 * Extracts HTTP route definitions from PHP source files.
 *
 * Supports:
 * - Symfony Route attributes (#[Route('/path', methods: ['GET'])])
 * - Symfony Route annotations (@Route("/path", methods={"GET"}))
 * - Silex route definitions ($app->get('/path', callable))
 * - ZF1 controller conventions (controllers/ directory → action methods)
 *
 * Usage: php extract-http-routes.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

// ---------------------------------------------------------------------------
// Visitor: Symfony Route attributes & annotations
// ---------------------------------------------------------------------------

class SymfonyRouteVisitor extends NodeVisitorAbstract
{
    private array $routes = [];
    private string $currentFile = '';
    private string $currentClass = '';
    private string $classRoutePrefix = '';
    private array $classMethods = [];
    private array $routeAttributes;
    private array $useMap = [];
    private ?string $namespace = null;

    public function __construct(array $routeAttributes)
    {
        $this->routeAttributes = $routeAttributes;
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->currentClass = '';
        $this->classRoutePrefix = '';
        $this->classMethods = [];
        $this->useMap = [];
        $this->namespace = null;
    }

    public function getRoutes(): array
    {
        return $this->routes;
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

        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = ($this->namespace ? $this->namespace . '\\' : '')
                . ($node->name?->toString() ?? '');
            $this->classRoutePrefix = '';
            $this->classMethods = [];

            // Class-level Route attribute
            foreach ($node->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    if ($this->isRouteAttribute($attr)) {
                        $this->classRoutePrefix = $this->extractPath($attr) ?? '';
                    }
                }
            }
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $methodRoutes = [];

            foreach ($node->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    if ($this->isRouteAttribute($attr)) {
                        $path = $this->classRoutePrefix . ($this->extractPath($attr) ?? '');
                        $methods = $this->extractMethods($attr);
                        $name = $this->extractName($attr);

                        foreach ($methods ?: ['GET'] as $method) {
                            $methodRoutes[] = [
                                'method'  => strtoupper($method),
                                'path'    => $path,
                                'name'    => $name,
                                'handler' => $this->currentClass . '::' . $node->name->toString(),
                                'proof'   => $this->currentFile . ':' . $node->getStartLine(),
                            ];
                        }
                    }
                }
            }

            // Check docblock for @Route annotations
            $docComment = $node->getDocComment();
            if ($docComment !== null && empty($methodRoutes)) {
                $docRoutes = $this->parseDocBlockRoutes($docComment->getText(), $node);
                $methodRoutes = array_merge($methodRoutes, $docRoutes);
            }

            foreach ($methodRoutes as $route) {
                $this->routes[] = $route;
            }
        }

        return null;
    }

    private function isRouteAttribute(Node\Attribute $attr): bool
    {
        $name = $attr->name->toString();
        foreach ($this->routeAttributes as $routeAttr) {
            if ($name === $routeAttr || ltrim($name, '\\') === $routeAttr) {
                return true;
            }
        }
        // Check use map
        $parts = explode('\\', $name);
        if (isset($this->useMap[$parts[0]])) {
            $resolved = $this->useMap[$parts[0]];
            $parts[0] = $resolved;
            $fullName = implode('\\', $parts);
            foreach ($this->routeAttributes as $routeAttr) {
                if ($fullName === $routeAttr) {
                    return true;
                }
            }
        }
        return false;
    }

    private function extractPath(Node\Attribute $attr): ?string
    {
        // First positional argument or 'path' named argument
        foreach ($attr->args as $arg) {
            if ($arg->name === null || $arg->name->toString() === 'path') {
                return extractStringValue($arg->value);
            }
        }
        return null;
    }

    private function extractMethods(Node\Attribute $attr): array
    {
        foreach ($attr->args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === 'methods') {
                if ($arg->value instanceof Node\Expr\Array_) {
                    $methods = [];
                    foreach ($arg->value->items as $item) {
                        $val = extractStringValue($item->value);
                        if ($val !== null) {
                            $methods[] = $val;
                        }
                    }
                    return $methods;
                }
            }
        }
        return [];
    }

    private function extractName(Node\Attribute $attr): ?string
    {
        foreach ($attr->args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === 'name') {
                return extractStringValue($arg->value);
            }
        }
        return null;
    }

    private function parseDocBlockRoutes(string $docBlock, Node\Stmt\ClassMethod $method): array
    {
        $routes = [];
        // Match @Route("/path", methods={"GET","POST"}, name="route_name")
        if (preg_match_all('/@Route\s*\(\s*"([^"]+)"(?:.*?methods\s*=\s*\{([^}]+)\})?(?:.*?name\s*=\s*"([^"]+)")?/s', $docBlock, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = $this->classRoutePrefix . $match[1];
                $methods = [];
                if (!empty($match[2])) {
                    preg_match_all('/"([^"]+)"/', $match[2], $methodMatches);
                    $methods = $methodMatches[1] ?? [];
                }
                $routes[] = [
                    'method'  => !empty($methods) ? strtoupper(implode('|', $methods)) : 'GET',
                    'path'    => $path,
                    'name'    => $match[3] ?? null,
                    'handler' => $this->currentClass . '::' . $method->name->toString(),
                    'proof'   => $this->currentFile . ':' . $method->getStartLine(),
                ];
            }
        }
        return $routes;
    }
}

// ---------------------------------------------------------------------------
// Visitor: Silex route definitions ($app->get('/path', ...))
// ---------------------------------------------------------------------------

class SilexRouteVisitor extends NodeVisitorAbstract
{
    private array $routes = [];
    private string $currentFile = '';
    private array $routeMethods;
    private array $appVariables;

    public function __construct(array $preset)
    {
        $this->routeMethods = $preset['http_routes']['silex_route_methods']
            ?? ['get', 'post', 'put', 'patch', 'delete', 'options', 'match'];
        $this->appVariables = $preset['http_routes']['silex_app_variables']
            ?? ['app', 'this'];
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function enterNode(Node $node): ?int
    {
        if (!$node instanceof Node\Expr\MethodCall) {
            return null;
        }

        $methodName = $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : null;

        if ($methodName === null || !in_array($methodName, $this->routeMethods, true)) {
            return null;
        }

        // Check if called on $app or $this
        $varName = $this->resolveVariable($node->var);
        if ($varName === null || !in_array($varName, $this->appVariables, true)) {
            return null;
        }

        $path = isset($node->args[0]) ? extractStringValue($node->args[0]->value) : null;
        if ($path === null) {
            return null;
        }

        $handler = isset($node->args[1]) ? $this->resolveCallable($node->args[1]->value) : 'unknown';

        $this->routes[] = [
            'method'  => $methodName === 'match' ? 'ANY' : strtoupper($methodName),
            'path'    => $path,
            'name'    => null,
            'handler' => $handler,
            'proof'   => $this->currentFile . ':' . $node->getStartLine(),
        ];

        return null;
    }

    private function resolveVariable(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable) {
            return is_string($node->name) ? $node->name : null;
        }
        // $this->app
        if ($node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }
        return null;
    }

    private function resolveCallable(Node $node): string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ClassConstFetch) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : '?';
            $name = $node->name instanceof Node\Identifier ? $node->name->toString() : '?';
            return "$class::$name";
        }
        return 'closure';
    }
}

// ---------------------------------------------------------------------------
// Visitor: ZF1 controllers (action methods in *Controller classes)
// ---------------------------------------------------------------------------

class Zf1RouteVisitor extends NodeVisitorAbstract
{
    private array $routes = [];
    private string $currentFile = '';
    private string $currentClass = '';
    private bool $isController = false;

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->currentClass = '';
        $this->isController = false;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Class_) {
            $name = $node->name?->toString() ?? '';
            $this->currentClass = $name;
            $this->isController = str_ends_with($name, 'Controller');
        }

        if ($this->isController && $node instanceof Node\Stmt\ClassMethod) {
            $methodName = $node->name->toString();
            if (str_ends_with($methodName, 'Action')) {
                $action = substr($methodName, 0, -6); // Remove 'Action' suffix
                $path = $this->buildZf1Path($this->currentClass, $action);
                $method = $this->detectHttpMethod($node);

                $this->routes[] = [
                    'method'  => $method,
                    'path'    => $path,
                    'name'    => null,
                    'handler' => $this->currentClass . '::' . $methodName,
                    'proof'   => $this->currentFile . ':' . $node->getStartLine(),
                ];
            }
        }

        return null;
    }

    /**
     * Build ZF1 path from controller class name and action.
     * Betty_ArticleController::editAction → /betty/article/edit
     * IndexController::indexAction → /index/index
     * FooBarController::bazAction → /foo-bar/baz
     */
    private function buildZf1Path(string $className, string $action): string
    {
        $controllerRaw = str_replace('Controller', '', $className);
        // Split on underscore (ZF1 module separator: Module_ControllerName)
        $parts = explode('_', $controllerRaw);
        // Convert each part from CamelCase to kebab-case
        $pathParts = array_map(function (string $part): string {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $part));
        }, $parts);
        // Action: camelCase to kebab-case
        $actionPath = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $action));

        return '/' . implode('/', $pathParts) . '/' . $actionPath;
    }

    /**
     * Detect HTTP method from action body by looking for ZF1 request checks:
     * - $this->getRequest()->isPost() → POST
     * - $this->getRequest()->isGet() → GET
     * - $this->_forward() / $this->_redirect() → noted but keeps detected methods
     * Falls back to ANY if no method checks are found.
     */
    private function detectHttpMethod(Node\Stmt\ClassMethod $method): string
    {
        $methods = [];
        $this->walkForHttpMethods($method->stmts ?? [], $methods);

        if (empty($methods)) {
            return 'ANY';
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        return count($methods) === 1 ? $methods[0] : implode('|', $methods);
    }

    private function walkForHttpMethods(array $stmts, array &$methods): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node) {
                $this->walkNodeForHttpMethods($stmt, $methods);
            }
        }
    }

    private function walkNodeForHttpMethods(Node $node, array &$methods): void
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $methodName = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            if ($methodName !== null) {
                $httpMethod = match ($methodName) {
                    'isPost'    => 'POST',
                    'isGet'     => 'GET',
                    'isPut'     => 'PUT',
                    'isDelete'  => 'DELETE',
                    'isHead'    => 'HEAD',
                    'isOptions' => 'OPTIONS',
                    default     => null,
                };

                if ($httpMethod !== null) {
                    $methods[] = $httpMethod;
                }
            }
        }

        // Recurse into sub-nodes
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;
            if ($subNode instanceof Node) {
                $this->walkNodeForHttpMethods($subNode, $methods);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->walkNodeForHttpMethods($item, $methods);
                    }
                }
            }
        }
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

fwrite(STDERR, "Scanning " . count($files) . " PHP files for HTTP routes...\n");

$visitors = [];

// Always add Symfony visitor if preset has route attributes
if (!empty($preset['http_routes']['attributes'])) {
    $visitors[] = new SymfonyRouteVisitor($preset['http_routes']['attributes']);
}

// Add Silex visitor if preset has silex config
if (!empty($preset['http_routes']['silex_route_methods'])) {
    $visitors[] = new SilexRouteVisitor($preset);
}

// Add ZF1 visitor if preset has zf1 config
if (!empty($preset['http_routes']['zf1_controller_dirs'])) {
    $visitors[] = new Zf1RouteVisitor();
}

// If no framework-specific visitors, add Symfony as default (it handles annotations too)
if (empty($visitors) && !empty($preset['http_routes']['attributes'])) {
    $visitors[] = new SymfonyRouteVisitor($preset['http_routes']['attributes']);
}

$allRoutes = [];

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    foreach ($visitors as $visitor) {
        $visitor->setCurrentFile($fileInfo['relative']);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);
    }
}

foreach ($visitors as $visitor) {
    $allRoutes = array_merge($allRoutes, $visitor->getRoutes());
}

// Remove null names for cleaner output
$allRoutes = array_map(function (array $route): array {
    if ($route['name'] === null) {
        unset($route['name']);
    }
    return $route;
}, $allRoutes);

outputJson($allRoutes);
