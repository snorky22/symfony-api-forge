<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Curl\CurlRequest;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;

/**
 * Imports one documented example into the descriptors of an API: the work of api-forge:import, for the command
 * and for the web interface.
 */
final class ImportRunner
{
    public function __construct(
        private readonly CurlParser $curlParser,
        private readonly EndpointImporter $importer,
        private readonly DescriptorMerger $merger,
        private readonly DescriptorStore $store,
        private readonly ExampleReader $examples,
    ) {
    }

    /**
     * @param string  $example  file with the curl command, or an example of another format (an array holding the
     *                          request and its response)
     * @param ?string $response file with the example response (JSON, or an array)
     * @param ?string $docs     text capture of the docs page of the response; <example>.docs.md next to the response
     *                          (or the example) when null and it exists
     *
     * @throws \InvalidArgumentException when the example can't be read or conflicts with the descriptors
     */
    public function import(string $api, string $example, ?string $response = null, ?string $docs = null, ?string $resource = null, ?string $operation = null, bool $force = false, ?string $dir = null): ImportResult
    {
        $existingApi = $this->store->loadApi($api, $dir);
        $read = $this->examples->operation($example, $existingApi['base_uri'] ?? null);
        if (null === $read->curl) {
            throw new \InvalidArgumentException(\sprintf('"%s" holds no request: add a "request" ("METHOD /path").', $example));
        }
        if (null !== $response && null !== $read->response) {
            throw new \InvalidArgumentException(\sprintf('The response is given twice: "response" in %s and --response.', $example));
        }

        $request = $this->withBody($this->curlParser->parse($read->curl), $example);
        $endpoint = $this->importer->import(
            $request,
            $api,
            null !== $response ? $this->examples->json($response) : $read->response,
            isset($existingApi['base_uri']) ? [$existingApi['base_uri'], ...array_values($existingApi['environments'] ?? [])] : null,
            $resource,
            $operation,
            $existingApi['format'] ?? null,
            $this->docs($docs, $response ?? $example),
            $this->bodyDocs($example),
        );
        $merged = $this->merger->merge($existingApi, $this->store->loadResource($api, $endpoint->resource, $dir), $endpoint, $force);

        $files = [
            $this->store->saveApi($api, $merged['api'], $dir),
            $this->store->saveResource($api, $endpoint->resource, $merged['resource'], $dir),
        ];
        $saved = $merged['resource']['operations'][$endpoint->operationName];

        return new ImportResult($endpoint->resource, $endpoint->operationName, $saved['method'].' '.$saved['path'], $files, $merged['notes'], $saved['review'] ?? []);
    }

    /**
     * The JSON body of the request, from <example>.body.json (or .body.array) next to the curl file: a request with a
     * body and no method is a POST.
     *
     * @throws \InvalidArgumentException when the body is given twice
     */
    private function withBody(CurlRequest $request, string $example): CurlRequest
    {
        $base = (string) preg_replace('/\.[^.\/]+$/', '', $example);
        foreach (['json', 'array'] as $extension) {
            $file = $base.'.body.'.$extension;
            if (!is_file($file)) {
                continue;
            }
            if (null !== $request->body) {
                throw new \InvalidArgumentException(\sprintf('The request body is given twice: "-d" in %s and %s.', $example, basename($file)));
            }

            return new CurlRequest('GET' === $request->method ? 'POST' : $request->method, $request->url, $request->headers, $this->examples->json($file), $request->user, $request->unknownOptions);
        }

        return $request;
    }

    private function bodyDocs(string $example): ?string
    {
        $file = preg_replace('/\.[^.\/]+$/', '', $example).'.body.docs.md';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function docs(?string $docs, string $example): ?string
    {
        if (null === $docs) {
            $docs = preg_replace('/\.[^.\/]+$/', '', $example).'.docs.md';
            if (!is_file($docs)) {
                return null;
            }
        }
        $content = is_file($docs) ? file_get_contents($docs) : false;
        if (false === $content) {
            throw new \InvalidArgumentException(\sprintf('Cannot read file "%s".', $docs));
        }

        return $content;
    }
}
