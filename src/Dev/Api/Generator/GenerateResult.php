<?php

namespace Snorky22\ApiForge\Dev\Api\Generator;

/**
 * What api-forge:generate found, or did.
 */
final class GenerateResult
{
    /**
     * @param list<string>                    $errors  why the descriptors are invalid (nothing generated)
     * @param list<string>                    $review  the review notes left in the descriptors
     * @param list<array{string, string}>     $changes created / updated / removed, file
     * @param int                             $files   number of generated files
     * @param ?string                         $blocked why nothing was generated though the descriptors are valid (a file
     *                                                 not written by api-forge:generate would be overwritten)
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly array $review = [],
        public readonly array $changes = [],
        public readonly int $files = 0,
        public readonly ?string $blocked = null,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    public function isBlocked(): bool
    {
        return null !== $this->blocked;
    }

    public function isUpToDate(): bool
    {
        return [] === $this->errors && null === $this->blocked && [] === $this->changes;
    }
}
