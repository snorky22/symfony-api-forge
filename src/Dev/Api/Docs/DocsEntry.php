<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * One field described by the documentation of an API, as read from a text capture of the page.
 */
final class DocsEntry
{
    /**
     * @param string            $type     the documented type: string, object, array, integer, number, boolean
     * @param ?bool             $required true: documented required, false: not marked required
     * @param ?list<string>     $values   the documented values (enum), null when none is listed
     * @param int               $valueCount number of values listed (may exceed those kept)
     * @param array<string, string> $details  Default, Example, Length, Pattern, Min, Max, Range, Items...
     * @param int               $line     line of the capture, for the reports
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly ?bool $required,
        public readonly ?string $format,
        public readonly ?array $values,
        public readonly int $valueCount,
        public readonly array $details,
        public readonly int $line,
    ) {
    }
}
