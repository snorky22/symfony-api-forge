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

    /**
     * The hints of the fields under a member ("data", or "data[]" for the items of a list), by their path in it.
     *
     * @param array<string, DocsEntry> $hints
     *
     * @return array<string, DocsEntry>
     */
    public static function under(string $member, array $hints): array
    {
        $under = [];
        foreach ($hints as $path => $entry) {
            if ($path === $member) {
                $under[''] = $entry;
            } elseif (str_starts_with($path, $member.'.')) {
                $under[substr($path, \strlen($member) + 1)] = $entry;
            }
        }

        return $under;
    }

    /**
     * The hints of the fields outside a top-level member.
     *
     * @param array<string, DocsEntry> $hints
     *
     * @return array<string, DocsEntry>
     */
    public static function outside(string $member, array $hints): array
    {
        return array_filter($hints, static fn (string $path) => $path !== $member && !str_starts_with($path, $member.'.') && !str_starts_with($path, $member.'['), \ARRAY_FILTER_USE_KEY);
    }
}
