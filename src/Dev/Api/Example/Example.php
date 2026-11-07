<?php

namespace Snorky22\ApiForge\Dev\Api\Example;

/**
 * A documentation example, whatever its format, in the two forms the importers read.
 */
final class Example
{
    /**
     * @param ?string $curl     the documented request as a curl command, null when the example has none
     * @param ?string $response the example response as JSON, null when the example has none
     */
    public function __construct(
        public readonly ?string $curl,
        public readonly ?string $response,
    ) {
    }
}
