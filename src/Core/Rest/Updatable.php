<?php

namespace Snorky22\ApiForge\Core\Rest;

use Snorky22\ApiForge\Core\ApiException;

/**
 * A resource whose items can be modified: PATCH {path}/{id}, the body as JSON.
 *
 * @template TBody of array
 * @template TResponse of array
 */
interface Updatable
{
    /**
     * @param TBody $body only the fields to change
     *
     * @return TResponse usually the updated item
     *
     * @throws ApiException
     */
    public function update(int|string $id, array $body): array;
}
