<?php

namespace Snorky22\ApiForge\Dev\Doctor;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Compares a value decoded from an API response with an array shape of the descriptors (the PHPDoc types of
 * the generated code): missing keys, new keys, other types, values outside the literal values. Types it
 * doesn't know (aliases, classes) are not checked.
 */
final class ShapeChecker
{
    private readonly Lexer $lexer;
    private readonly TypeParser $typeParser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $this->typeParser = new TypeParser($config, new ConstExprParser($config));
    }

    /**
     * @return list<ShapeDifference> each difference once per path (list items share their path)
     */
    public function check(string $type, mixed $value, string $path = ''): array
    {
        $tokens = new TokenIterator($this->lexer->tokenize(trim($type)));
        $differences = [];
        foreach ($this->visit($this->typeParser->parse($tokens), $value, $path) as $difference) {
            $differences[$difference->kind.' '.$difference->path] ??= $difference;
        }

        return array_values($differences);
    }

    /**
     * @return list<ShapeDifference>
     */
    private function visit(TypeNode $node, mixed $value, string $path): array
    {
        return match (true) {
            $node instanceof ArrayShapeNode => $this->shape($node, $value, $path),
            $node instanceof UnionTypeNode => $this->union($node->types, $value, $path),
            $node instanceof NullableTypeNode => null === $value ? [] : $this->visit($node->type, $value, $path),
            $node instanceof GenericTypeNode => $this->generic($node, $value, $path),
            $node instanceof ArrayTypeNode => $this->items($node->type, $value, $path, false),
            $node instanceof ConstTypeNode => $this->literal($node, $value, $path),
            $node instanceof IdentifierTypeNode => $this->identifier($node->name, $value, $path),
            default => [],
        };
    }

    /**
     * @return list<ShapeDifference>
     */
    private function shape(ArrayShapeNode $node, mixed $value, string $path): array
    {
        if (!\is_array($value)) {
            return [$this->type((string) $node, $value, $path)];
        }

        $differences = [];
        $known = [];
        foreach ($node->items as $item) {
            $key = match (true) {
                $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                $item->keyName instanceof ConstExprIntegerNode => (int) $item->keyName->value,
                $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                default => null,
            };
            if (null === $key) {
                continue; // positional items of list{...}: not checked
            }
            $known[] = (string) $key;
            if (!\array_key_exists($key, $value)) {
                if (!$item->optional) {
                    $differences[] = new ShapeDifference(ShapeDifference::MISSING, self::join($path, (string) $key), 'missing (required by the descriptor)');
                }
                continue;
            }
            array_push($differences, ...$this->visit($item->valueType, $value[$key], self::join($path, (string) $key)));
        }

        if ($node->sealed) {
            foreach (array_diff(array_map('strval', array_keys($value)), $known) as $key) {
                $differences[] = new ShapeDifference(ShapeDifference::NEW, self::join($path, $key), \sprintf('new field (%s), not in the descriptor', self::describe($value[$key])));
            }
        }

        return $differences;
    }

    /**
     * A union matches when one of its types matches; otherwise, the differences with the closest type.
     *
     * @param list<TypeNode> $types
     *
     * @return list<ShapeDifference>
     */
    private function union(array $types, mixed $value, string $path): array
    {
        $best = null;
        $literals = [];
        foreach ($types as $type) {
            $differences = $this->visit($type, $value, $path);
            if ([] === $differences) {
                return [];
            }
            if ($type instanceof ConstTypeNode) {
                $literals[] = (string) $type;
                continue;
            }
            // A type of the value's kind (array, scalar) is closer than another
            $sameKind = \is_array($value) === self::isArrayType($type);
            if (null === $best || ($sameKind && !$best[0]) || ($sameKind === $best[0] && \count($differences) < \count($best[1]))) {
                $best = [$sameKind, $differences];
            }
        }

        // Descend into the closest array type; a scalar mismatch is reported against the whole union
        if (null !== $best && $best[0] && \is_array($value)) {
            return $best[1];
        }
        if ([] !== $literals && \count($literals) === \count($types)) {
            return [new ShapeDifference(ShapeDifference::VALUE, $path, \sprintf('%s is not one of %s', self::describe($value), implode('|', $literals)))];
        }

        return [$this->type(implode('|', array_map('strval', $types)), $value, $path)];
    }

    /**
     * @return list<ShapeDifference>
     */
    private function generic(GenericTypeNode $node, mixed $value, string $path): array
    {
        $name = strtolower($node->type->name);
        if (!\in_array($name, ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'], true)) {
            return [];
        }

        return $this->items(end($node->genericTypes) ?: new IdentifierTypeNode('mixed'), $value, $path, str_contains($name, 'list'), (string) $node);
    }

    /**
     * @return list<ShapeDifference>
     */
    private function items(TypeNode $itemType, mixed $value, string $path, bool $list, ?string $label = null): array
    {
        if (!\is_array($value) || ($list && !array_is_list($value))) {
            return [$this->type($label ?? $itemType.'[]', $value, $path)];
        }

        $differences = [];
        foreach ($value as $key => $item) {
            array_push($differences, ...$this->visit($itemType, $item, array_is_list($value) ? $path.'[]' : self::join($path, (string) $key)));
        }

        return $differences;
    }

    /**
     * @return list<ShapeDifference>
     */
    private function literal(ConstTypeNode $node, mixed $value, string $path): array
    {
        $expected = $node->constExpr;
        $matches = match (true) {
            $expected instanceof ConstExprStringNode => $value === $expected->value,
            $expected instanceof ConstExprIntegerNode => $value === (int) $expected->value,
            $expected instanceof ConstExprFloatNode => (\is_int($value) || \is_float($value)) && (float) $value === (float) $expected->value,
            $expected instanceof ConstExprTrueNode => true === $value,
            $expected instanceof ConstExprFalseNode => false === $value,
            $expected instanceof ConstExprNullNode => null === $value,
            default => true,
        };

        return $matches ? [] : [new ShapeDifference(ShapeDifference::VALUE, $path, \sprintf('%s is not %s', self::describe($value), $node))];
    }

    /**
     * @return list<ShapeDifference>
     */
    private function identifier(string $name, mixed $value, string $path): array
    {
        $matches = match (strtolower($name)) {
            'mixed' => true,
            'null' => null === $value,
            'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int' => \is_int($value),
            // JSON has one number type: 10 decodes to an int
            'float', 'double' => \is_int($value) || \is_float($value),
            'numeric' => is_numeric($value),
            'string', 'non-empty-string', 'numeric-string', 'lowercase-string' => \is_string($value),
            'bool', 'boolean' => \is_bool($value),
            'true' => true === $value,
            'false' => false === $value,
            'scalar' => \is_scalar($value),
            'array', 'non-empty-array', 'iterable', 'object' => \is_array($value),
            'list', 'non-empty-list' => \is_array($value) && array_is_list($value),
            default => true, // an alias or a class: not checked
        };

        return $matches ? [] : [$this->type($name, $value, $path)];
    }

    private function type(string $expected, mixed $value, string $path): ShapeDifference
    {
        $expected = preg_replace('/\s+/', ' ', $expected);

        return new ShapeDifference(ShapeDifference::TYPE, $path, \sprintf('%s, the descriptor expects %s', self::describe($value), mb_strimwidth($expected, 0, 60, '…')));
    }

    private static function isArrayType(TypeNode $type): bool
    {
        return $type instanceof ArrayShapeNode || $type instanceof ArrayTypeNode
            || ($type instanceof GenericTypeNode && \in_array(strtolower($type->type->name), ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'], true))
            || ($type instanceof IdentifierTypeNode && \in_array(strtolower($type->name), ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable', 'object'], true));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => "'".mb_strimwidth($value, 0, 30, '…')."'",
            \is_int($value), \is_float($value) => (string) $value,
            \is_array($value) => array_is_list($value) ? 'a list' : 'an object',
            default => get_debug_type($value),
        };
    }

    private static function join(string $path, string $key): string
    {
        return '' === $path ? $key : $path.'.'.$key;
    }
}
