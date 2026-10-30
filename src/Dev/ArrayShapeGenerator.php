<?php

namespace App\Dev;

/**
 * Infers a PHPDoc array shape (PHPStan / Psalm / PhpStorm syntax) from example data.
 *
 * The data must be decoded with JSON objects kept as \stdClass, so that "{}" and "[]"
 * can be told apart. Elements of a list are merged into a single shape: keys missing
 * from some elements become optional, and differing value types become unions.
 */
final class ArrayShapeGenerator
{
    private const INDENT = '    ';
    private const SCALAR_ORDER = ['int', 'float', 'string', 'bool'];

    public function docBlock(mixed $data, string $tag = 'var'): string
    {
        $lines = explode("\n", '@'.$tag.' '.$this->generate($data));

        return "/**\n".implode("\n", array_map(static fn (string $line) => rtrim(' * '.$line), $lines))."\n */";
    }

    /**
     * @param bool $optionalKeys make the top-level keys optional (e.g. for request attributes)
     */
    public function generate(mixed $data, bool $optionalKeys = false): string
    {
        return $this->generateMerged([$data], $optionalKeys);
    }

    /**
     * Generates a single shape describing all the given items, e.g. the elements of a list.
     *
     * @param list<mixed> $items
     */
    public function generateMerged(array $items, bool $optionalKeys = false): string
    {
        $type = null;
        foreach ($items as $item) {
            $type = $this->merge($type, $this->infer($item));
        }
        if (null === $type) {
            return 'mixed';
        }

        if ($optionalKeys && null !== $type['shape']) {
            foreach ($type['shape']['keys'] as &$entry) {
                $entry['count'] = 0;
            }
            unset($entry);
        }

        return $this->render($type, 0);
    }

    /**
     * @return array{scalars: array<string, true>, shape: ?array{count: int, keys: array<string, array{count: int, type: array}>}, list: ?array{item: ?array}}
     */
    private function infer(mixed $value): array
    {
        $type = ['scalars' => [], 'shape' => null, 'list' => null];

        if ($value instanceof \stdClass) {
            $keys = [];
            foreach (get_object_vars($value) as $key => $item) {
                $keys[(string) $key] = ['count' => 1, 'type' => $this->infer($item)];
            }
            $type['shape'] = ['count' => 1, 'keys' => $keys];
        } elseif (\is_array($value)) {
            $item = null;
            foreach ($value as $element) {
                $item = $this->merge($item, $this->infer($element));
            }
            $type['list'] = ['item' => $item];
        } else {
            $type['scalars'][match (true) {
                null === $value => 'null',
                \is_bool($value) => 'bool',
                \is_int($value) => 'int',
                \is_float($value) => 'float',
                default => 'string',
            }] = true;
        }

        return $type;
    }

    private function merge(?array $a, array $b): array
    {
        if (null === $a) {
            return $b;
        }

        $merged = ['scalars' => $a['scalars'] + $b['scalars'], 'shape' => $a['shape'] ?? $b['shape'], 'list' => $a['list'] ?? $b['list']];

        if (null !== $a['shape'] && null !== $b['shape']) {
            $keys = $a['shape']['keys'];
            foreach ($b['shape']['keys'] as $key => $entry) {
                $keys[$key] = isset($keys[$key])
                    ? ['count' => $keys[$key]['count'] + $entry['count'], 'type' => $this->merge($keys[$key]['type'], $entry['type'])]
                    : $entry;
            }
            $merged['shape'] = ['count' => $a['shape']['count'] + $b['shape']['count'], 'keys' => $keys];
        }

        if (null !== $a['list'] && null !== $b['list']) {
            $merged['list'] = ['item' => null === $b['list']['item'] ? $a['list']['item'] : $this->merge($a['list']['item'], $b['list']['item'])];
        }

        return $merged;
    }

    private function render(array $type, int $level): string
    {
        $members = [];
        if (null !== $type['shape']) {
            $members[] = $this->renderShape($type['shape'], $level);
        }
        if (null !== $type['list']) {
            $members[] = 'list<'.(null === $type['list']['item'] ? 'mixed' : $this->render($type['list']['item'], $level)).'>';
        }
        foreach (self::SCALAR_ORDER as $scalar) {
            if (isset($type['scalars'][$scalar])) {
                $members[] = $scalar;
            }
        }

        // A value that is only ever null in the example tells nothing about its real type.
        if ([] === $members) {
            return 'mixed';
        }

        if (isset($type['scalars']['null'])) {
            if (1 === \count($members) && \in_array($members[0], self::SCALAR_ORDER, true)) {
                return '?'.$members[0];
            }
            $members[] = 'null';
        }

        return implode('|', $members);
    }

    private function renderShape(array $shape, int $level): string
    {
        if ([] === $shape['keys']) {
            return 'array<string, mixed>';
        }

        $indent = str_repeat(self::INDENT, $level + 1);
        $lines = [];
        foreach ($shape['keys'] as $key => $entry) {
            $optional = $entry['count'] < $shape['count'] ? '?' : '';
            $lines[] = $indent.$this->renderKey($key).$optional.': '.$this->render($entry['type'], $level + 1).',';
        }

        return "array{\n".implode("\n", $lines)."\n".str_repeat(self::INDENT, $level).'}';
    }

    private function renderKey(string $key): string
    {
        if (preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*|0|[1-9][0-9]*)$/', $key)) {
            return $key;
        }

        return "'".addcslashes($key, "'\\")."'";
    }
}
