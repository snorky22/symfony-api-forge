<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * Puts the documentation of an API (a DocsCapture) on the structure of an example checked by a human.
 *
 * The capture is flat: entries come in the order of the page with no parent, so the nesting comes from the
 * example. A name is aligned only when the documentation and the example agree on how many fields have it: then
 * each entry binds to the nearest field of that name not yet bound, walking up from the object being read, which
 * is exact. Otherwise the name is not bound and the difference is reported: complete the example, then import
 * again. Nothing is guessed.
 *
 * What the documentation describes and the example doesn't (an object only returned with an "include"
 * parameter, an early access field) would make its names ambiguous: such an object, and what follows it up to
 * the next aligned field, is set aside, and the count is made again without it.
 */
final class DocsAnnotator
{
    private const CERTAIN = 'certain';
    private const UNCERTAIN = 'uncertain';

    /**
     * @param list<DocsEntry> $entries
     * @param list<mixed>     $items   the decoded example (JSON objects as \stdClass), or the items of a list
     */
    public function annotate(array $entries, array $items): DocsAlignment
    {
        $root = new Field('');
        foreach ($items as $item) {
            $root->absorb($item);
        }

        $inExample = [];
        $root->walk(static function (Field $field) use (&$inExample): void {
            if ('' !== $field->name) {
                $inExample[$field->name][] = $field;
            }
        });

        // Setting aside an object changes the counts of the names it holds, which may align them: repeat
        $setAside = [];
        do {
            $before = \count($setAside);
            [$bound, $setAside, $reliable, $unplaced, $underEmpty] = $this->align($entries, $root, $inExample, $setAside);
        } while (\count($setAside) !== $before);

        $hints = [];
        $notes = [];
        foreach ($bound as $path => $index) {
            $hints[$path] = $entries[$index];
        }
        foreach ($this->fields($root) as $field) {
            $entry = $hints[$field->path()] ?? null;
            if (null !== $entry && null !== $difference = $field->differsFrom($entry)) {
                $notes[] = \sprintf('docs: %s is %s in the docs (line %d), %s in the example', $field->path(), $entry->type, $entry->line, $difference);
            }
        }

        // The docs fields of an object the example shows empty (null, []): one note for the object, not one per field
        $explained = [];
        foreach ($underEmpty as $path => $indexes) {
            $field = $this->find($root, $path);
            $names = array_values(array_unique(array_map(static fn (int $i) => $entries[$i]->name, $indexes)));
            $notes[] = \sprintf('docs: "%s" (%s) shows no fields in the example (null or empty), but the docs describe %s: give it an example value', $field?->name ?? $path, $path, implode(', ', $names));
            foreach ($indexes as $index) {
                $explained[$index] = true;
            }
        }

        $documented = [];
        foreach ($entries as $index => $entry) {
            if (!isset($setAside[$index]) && !isset($explained[$index])) {
                $documented[$entry->name][] = [$index, $entry];
            }
        }
        foreach ($documented as $name => $docs) {
            if (isset($reliable[$name])) {
                continue;
            }
            $found = \count($inExample[$name] ?? []);
            $lines = implode(', ', array_map(static fn (array $doc) => (string) $doc[1]->line, $docs));
            if (0 === $found) {
                $values = $docs[0][1]->values;
                $notes[] = \sprintf('docs: "%s" (line %s) is not in the example: add it to the example (in the docs it sits %s%s)', $name, $lines, $this->neighbours($entries, $docs[0][0], array_flip(array_values($bound))), null !== $values ? '; values in the docs: '.implode(', ', $values) : '');
            } else {
                // The count includes the occurrences under an object the example shows empty: say which they are
                $emptied = [];
                foreach (array_keys($explained) as $index) {
                    if ($entries[$index]->name === $name) {
                        $emptied[] = $entries[$index]->line;
                    }
                }
                sort($emptied);
                $all = array_merge(array_map(static fn (array $doc) => $doc[1]->line, $docs), $emptied);
                sort($all);
                $notes[] = \sprintf(
                    'docs: "%s" is described %d time(s) (line %s) and found %d time(s) in the example: %s, nothing is applied to "%s"',
                    $name,
                    \count($all),
                    implode(', ', $all),
                    $found,
                    [] === $emptied ? 'add the missing ones to the example' : \sprintf('line %s under an object the example shows empty (see its note): give that object an example value', implode(', ', $emptied)),
                    $name,
                );
            }
        }
        foreach ($unplaced as $index) {
            if (!isset($explained[$index])) {
                $notes[] = \sprintf('docs: "%s" (line %d) has the right count in the example but no place in it: its parent is not the one of the docs, check where the example holds it', $entries[$index]->name, $entries[$index]->line);
            }
        }

        // An object the docs call free-form (no field described) that the example fills: one note, not one per key
        $freeForm = [];
        foreach ($this->fields($root) as $field) {
            $entry = $hints[$field->path()] ?? null;
            if (null === $entry || 'object' !== $entry->type || !$field->isObject || [] === $field->children) {
                continue;
            }
            $described = array_filter($field->children, static fn (Field $child) => isset($hints[$child->path()]));
            if ([] === $described) {
                $freeForm[$field->path()] = $field;
                $notes[] = \sprintf('example: "%s" (%s) is a free-form object in the docs (no field is described), but the example gives it fields (%s): replace its value with {} in the example', $field->name, $field->path(), implode(', ', array_keys($field->children)));
            }
        }
        $covered = static function (string $path) use ($freeForm): bool {
            foreach (array_keys($freeForm) as $parent) {
                if (str_starts_with($path, $parent.'.') || str_starts_with($path, $parent.'[')) {
                    return true;
                }
            }

            return false;
        };
        foreach (array_diff_key($inExample, array_diff_key($documented, $reliable) + $reliable) as $name => $fields) {
            foreach ($fields as $field) {
                if (!isset($hints[$field->path()]) && !$covered($field->path()) && !isset($freeForm[$field->path()])) {
                    $notes[] = \sprintf('example: "%s" (%s) is not in the docs: complete the docs page text, or remove it from the example', $name, $field->path());
                }
            }
        }

        return new DocsAlignment($hints, $notes);
    }

    /**
     * @param list<DocsEntry>        $entries
     * @param array<string, list<Field>> $inExample
     * @param array<int, true>       $setAside entries (by position) left out of the count
     *
     * @return array{array<string, int>, array<int, true>, array<string, true>, list<int>, array<string, list<int>>} path => entry position, entries set aside, names aligned, aligned names found nowhere, entries under an object the example shows empty (by its path)
     */
    private function align(array $entries, Field $root, array $inExample, array $setAside): array
    {
        $count = [];
        foreach ($entries as $index => $entry) {
            if (!isset($setAside[$index])) {
                $count[$entry->name] = ($count[$entry->name] ?? 0) + 1;
            }
        }
        $reliable = [];
        foreach ($count as $name => $number) {
            if (\count($inExample[$name] ?? []) === $number) {
                $reliable[$name] = true;
            }
        }

        $bound = [];
        $unplaced = [];
        $underEmpty = [];
        $owners = [];
        $stack = [$root->container()];
        foreach ($entries as $index => $entry) {
            $opens = \in_array($entry->type, ['object', 'array'], true);
            $inside = \in_array(self::CERTAIN, $stack, true);

            if (isset($reliable[$entry->name]) && !isset($setAside[$index])) {
                for ($depth = \count($stack) - 1; $depth >= 0; --$depth) {
                    $field = $stack[$depth] instanceof Field ? ($stack[$depth]->children[$entry->name] ?? null) : null;
                    if (null !== $field && !isset($bound[$field->path()])) {
                        $stack = \array_slice($stack, 0, $depth + 1);
                        $owners = array_filter($owners, static fn (int $at) => $at <= $depth, \ARRAY_FILTER_USE_KEY);
                        $bound[$field->path()] = $index;
                        if ($opens && null !== $container = $field->container()) {
                            $stack[] = $container;
                        } elseif ($opens) {
                            $stack[] = self::UNCERTAIN;
                            $owners[\count($stack) - 1] = $field->path();
                        }
                        continue 2;
                    }
                }
            }

            // Not aligned: inside an object set aside, it is set aside too
            $top = \count($stack) - 1;
            if ($inside) {
                $setAside[$index] = true;
            } elseif (isset($owners[$top]) && self::UNCERTAIN === $stack[$top] && !isset($reliable[$entry->name])) {
                // A field of an object the example shows empty
                $underEmpty[$owners[$top]][] = $index;
            } else {
                if (isset($reliable[$entry->name]) && !isset($setAside[$index])) {
                    $unplaced[] = $index;
                }
            }
            if ($opens) {
                // What the example doesn't have (a name of the docs only): its fields are not the example's
                $stack[] = [] === ($inExample[$entry->name] ?? []) ? self::CERTAIN : self::UNCERTAIN;
            }
        }

        return [$bound, $setAside, $reliable, $unplaced, $underEmpty];
    }

    /**
     * Where a field of the docs sits, in the order of the page: between the fields before and after it that the example has.
     *
     * @param list<DocsEntry>  $entries
     * @param array<int, int>  $bound   the positions of the entries bound to a field of the example
     */
    private function neighbours(array $entries, int $index, array $bound): string
    {
        $before = $after = null;
        for ($i = $index - 1; $i >= 0; --$i) {
            if (isset($bound[$i])) {
                $before = $entries[$i]->name;
                break;
            }
        }
        for ($i = $index + 1; $i < \count($entries); ++$i) {
            if (isset($bound[$i])) {
                $after = $entries[$i]->name;
                break;
            }
        }

        return match (true) {
            null !== $before && null !== $after => \sprintf('between "%s" and "%s"', $before, $after),
            null !== $before => \sprintf('after "%s"', $before),
            null !== $after => \sprintf('before "%s"', $after),
            default => 'at the top level',
        };
    }

    private function find(Field $root, string $path): ?Field
    {
        foreach ($this->fields($root) as $field) {
            if ($field->path() === $path) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return \Generator<Field>
     */
    private function fields(Field $field): \Generator
    {
        yield $field;
        foreach ($field->children as $child) {
            yield from $this->fields($child);
        }
        if (null !== $field->item) {
            yield from $this->fields($field->item);
        }
    }
}
