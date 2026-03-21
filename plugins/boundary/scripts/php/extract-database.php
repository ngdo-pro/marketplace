<?php

declare(strict_types=1);

/**
 * Extracts database table and column usage from PHP source files.
 *
 * Detects:
 * - Doctrine ORM Entity attributes/annotations (#[ORM\Entity], #[ORM\Table], #[ORM\Column])
 * - Doctrine DBAL QueryBuilder usage
 * - Raw SQL in PDO/DBAL (->executeQuery(), ->prepare(), ->query())
 * - ZF1 Zend_Db_Table classes
 * - Repository method calls (find, findBy, findOneBy, createQueryBuilder)
 *
 * Usage: php extract-database.php --root /path/to/project --config boundary.config.json
 */

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

// ---------------------------------------------------------------------------
// Visitor: Doctrine Entity definitions (tables + columns)
// ---------------------------------------------------------------------------

class DoctrineEntityVisitor extends NodeVisitorAbstract
{
    private array $entities = [];
    private string $currentFile = '';
    private ?string $namespace = null;
    private array $useMap = [];
    private array $entityAttributes;

    public function __construct(array $preset)
    {
        $this->entityAttributes = $preset['database']['entity_attributes'] ?? [];
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->namespace = null;
        $this->useMap = [];
    }

    public function getEntities(): array
    {
        return $this->entities;
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

        // Check if this is a Doctrine entity
        $isEntity = false;
        $tableName = null;

        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();

                if ($this->isEntityAttribute($attrName, 'Entity')) {
                    $isEntity = true;
                }

                if ($this->isEntityAttribute($attrName, 'Table')) {
                    foreach ($attr->args as $arg) {
                        if ($arg->name === null || $arg->name->toString() === 'name') {
                            $tableName = extractStringValue($arg->value);
                        }
                    }
                }
            }
        }

        // Check docblock for @ORM\Entity and @ORM\Table
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $docText = $docComment->getText();
            if (preg_match('/@(?:ORM\\\\)?Entity/', $docText)) {
                $isEntity = true;
            }
            if (preg_match('/@(?:ORM\\\\)?Table\s*\(\s*(?:name\s*=\s*)?["\']([^"\']+)["\']/', $docText, $m)) {
                $tableName = $m[1];
            }
        }

        if (!$isEntity) {
            return null;
        }

        // Default table name from class name (Doctrine convention)
        if ($tableName === null) {
            $shortName = $node->name?->toString() ?? '';
            $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
        }

        // Extract columns from properties
        $columns = $this->extractColumns($node);

        $this->entities[] = [
            'table'   => $tableName,
            'entity'  => $className,
            'columns' => $columns,
            'proof'   => $this->currentFile . ':' . $node->getStartLine(),
        ];

        return null;
    }

    private function extractColumns(Node\Stmt\Class_ $class): array
    {
        $columns = [];

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Property) {
                continue;
            }

            $columnName = null;
            $columnType = null;
            $isColumn = false;
            $isId = false;

            // Check attributes
            foreach ($stmt->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    $attrName = $attr->name->toString();

                    if ($this->isEntityAttribute($attrName, 'Column')) {
                        $isColumn = true;
                        foreach ($attr->args as $arg) {
                            if ($arg->name === null || $arg->name->toString() === 'name') {
                                $columnName = extractStringValue($arg->value);
                            }
                            if ($arg->name?->toString() === 'type') {
                                $columnType = extractStringValue($arg->value);
                            }
                        }
                    }

                    if ($this->isEntityAttribute($attrName, 'Id')) {
                        $isId = true;
                    }
                }
            }

            // Check docblock
            $docComment = $stmt->getDocComment();
            if ($docComment !== null) {
                $docText = $docComment->getText();
                if (preg_match('/@(?:ORM\\\\)?Column/', $docText)) {
                    $isColumn = true;
                    if (preg_match('/name\s*=\s*["\']([^"\']+)["\']/', $docText, $m)) {
                        $columnName = $m[1];
                    }
                    if (preg_match('/type\s*=\s*["\']([^"\']+)["\']/', $docText, $m)) {
                        $columnType = $m[1];
                    }
                }
                if (preg_match('/@(?:ORM\\\\)?Id/', $docText)) {
                    $isId = true;
                }
            }

            if (!$isColumn && !$isId) {
                continue;
            }

            // Default column name from property name
            $propName = $stmt->props[0]->name->toString();
            if ($columnName === null) {
                $columnName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $propName));
            }

            $columns[] = array_filter([
                'name' => $columnName,
                'type' => $columnType,
                'is_id' => $isId ?: null,
            ], fn($v) => $v !== null);
        }

        return $columns;
    }

    private function isEntityAttribute(string $attrName, string $shortName): bool
    {
        // Direct match
        if ($attrName === $shortName || $attrName === "ORM\\$shortName") {
            return true;
        }

        foreach ($this->entityAttributes as $entityAttr) {
            if (str_ends_with($entityAttr, $shortName)
                && ($attrName === $entityAttr || ltrim($attrName, '\\') === $entityAttr)) {
                return true;
            }
        }

        // Check via use map
        $parts = explode('\\', $attrName);
        if (isset($this->useMap[$parts[0]])) {
            $resolved = $this->useMap[$parts[0]];
            $parts[0] = $resolved;
            $fullName = implode('\\', $parts);
            foreach ($this->entityAttributes as $entityAttr) {
                if ($fullName === $entityAttr) {
                    return true;
                }
            }
        }

        return false;
    }
}

// ---------------------------------------------------------------------------
// Visitor: SQL query detection (read vs write)
// ---------------------------------------------------------------------------

class SqlQueryVisitor extends NodeVisitorAbstract
{
    private array $queries = [];
    private string $currentFile = '';
    private array $readMethods;
    private array $writeMethods;
    private array $classConstants = [];

    public function __construct(array $preset)
    {
        $this->readMethods = $preset['database']['read_methods'] ?? [];
        $this->writeMethods = $preset['database']['write_methods'] ?? [];
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->classConstants = [];
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function enterNode(Node $node): ?int
    {
        // Track class constants for self::/static:: resolution
        if ($node instanceof Node\Stmt\Class_) {
            $this->classConstants = $this->collectClassConstants($node);
            return null;
        }

        if (!$node instanceof Node\Expr\MethodCall) {
            return null;
        }

        $methodName = $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : null;

        if ($methodName === null) return null;

        $access = null;
        if (in_array($methodName, $this->readMethods, true)) {
            $access = 'read';
        } elseif (in_array($methodName, $this->writeMethods, true)) {
            $access = 'write';
        }

        if ($access === null) return null;

        $tables = [];
        $unresolved = false;

        // For Doctrine methods like ->insert('table_name', ...) — check first for direct table arg
        if (in_array($methodName, ['insert', 'update', 'delete'], true) && isset($node->args[0])) {
            $arg = $node->args[0]->value;

            // Handle class constant reference directly (self::TABLE, static::TABLE)
            if ($arg instanceof Node\Expr\ClassConstFetch) {
                $resolved = $this->resolveClassConstant($arg);
                if ($resolved !== null) {
                    $tables = [$resolved];
                } else {
                    $constStr = extractStringValue($arg);
                    $tables = $constStr !== null ? [$constStr] : [];
                    $unresolved = true;
                }
            } else {
                $tableName = extractStringValue($arg);
                if ($tableName !== null) {
                    // Try to resolve if it looks like a constant reference string
                    $resolved = $this->resolveConstantString($tableName);
                    if ($resolved !== null) {
                        $tables = [$resolved];
                    } else {
                        $tables = [$tableName];
                        $unresolved = preg_match('/^(self|static)::/', $tableName) === 1;
                    }
                }
            }
        }

        // Try to extract SQL or table name from first argument (if no tables found yet)
        if (empty($tables) && isset($node->args[0])) {
            $sql = extractStringValue($node->args[0]->value);
            if ($sql !== null) {
                $tables = $this->extractTablesFromSql($sql);
            }
        }

        // Validate and emit
        if (!empty($tables)) {
            foreach ($tables as $table) {
                if (!$this->isValidTableName($table) && !$unresolved) {
                    continue; // Skip invalid table names (WHERE fragments, etc.)
                }

                $entry = [
                    'table'  => $table,
                    'access' => $access,
                    'method' => $methodName,
                    'proof'  => $this->currentFile . ':' . $node->getStartLine(),
                ];

                if ($unresolved) {
                    $entry['unresolved'] = true;
                }

                $this->queries[] = $entry;
            }
        }

        return null;
    }

    /**
     * Collect string constants from a class for self::/static:: resolution.
     */
    private function collectClassConstants(Node\Stmt\Class_ $class): array
    {
        $constants = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    if ($const->value instanceof Node\Scalar\String_) {
                        $constants[$const->name->toString()] = $const->value->value;
                    }
                }
            }
        }
        return $constants;
    }

    /**
     * Resolve a ClassConstFetch node (self::XXX, static::XXX) to its string value.
     */
    private function resolveClassConstant(Node\Expr\ClassConstFetch $node): ?string
    {
        $classRef = $node->class instanceof Node\Name ? $node->class->toString() : null;
        $constName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

        if ($constName === null) return null;

        if (in_array($classRef, ['self', 'static'], true)) {
            return $this->classConstants[$constName] ?? null;
        }

        return null;
    }

    /**
     * Try to resolve a constant reference string like "self::TABLE_NAME".
     */
    private function resolveConstantString(string $value): ?string
    {
        if (preg_match('/^(self|static)::(\w+)$/', $value, $m)) {
            return $this->classConstants[$m[2]] ?? null;
        }
        return null;
    }

    /**
     * Validate that a string is a plausible SQL table name.
     * Rejects WHERE fragments, expressions with =, spaces (except schema.table).
     */
    private function isValidTableName(string $name): bool
    {
        // Valid: alphanumeric + underscore, optionally schema.table
        return (bool) preg_match('/^[a-zA-Z_]\w*(\.[a-zA-Z_]\w*)?$/', $name);
    }

    private function extractTablesFromSql(string $sql): array
    {
        $tables = [];

        // FROM table
        if (preg_match_all('/\bFROM\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        // JOIN table
        if (preg_match_all('/\bJOIN\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        // INSERT INTO table
        if (preg_match_all('/\bINSERT\s+INTO\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        // UPDATE table
        if (preg_match_all('/\bUPDATE\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        // DELETE FROM table
        if (preg_match_all('/\bDELETE\s+FROM\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Filter out invalid table names
        $tables = array_filter($tables, [$this, 'isValidTableName']);

        return array_values(array_unique($tables));
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

fwrite(STDERR, "Scanning " . count($files) . " PHP files for database usage...\n");

$entityVisitor = new DoctrineEntityVisitor($preset);
$sqlVisitor    = new SqlQueryVisitor($preset);

foreach ($files as $fileInfo) {
    $stmts = parseFile($parser, $fileInfo['absolute']);
    if ($stmts === null) continue;

    $entityVisitor->setCurrentFile($fileInfo['relative']);
    $sqlVisitor->setCurrentFile($fileInfo['relative']);

    $traverser = new NodeTraverser();
    $traverser->addVisitor($entityVisitor);
    $traverser->addVisitor($sqlVisitor);
    $traverser->traverse($stmts);
}

outputJson([
    'entities'    => $entityVisitor->getEntities(),
    'raw_queries' => $sqlVisitor->getQueries(),
]);
