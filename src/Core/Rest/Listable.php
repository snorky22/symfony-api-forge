<?php

namespace Snorky22\ApiForge\Core\Rest;

use Snorky22\ApiForge\Core\ApiException;

/**
 * A resource whose list can be read: GET {path}.
 *
 * @template TResponse of array
 */
interface Listable
{
    /**
     * Returns one page of the list, as the API sends it.
     *
     * @param array<string, mixed> $query filters, sorting, page size; null values are left out
     *
     * @return TResponse
     *
     * @throws ApiException
     */
    public function list(array $query = []): array;
}
