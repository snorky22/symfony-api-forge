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
     * @param ?string           $itemType the type of the items of an array ("array of strings"), when the page says it
     * @param ?list<string>     $parents  the names of the fields holding it, outermost first, when the HTML of the page
     *                                    gives them (DocsHtml); null when only the text is known
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
        public readonly ?string $itemType = null,
        public readonly bool $deprecated = false,
        public readonly bool $readOnly = false,
        public readonly bool $writeOnly = false,
        public readonly ?array $parents = null,
    ) {
    }

    /**
     * The same entry, not required: it is in some alternatives of its object only.
     */
    public function optional(): self
    {
        return new self($this->name, $this->type, $this->nullable, false, $this->format, $this->values, $this->valueCount, $this->details, $this->line, $this->itemType, $this->deprecated, $this->readOnly, $this->writeOnly, $this->parents);
    }

    /**
     * @param list<string> $parents
     */
    public function withParents(array $parents): self
    {
        return new self($this->name, $this->type, $this->nullable, $this->required, $this->format, $this->values, $this->valueCount, $this->details, $this->line, $this->itemType, $this->deprecated, $this->readOnly, $this->writeOnly, $parents);
    }
}
