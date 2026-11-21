<?php

namespace Snorky22\ApiForge\Tests\Core\Rest;

use Snorky22\ApiForge\Core\Rest\AbstractRestResource;
use Snorky22\ApiForge\Core\Rest\Creatable;
use Snorky22\ApiForge\Core\Rest\Creates;
use Snorky22\ApiForge\Core\Rest\Crud;
use Snorky22\ApiForge\Core\Rest\Deletes;
use Snorky22\ApiForge\Core\Rest\Listable;
use Snorky22\ApiForge\Core\Rest\Lists;
use Snorky22\ApiForge\Core\Rest\Readable;
use Snorky22\ApiForge\Core\Rest\Reads;
use Snorky22\ApiForge\Core\Rest\Updatable;
use Snorky22\ApiForge\Core\Rest\Updates;

/**
 * Like Paddle's prices: no delete (archived by an update), and a method of its own.
 *
 * @phpstan-type Price array{id: string, status: string, ...}
 *
 * @implements Listable<array{data: list<Price>, ...}>
 * @implements Readable<array{data: Price, ...}>
 * @implements Creatable<array{product_id: string, description: string, ...}, array{data: Price, ...}>
 * @implements Updatable<array{description?: string, status?: string, ...}, array{data: Price, ...}>
 */
final class Prices extends AbstractRestResource implements Listable, Readable, Creatable, Updatable
{
    use Creates;
    use Lists;
    use Reads;
    use Updates;

    /**
     * @return array{data: Price, ...}
     */
    public function archive(string $id): array
    {
        return $this->update($id, ['status' => 'archived']);
    }

    protected function path(): string
    {
        return 'prices';
    }
}

/**
 * The five actions.
 *
 * @implements Crud<array<mixed>, array<mixed>, array<mixed>>
 */
final class Collections extends AbstractRestResource implements Crud
{
    use Creates;
    use Deletes;
    use Lists;
    use Reads;
    use Updates;

    protected function path(): string
    {
        return 'collections';
    }
}
