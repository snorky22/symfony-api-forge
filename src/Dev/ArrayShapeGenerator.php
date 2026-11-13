<?php

namespace Snorky22\ApiForge\Dev;

use Snorky22\ApiForge\Dev\Api\Docs\DocsEntry;

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
     * @param bool                     $optionalKeys make the top-level keys optional (e.g. for request attributes)
     * @param array<string, DocsEntry> $docs         what the documentation says of the fields, by path (data[].name):
     *                                               it decides which keys are optional, which values are null, the
     *                                               literal values of an enum, and the type of a field the example
     *                                               leaves unknown
     */
    public function generate(mixed $data, bool $optionalKeys = false, array $docs = []): string
    {
        return $this->generateMerged([$data], $optionalKeys, $docs);
    }

    /**
     * Generates a single shape describing all the given items, e.g. the elements of a list.
     *
     * @param list<mixed>              $items
     * @param array<string, DocsEntry> $docs
     */
    public function generateMerged(array $items, bool $optionalKeys = false, array $docs = []): string
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

        return $this->render($type, 0, '', $docs);
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

    /**
     * @param array<string, DocsEntry> $docs
     */
    private function render(array $type, int $level, string $path, array $docs): string
    {
        $entry = $docs[$path] ?? null;
        $members = [];
        if (null !== $type['shape']) {
            $members[] = $this->renderShape($type['shape'], $level, $path, $docs);
        }
        if (null !== $type['list']) {
            $members[] = 'list<'.(null === $type['list']['item'] ? 'mixed' : $this->render($type['list']['item'], $level, $path.'[]', $docs)).'>';
        }
        foreach (self::SCALAR_ORDER as $scalar) {
            if (isset($type['scalars'][$scalar])) {
                $members[] = $scalar;
            }
        }

        $nullable = isset($type['scalars']['null']) || (null !== $entry && $entry->nullable);

        // What the example leaves unknown (only null, or an empty list for an object) is the documented type
        if (null !== $entry && ([] === $members || (['list<mixed>'] === $members && ('object' === $entry->type || (null !== $entry->itemType && 'array' === $entry->type))))) {
            $members = [self::documented($entry)];
        } elseif (null !== $entry && null !== $entry->values && ['string'] === $members && 'string' === $entry->type) {
            // The literal values of the docs, when the page lists them all
            $members = array_map(static fn (string $value) => "'".addcslashes($value, "'\\")."'", $entry->values);
        }

        // A value that is only ever null in the example tells nothing about its real type.
        if ([] === $members) {
            return 'mixed';
        }

        if ($nullable) {
            if (1 === \count($members) && (\in_array($members[0], self::SCALAR_ORDER, true))) {
                return '?'.$members[0];
            }
            $members[] = 'null';
        }

        return implode('|', $members);
    }

    /**
     * The PHPDoc type of a documented field, for what the example does not show.
     */
    private static function documented(DocsEntry $entry): string
    {
        return match ($entry->type) {
            'string' => 'string',
            'integer' => 'int',
            'number' => 'int|float',
            'boolean' => 'bool',
            'object' => 'array<string, mixed>',
            default => 'list<'.match ($entry->itemType) {
                'string' => 'string',
                'integer' => 'int',
                'number' => 'int|float',
                'boolean' => 'bool',
                'object' => 'array<string, mixed>',
                default => 'mixed',
            }.'>',
        };
    }

    /**
     * @param array<string, DocsEntry> $docs
     */
    private function renderShape(array $shape, int $level, string $path, array $docs): string
    {
        if ([] === $shape['keys']) {
            return 'array<string, mixed>';
        }

        $indent = str_repeat(self::INDENT, $level + 1);
        // Keys the docs require that some items go without: two or more of them are alternative forms of the item (a
        // price id, or a price: each "required" in its own form), and optional
        $missingRequired = [];
        foreach ($shape['keys'] as $key => $entry) {
            $documented = $docs[('' === $path ? '' : $path.'.').$key] ?? null;
            if (null !== $documented && true === $documented->required && $entry['count'] < $shape['count']) {
                $missingRequired[] = $key;
            }
        }
        $alternatives = \count($missingRequired) > 1 ? array_flip($missingRequired) : [];
        $lines = [];
        foreach ($shape['keys'] as $key => $entry) {
            $childPath = ('' === $path ? '' : $path.'.').$key;
            $documented = $docs[$childPath] ?? null;
            // The documentation says what is required; the example only says what it shows (a trimmed example does not
            // make a required key optional), except for alternative forms
            $optional = (isset($alternatives[$key]) || (null !== $documented ? !$documented->required : $entry['count'] < $shape['count'])) ? '?' : '';
            $lines[] = $indent.$this->renderKey((string) $key).$optional.': '.$this->render($entry['type'], $level + 1, $childPath, $docs).',';
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
