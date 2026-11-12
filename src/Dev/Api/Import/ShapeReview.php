<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

/**
 * The review notes of a generated shape: the keys whose type the examples leave unknown ("mixed", "list<mixed>"),
 * named, so that the web interface can offer a fix for each.
 */
final class ShapeReview
{
    /**
     * @param string       $what   what the shape describes: "response", "object", "request body"
     * @param list<string> $review the notes, completed
     */
    public static function add(string $shape, string $what, array &$review): void
    {
        $paths = self::unknownTypes($shape);
        if (preg_match('/:\s*mixed,/', $shape)) {
            $review[] = \sprintf('%s: "mixed" marks values that are null in the example, their real type is unknown%s', $what, self::keys($paths['mixed']));
        }
        if (str_contains($shape, 'list<mixed>')) {
            $review[] = \sprintf('%s: "list<mixed>" marks empty arrays in the example, they may be empty objects%s', $what, self::keys($paths['lists']));
        }
    }

    /**
     * The keys typed "mixed" and "list<mixed>" in a generated (multi-line) shape, as paths: data[].custom_data.
     *
     * @return array{mixed: list<string>, lists: list<string>}
     */
    private static function unknownTypes(string $shape): array
    {
        $found = ['mixed' => [], 'lists' => []];
        $stack = [];
        foreach (preg_split('/\R/', $shape) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '}')) {
                array_pop($stack);
                continue;
            }
            $key = preg_match('/^(\'[^\']*\'|[^\s:?]+)\??:\s*(.*)$/', $line, $matches) ? trim($matches[1], "'") : null;
            $type = $matches[2] ?? $line;
            if (str_ends_with($type, '{')) {
                $stack[] = (null !== $key ? $key : '').(str_starts_with($type, 'list<') ? '[]' : '');
                continue;
            }
            if (null === $key) {
                continue;
            }
            $path = implode('.', array_filter([...$stack, $key], static fn (string $part) => '' !== $part));
            if (1 === preg_match('/^mixed,?$/', $type)) {
                $found['mixed'][] = $path;
            } elseif (str_contains($type, 'list<mixed>')) {
                $found['lists'][] = $path;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $paths
     */
    private static function keys(array $paths): string
    {
        $paths = array_values(array_unique($paths));

        return [] === $paths ? '' : \sprintf(' (%s%s)', implode(', ', \array_slice($paths, 0, 10)), \count($paths) > 10 ? ', …' : '');
    }
}
