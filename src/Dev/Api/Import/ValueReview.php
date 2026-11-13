<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

/**
 * The review notes of the values of an example: a field with values of two kinds (an object here, a list there: one
 * of them is probably wrong), and a list of lists of objects (often a type file holding a list, used as one item).
 */
final class ValueReview
{
    private const KINDS = ['object' => 'an object', 'list' => 'a list', 'string' => 'a text', 'number' => 'a number', 'bool' => 'yes/no'];

    /**
     * @param list<mixed>  $items  the decoded examples (JSON objects as \stdClass), or the items of a list
     * @param string       $what   what they are: "response", "object", "payload", "request body"
     * @param list<string> $review the notes, completed
     */
    public static function add(array $items, string $what, array &$review): void
    {
        $kinds = [];
        $listsOfLists = [];
        foreach ($items as $item) {
            self::walk($item, '', $kinds, $listsOfLists);
        }
        foreach ($kinds as $path => $counts) {
            if (\count($counts) > 1) {
                $said = [];
                foreach ($counts as $kind => $count) {
                    $said[] = \sprintf('%s (%d time%s)', self::KINDS[$kind], $count, 1 === $count ? '' : 's');
                }
                $review[] = \sprintf('%s: "%s" (%s) has two kinds of values in the example: %s: one of them is probably wrong', $what, self::name($path), $path, implode(', ', $said));
            }
        }
        foreach (array_keys($listsOfLists) as $path) {
            $review[] = \sprintf('%s: "%s" (%s) is a list of lists in the example: when its item is described in a type file (["…array"]), that file must hold one item, not a list', $what, self::name($path), $path);
        }
    }

    /**
     * @param array<string, array<string, int>> $kinds
     * @param array<string, true>               $listsOfLists
     */
    private static function walk(mixed $value, string $path, array &$kinds, array &$listsOfLists): void
    {
        $kind = match (true) {
            null === $value => null,
            $value instanceof \stdClass => 'object',
            \is_array($value) => 'list',
            \is_string($value) => 'string',
            \is_int($value), \is_float($value) => 'number',
            \is_bool($value) => 'bool',
            default => null,
        };
        if (null === $kind) {
            return;
        }
        if ('' !== $path) {
            $kinds[$path][$kind] = ($kinds[$path][$kind] ?? 0) + 1;
        }
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $child) {
                self::walk($child, ('' === $path ? '' : $path.'.').$key, $kinds, $listsOfLists);
            }
        } elseif (\is_array($value)) {
            $inner = array_filter($value, static fn ($item) => \is_array($item) && [] !== $item && [] === array_filter($item, static fn ($x) => !$x instanceof \stdClass));
            if ('' !== $path && [] !== $value && \count($inner) === \count($value)) {
                $listsOfLists[$path] = true;
            }
            foreach ($value as $item) {
                self::walk($item, $path.'[]', $kinds, $listsOfLists);
            }
        }
    }

    private static function name(string $path): string
    {
        return (string) preg_replace('/^.*\./', '', str_replace('[]', '', $path));
    }
}
