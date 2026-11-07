<?php

namespace Snorky22\ApiForge\Dev\Api\Example;

/**
 * A format of documentation examples other than curl and JSON, read by file extension (tag
 * api_forge.example_format, by autoconfiguration). It converts what the API documentation gives, whatever
 * its state, to a curl command and a JSON response: the importers do the rest.
 */
interface ExampleFormat
{
    /**
     * @return list<string> file extensions, lower case, without the dot
     */
    public function extensions(): array;

    /**
     * An example describing an operation: a request and/or its response.
     *
     * @param ?string                $baseUri base URI of the API, when known: requests may then give a path only
     * @param ?\Closure(mixed): mixed $resolve resolves the references to other data files in the data of the
     *                                         request body and of the response (ReferenceResolver)
     *
     * @throws \InvalidArgumentException when the example can't be read
     */
    public function operation(string $content, ?string $baseUri, ?\Closure $resolve = null): Example;

    /**
     * A bare JSON document (a response, an object, a type, a webhook payload) in this format, as data.
     *
     * @throws \InvalidArgumentException when the example can't be read
     */
    public function document(string $content): mixed;
}
