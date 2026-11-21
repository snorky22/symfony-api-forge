<?php

namespace Snorky22\ApiForge\Core\Rest;

/**
 * Implements {@see Updatable} in an {@see AbstractRestResource}.
 */
trait Updates
{
    public function update(int|string $id, array $body): array
    {
        return $this->request('PATCH', $this->itemPath(), ['id' => $id], json: $body);
    }
}
