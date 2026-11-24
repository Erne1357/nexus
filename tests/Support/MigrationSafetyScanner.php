<?php

namespace Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Revisa el código de una migración y dice qué la haría incompatible con el release anterior (ADR-0033,
 * expand/contract). Solo mira `up()`. Una clase con `#[Contract(...)]` queda exenta: es una contracción declarada.
 */
final class MigrationSafetyScanner
{
    private const DESTRUCTIVE_METHODS = [
        'dropColumn', 'dropColumns', 'dropConstrainedForeignId', 'dropSoftDeletes', 'dropTimestamps',
        'dropRememberToken', 'dropMorphs', 'renameColumn', 'drop', 'dropIfExists', 'rename', 'change',
    ];

    private const COLUMN_TYPES = [
        'string', 'char', 'text', 'tinyText', 'mediumText', 'longText', 'integer', 'tinyInteger', 'smallInteger',
        'mediumInteger', 'bigInteger', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger',
        'unsignedMediumInteger', 'unsignedBigInteger', 'float', 'double', 'decimal', 'boolean', 'enum', 'set',
        'json', 'jsonb', 'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz', 'year',
        'binary', 'uuid', 'ulid', 'ipAddress', 'macAddress', 'foreignId', 'foreignUuid', 'foreignUlid', 'morphs',
        'uuidMorphs', 'ulidMorphs', 'geometry', 'geography', 'point',
    ];

    private const COLUMN_MODIFIERS_THAT_ALLOW = [
        'nullable', 'default', 'useCurrent', 'storedAs', 'virtualAs', 'generatedAs',
    ];

    private const RAW_SQL_METHODS = ['statement', 'unprepared'];

    private const RAW_SQL_PATTERNS = [
        '/\bDROP\b/i' => 'DROP',
        '/\bRENAME\b/i' => 'RENAME',
        '/\bALTER\b.*\bTYPE\b/is' => 'ALTER ... TYPE',
        '/\bSET\s+NOT\s+NULL\b/i' => 'SET NOT NULL',
    ];

    /**
     * @return list<string> Violaciones en español (operación y línea); vacía si la migración es segura.
     */
    public function scan(string $phpSource): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($phpSource) ?? [];
        } catch (Throwable $e) {
            return ['No se pudo analizar la migración: '.$e->getMessage()];
        }

        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($ast, Class_::class) as $class) {
            if ($this->hasContractAttribute($class)) {
                return [];
            }
        }

        $violations = [];

        foreach ($finder->findInstanceOf($ast, ClassMethod::class) as $method) {
            if ($method->name->toString() !== 'up' || $method->stmts === null) {
                continue;
            }

            array_push($violations, ...$this->destructiveCalls($method), ...$this->rawSql($method), ...$this->requiredColumns($method));
        }

        return $violations;
    }

    private function hasContractAttribute(Class_ $class): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Contract') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function destructiveCalls(ClassMethod $up): array
    {
        $violations = [];

        foreach ((new NodeFinder)->find($up->stmts, fn (Node $n) => ($n instanceof MethodCall || $n instanceof StaticCall) && $n->name instanceof Node\Identifier) as $call) {
            $name = $call->name->toString();

            if (in_array($name, self::DESTRUCTIVE_METHODS, true)) {
                $violations[] = "Línea {$call->getStartLine()}: {$name}() borra, renombra o cambia algo que el release anterior aún puede usar; "
                    ."va en un release posterior y se marca con #[Contract('…')].";
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function rawSql(ClassMethod $up): array
    {
        $violations = [];

        foreach ((new NodeFinder)->find($up->stmts, fn (Node $n) => ($n instanceof MethodCall || $n instanceof StaticCall) && $n->name instanceof Node\Identifier && in_array($n->name->toString(), self::RAW_SQL_METHODS, true)) as $call) {
            $sql = implode(' ', array_map(fn (String_ $s) => $s->value, (new NodeFinder)->findInstanceOf($call->args, String_::class)));
            $encapsed = (new NodeFinder)->findInstanceOf($call->args, Node\InterpolatedStringPart::class);
            $sql .= ' '.implode(' ', array_map(fn (Node\InterpolatedStringPart $p) => $p->value, $encapsed));

            foreach (self::RAW_SQL_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $sql) === 1) {
                    $violations[] = "Línea {$call->getStartLine()}: SQL crudo con {$label}; es una contracción y se marca con #[Contract('…')] en un release posterior.";
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function requiredColumns(ClassMethod $up): array
    {
        $violations = [];

        foreach ((new NodeFinder)->find($up->stmts, fn (Node $n) => $n instanceof MethodCall || $n instanceof StaticCall) as $call) {
            if (! $call instanceof StaticCall && ! $call instanceof MethodCall) {
                continue;
            }

            if (! $this->isSchemaTable($call)) {
                continue;
            }

            $closure = null;
            foreach ($call->args as $arg) {
                if ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction) {
                    $closure = $arg->value;
                }
            }

            if ($closure === null) {
                continue;
            }

            $statements = $closure instanceof Node\Expr\Closure ? $closure->stmts : [new Expression($closure->expr)];

            foreach ((new NodeFinder)->find($statements, fn (Node $n) => $n instanceof Expression) as $statement) {
                $expr = $statement->expr instanceof Assign ? $statement->expr->expr : $statement->expr;

                if (! $expr instanceof MethodCall) {
                    continue;
                }

                $names = [];
                $node = $expr;
                while ($node instanceof MethodCall) {
                    array_unshift($names, $node->name instanceof Node\Identifier ? $node->name->toString() : '');
                    $node = $node->var;
                }

                if (! $node instanceof Variable || ! in_array($names[0], self::COLUMN_TYPES, true)) {
                    continue;
                }

                if (array_intersect($names, self::COLUMN_MODIFIERS_THAT_ALLOW) === []) {
                    $violations[] = "Línea {$expr->getStartLine()}: la columna {$names[0]}() sobre una tabla existente debe ser nullable() o default(), "
                        .'porque el release anterior inserta sin conocerla.';
                }
            }
        }

        return $violations;
    }

    private function isSchemaTable(MethodCall|StaticCall $call): bool
    {
        return $call->name instanceof Node\Identifier && $call->name->toString() === 'table';
    }
}
