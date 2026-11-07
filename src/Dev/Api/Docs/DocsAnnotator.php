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
            [$bound, $setAside, $reliable, $unplaced] = $this->align($entries, $root, $inExample, $setAside);
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

        $documented = [];
        foreach ($entries as $index => $entry) {
            if (!isset($setAside[$index])) {
                $documented[$entry->name][] = $entry;
            }
        }
        foreach ($documented as $name => $docs) {
            if (isset($reliable[$name])) {
                continue;
            }
            $found = \count($inExample[$name] ?? []);
            $lines = implode(', ', array_map(static fn (DocsEntry $entry) => (string) $entry->line, $docs));
            $notes[] = 0 === $found
                ? \sprintf('docs: "%s" (line %s) is not in the example: add it to the example', $name, $lines)
                : \sprintf('docs: "%s" is described %d time(s) (line %s) and found %d time(s) in the example: add the missing ones to the example, nothing is applied to "%s"', $name, \count($docs), $lines, $found, $name);
        }
        foreach ($unplaced as $index) {
            $notes[] = \sprintf('docs: "%s" (line %d) has the right count in the example but no place in it: its parent is not the one of the docs, check where the example holds it', $entries[$index]->name, $entries[$index]->line);
        }
        foreach (array_diff_key($inExample, $documented) as $name => $fields) {
            $notes[] = \sprintf('example: "%s" (%s) is not in the docs', $name, $fields[0]->path());
        }

        return new DocsAlignment($hints, $notes);
    }

    /**
     * @param list<DocsEntry>        $entries
     * @param array<string, list<Field>> $inExample
     * @param array<int, true>       $setAside entries (by position) left out of the count
     *
     * @return array{array<string, int>, array<int, true>, array<string, true>, list<int>} path => entry position, entries set aside, names aligned, aligned names found nowhere
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
        $stack = [$root->container()];
        foreach ($entries as $index => $entry) {
            $opens = \in_array($entry->type, ['object', 'array'], true);
            $inside = \in_array(self::CERTAIN, $stack, true);

            if (isset($reliable[$entry->name]) && !isset($setAside[$index])) {
                for ($depth = \count($stack) - 1; $depth >= 0; --$depth) {
                    $field = $stack[$depth] instanceof Field ? ($stack[$depth]->children[$entry->name] ?? null) : null;
                    if (null !== $field && !isset($bound[$field->path()])) {
                        $stack = \array_slice($stack, 0, $depth + 1);
                        $bound[$field->path()] = $index;
                        if ($opens && null !== $container = $field->container()) {
                            $stack[] = $container;
                        } elseif ($opens) {
                            $stack[] = self::UNCERTAIN;
                        }
                        continue 2;
                    }
                }
            }

            // Not aligned: inside an object set aside, it is set aside too
            if ($inside) {
                $setAside[$index] = true;
            } elseif (isset($reliable[$entry->name]) && !isset($setAside[$index])) {
                $unplaced[] = $index;
            }
            if ($opens) {
                // What the example doesn't have (a name of the docs only): its fields are not the example's
                $stack[] = [] === ($inExample[$entry->name] ?? []) ? self::CERTAIN : self::UNCERTAIN;
            }
        }

        return [$bound, $setAside, $reliable, $unplaced];
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
