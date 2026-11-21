<?php

namespace Snorky22\ApiForge\Core\Rest;

use Snorky22\ApiForge\Core\ApiException;

/**
 * A resource whose items can be created: POST {path}, the body as JSON.
 *
 * @template TBody of array
 * @template TResponse of array
 */
interface Creatable
{
    /**
     * @param TBody $body only the fields you send: the optional ones you don't use can be left out
     *
     * @return TResponse usually the created item
     *
     * @throws ApiException
     */
    public function create(array $body): array;
}
