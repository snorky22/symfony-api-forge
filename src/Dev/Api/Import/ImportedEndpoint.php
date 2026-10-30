<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

final class ImportedEndpoint
{
    /**
     * @param array<string, mixed>                         $api       API-level settings found in the request (base_uri, format, headers, auth)
     * @param ?string                                      $type      JSON:API resource type, when the bodies tell it
     * @param array<string, mixed>                         $operation operation descriptor
     * @param ?array{shape: string, review?: list<string>} $object    resource object descriptor, from a JSON:API response
     */
    public function __construct(
        public readonly array $api,
        public readonly string $resource,
        public readonly ?string $type,
        public readonly string $operationName,
        public readonly array $operation,
        public readonly ?array $object = null,
    ) {
    }
}
