<?php

namespace Snorky22\ApiForge\Core\Rest;

/**
 * A resource with the five actions: list, get, create, update, delete.
 *
 * @template TList of array response of list()
 * @template TItem of array response of get(), create() and update()
 * @template TBody of array body of create() and update()
 *
 * @extends Listable<TList>
 * @extends Readable<TItem>
 * @extends Creatable<TBody, TItem>
 * @extends Updatable<TBody, TItem>
 */
interface Crud extends Listable, Readable, Creatable, Updatable, Deletable
{
}
