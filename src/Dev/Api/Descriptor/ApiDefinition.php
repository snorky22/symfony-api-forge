<?php

namespace Snorky22\ApiForge\Dev\Api\Descriptor;

/**
 * Validated and normalized descriptors of an API, ready for code generation.
 */
final class ApiDefinition
{
    /**
     * @param array<string, mixed>                $api       normalized _api.yaml
     * @param array<string, array<string, mixed>> $resources normalized resource files, by resource name
     * @param list<string>                        $review    review notes left in the descriptors, prefixed with their location
     * @param ?array<string, mixed>               $webhooks  normalized _webhooks.yaml, null if the API has no webhooks
     */
    public function __construct(
        public readonly string $name,
        public readonly string $namespace,
        public readonly array $api,
        public readonly array $resources,
        public readonly array $review,
        public readonly ?array $webhooks = null,
    ) {
    }
}
