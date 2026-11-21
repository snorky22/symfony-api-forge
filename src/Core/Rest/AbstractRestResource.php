<?php

namespace Snorky22\ApiForge\Core\Rest;

use Snorky22\ApiForge\Core\AbstractResource;

/**
 * Base class of the hand-written REST resources: a path, and the traits of the actions it supports.
 *
 *     final class Prices extends AbstractRestResource implements Listable, Readable
 *     {
 *         use Lists, Reads;
 *
 *         protected function path(): string { return 'prices'; }
 *     }
 *
 * The HTTP client is expected to be scoped to the API (base URI, authentication, default headers).
 */
abstract class AbstractRestResource extends AbstractResource
{
    /**
     * Path of the resource, relative to the base URI of the client, e.g. "prices".
     */
    abstract protected function path(): string;

    /**
     * Path of one item, with an {id} placeholder.
     */
    protected function itemPath(): string
    {
        return $this->path().'/{id}';
    }
}
