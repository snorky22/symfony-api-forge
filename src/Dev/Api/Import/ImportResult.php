<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

/**
 * What an import wrote: the operation, the files, what was done to the descriptors and what to review.
 */
final class ImportResult
{
    /**
     * @param list<string> $files  descriptor files written
     * @param list<string> $notes  what the merge did ("operation "list" added")
     * @param list<string> $review the guesses of the operation, to review
     */
    public function __construct(
        public readonly string $resource,
        public readonly string $operation,
        public readonly string $endpoint,
        public readonly array $files,
        public readonly array $notes,
        public readonly array $review,
    ) {
    }
}
