<?php

namespace Snorky22\ApiForge\Core\Rest;

use Snorky22\ApiForge\Core\ApiException;

/**
 * A resource whose items can be deleted: DELETE {path}/{id}.
 */
interface Deletable
{
    /**
     * The response, if any, is ignored: APIs differ (204, or the deleted item).
     *
     * @throws ApiException
     */
    public function delete(int|string $id): void;
}
