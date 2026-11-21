<?php

namespace Snorky22\ApiForge\Core\Rest;

/**
 * Implements {@see Readable} in an {@see AbstractRestResource}.
 */
trait Reads
{
    public function get(int|string $id, array $query = []): array
    {
        return $this->request('GET', $this->itemPath(), ['id' => $id], $query);
    }
}
