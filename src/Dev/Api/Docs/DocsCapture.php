<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * Reads a text capture of the documentation of a response or a body (the page copied as text or Markdown) as
 * the ordered list of the fields it describes. The capture is flat: the page's indentation is lost, so the
 * entries come in the order of the page, depth first, with no parent (see DocsAnnotator).
 *
 * A field is a line made of its name immediately followed by its type, then "| null" and "required":
 *
 *     billing_cycleobject | nullrequired
 *     nextstring (uri)required
 *
 * followed by its details (Default:, Example:, Length:, Pattern:, Min:, Max:, Items:, Range:) and its
 * "Values" (enum, as bullets of a backticked value).
 */
final class DocsCapture
{
    private const TYPES = 'string|object|array|integer|number|boolean';
    /** Badges the page may show after the type */
    private const BADGES = 'early access|beta|alpha|new|deprecated|preview';
    /** Values kept for an enum: longer lists (countries, currencies) are not worth a shape */
    public const MAX_VALUES = 12;
    private const DETAILS = ['Default', 'Example', 'Length', 'Max length', 'Pattern', 'Min', 'Max', 'Minimum', 'Maximum', 'Range', 'Items'];

    /**
     * @return list<DocsEntry>
     */
    public function parse(string $text): array
    {
        $entries = [];
        $current = null;
        $inValues = false;
        $lines = preg_split('/\R/', $text) ?: [];

        foreach ($lines as $index => $raw) {
            $line = rtrim($raw);

            if (null !== $header = $this->header(trim($line))) {
                if (null !== $current) {
                    $entries[] = $this->entry($current);
                }
                $current = $header + ['line' => $index + 1, 'values' => null, 'count' => 0, 'truncated' => false, 'details' => []];
                $inValues = false;
                continue;
            }
            if (null === $current) {
                continue;
            }

            if ('Values' === trim($line)) {
                $inValues = true;
                $current['values'] = [];
                continue;
            }
            if ($inValues) {
                // The page shows a few values and "+ Show all values": the capture holds an incomplete list
                if (1 === preg_match('/^\+?\s*Show (?:all|more)\b/i', trim($line))) {
                    $current['truncated'] = true;
                    continue;
                }
                if (1 === preg_match('/^\*\s+`([^`]*)`/', $line, $matches)) {
                    ++$current['count'];
                    if ($current['count'] <= self::MAX_VALUES + 1) {
                        $current['values'][] = $matches[1];
                    }
                    continue;
                }
                // The description of a value is indented, the end of the list is not
                if ('' === trim($line) || 1 === preg_match('/^\s+\S/', $line)) {
                    continue;
                }
                $inValues = false;
            }

            if (1 === preg_match('/^('.implode('|', array_map('preg_quote', self::DETAILS)).'):\s*(.+)$/', trim($line), $matches)) {
                $current['details'][$matches[1]] ??= trim($matches[2], ' `');
            }
        }
        if (null !== $current) {
            $entries[] = $this->entry($current);
        }

        return $entries;
    }

    /**
     * @return ?array{name: string, type: string, nullable: bool, required: bool, format: ?string}
     */
    private function header(string $line): ?array
    {
        // The name ends where a type begins; the lazy name and the strict end make "object_arrayarray" read right
        if (1 !== preg_match('/^([A-Za-z_][A-Za-z0-9_]*?)('.self::TYPES.')(?:\s*\(([^)]*)\))?(\s*\|\s*null)?(?:\s*\(([^)]*)\))?(required)?(?:'.self::BADGES.')?$/', $line, $matches)) {
            return null;
        }

        return [
            'name' => $matches[1],
            'type' => $matches[2],
            'nullable' => '' !== ($matches[4] ?? ''),
            'required' => '' !== ($matches[6] ?? ''),
            'format' => '' !== ($matches[3] ?? '') ? $matches[3] : ('' !== ($matches[5] ?? '') ? $matches[5] : null),
        ];
    }

    /**
     * @param array{name: string, type: string, nullable: bool, required: bool, format: ?string, line: int, values: ?list<string>, count: int, truncated: bool, details: array<string, string>} $current
     */
    private function entry(array $current): DocsEntry
    {
        $values = null !== $current['values'] && !$current['truncated'] && $current['count'] <= self::MAX_VALUES ? $current['values'] : null;

        return new DocsEntry($current['name'], $current['type'], $current['nullable'], $current['required'], $current['format'], $values, $current['count'], $current['details'], $current['line']);
    }
}
