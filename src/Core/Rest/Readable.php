<?php

namespace Snorky22\ApiForge\Core\Rest;

use Snorky22\ApiForge\Core\ApiException;

/**
 * A resource whose items can be read one by one: GET {path}/{id}.
 *
 * @template TResponse of array
 */
interface Readable
{
    /**
     * Returns the item, as the API sends it.
     *
     * @param array<string, mixed> $query e.g. related objects to include; null values are left out
     *
     * @return TResponse
     *
     * @throws ApiException
     */
    public function get(int|string $id, array $query = []): array;
}
