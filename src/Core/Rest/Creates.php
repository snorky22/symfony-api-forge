<?php

namespace Snorky22\ApiForge\Core\Rest;

/**
 * Implements {@see Creatable} in an {@see AbstractRestResource}.
 */
trait Creates
{
    public function create(array $body): array
    {
        return $this->request('POST', $this->path(), json: $body);
    }
}
