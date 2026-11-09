<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * Reads a text capture of the documentation of a response or a body (the page copied as text or Markdown) as
 * the ordered list of the fields it describes. The capture is flat: the page's indentation is lost, so the
 * entries come in the order of the page, depth first, with no parent (see DocsAnnotator).
 *
 * A field is a line made of its name immediately followed by its type, then "| null" and "required" (a web copy glues them;
 * a Markdown copy separates them with spaces, "name string required"):
 *
 *     billing_cycleobject | nullrequired
 *     nextstring (uri)required
 *     name string required
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
    /** Words that start a sentence about a type ('the number', 'is boolean'), not names of fields */
    private const WORDS = ['an', 'as', 'is', 'be', 'or', 'of', 'to', 'in', 'by', 'at', 'on', 'if', 'it', 'no', 'the', 'are', 'and', 'for', 'any', 'all', 'can', 'not', 'may', 'one', 'use', 'set', 'has', 'was', 'its', 'but'];
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
     * @return ?array{name: string, type: string, nullable: bool, required: bool, format: ?string, itemType: ?string, deprecated: bool, readOnly: bool, writeOnly: bool}
     */
    private function header(string $line): ?array
    {
        $extras = ['itemType' => null, 'deprecated' => false, 'readOnly' => false, 'writeOnly' => false];

        // The web copy glues the name, the type and the flags ("billing_cycleobject | nullrequired"); the lazy name and the
        // strict end make "object_arrayarray" read right
        if (1 === preg_match('/^([A-Za-z_][A-Za-z0-9_]*?)('.self::TYPES.')(?:\s*\(([^)]*)\))?(\s*\|\s*null)?(?:\s*\(([^)]*)\))?(required|optional)?(?:'.self::BADGES.')?$/', $line, $matches)) {
            return [
                'name' => $matches[1],
                'type' => $matches[2],
                'nullable' => '' !== ($matches[4] ?? ''),
                'required' => 'required' === ($matches[6] ?? ''),
                'format' => '' !== ($matches[3] ?? '') ? $matches[3] : ('' !== ($matches[5] ?? '') ? $matches[5] : null),
            ] + $extras;
        }

        // The Markdown copy, or a page that writes it its own way: a lowercase name (a mark "?" or "*" says optional or
        // required), then words of the vocabulary in any order: "name string required", "type: string, optional",
        // "ids array of strings", "created_at string (date-time)", "token (string, required)"
        if (1 !== preg_match('/^([a-z][a-z0-9_]+)([?*]?)\s*:?\s*(.+)$/', $line, $matches) || \in_array($matches[1], self::WORDS, true)) {
            return null;
        }
        $info = DocsVocabulary::parse($matches[3]);
        if (null === $info) {
            return null;
        }

        return [
            'name' => $matches[1],
            'type' => $info['type'],
            'nullable' => $info['nullable'],
            'required' => ($info['required'] || '*' === $matches[2]) && !$info['optional'] && '?' !== $matches[2],
            'format' => $info['format'],
            'itemType' => $info['itemType'],
            'deprecated' => $info['deprecated'],
            'readOnly' => $info['readOnly'],
            'writeOnly' => $info['writeOnly'],
        ];
    }

    /**
     * @param array{name: string, type: string, nullable: bool, required: bool, format: ?string, itemType: ?string, deprecated: bool, readOnly: bool, writeOnly: bool, line: int, values: ?list<string>, count: int, truncated: bool, details: array<string, string>} $current
     */
    private function entry(array $current): DocsEntry
    {
        $values = null !== $current['values'] && !$current['truncated'] && $current['count'] <= self::MAX_VALUES ? $current['values'] : null;

        return new DocsEntry($current['name'], $current['type'], $current['nullable'], $current['required'], $current['format'], $values, $current['count'], $current['details'], $current['line'], $current['itemType'], $current['deprecated'], $current['readOnly'], $current['writeOnly']);
    }
}
