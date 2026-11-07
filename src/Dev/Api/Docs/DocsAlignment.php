<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * What the documentation says about the fields of an example, found by DocsAnnotator.
 */
final class DocsAlignment
{
    /**
     * @param array<string, DocsEntry> $hints path of the field in the example (data[].name) => its documentation
     * @param list<string>             $notes what could not be aligned, to review
     */
    public function __construct(
        public readonly array $hints = [],
        public readonly array $notes = [],
    ) {
    }
}
