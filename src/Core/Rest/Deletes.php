<?php

namespace Snorky22\ApiForge\Core\Rest;

/**
 * Implements {@see Deletable} in an {@see AbstractRestResource}.
 */
trait Deletes
{
    public function delete(int|string $id): void
    {
        $this->request('DELETE', $this->itemPath(), ['id' => $id]);
    }
}
