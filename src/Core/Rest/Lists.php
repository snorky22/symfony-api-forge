<?php

namespace Snorky22\ApiForge\Core\Rest;

/**
 * Implements {@see Listable} in an {@see AbstractRestResource}.
 */
trait Lists
{
    public function list(array $query = []): array
    {
        return $this->request('GET', $this->path(), query: $query);
    }
}
