<?php

namespace App\Dev\Api\Import;

use App\Dev\Api\Curl\CurlRequest;
use App\Dev\ArrayShapeGenerator;
use App\Dev\LenientJsonDecoder;

use function Symfony\Component\String\u;

/**
 * Turns a documented request (curl) and its example response into descriptor data.
 *
 * This is the lenient phase: whatever had to be guessed is listed in the operation's
 * "review" entry, so it can be checked and fixed by hand in the descriptor.
 */
final class EndpointImporter
{
    private const VERSION_SEGMENT = '/^(?:api|v\d+(?:\.\d+)*)$/i';
    private const ID_SEGMENT = '/^(?:\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';
    private const PLACEHOLDER_SEGMENT = '/^(?:\{([^}]+)\}|:([a-z_]\w*))$/i';
    private const PLACEHOLDER_VALUE = '/^(?:\{[^}]+\}|<[^>]+>|\$\{?[A-Z_]+\}?|YOUR_[A-Z_]+)$/';

    public function __construct(
        private readonly LenientJsonDecoder $decoder,
        private readonly ArrayShapeGenerator $shapeGenerator,
    ) {
    }

    /**
     * @param ?string $baseUri       known base URI of the API; guessed from the URL when null
     * @param ?string $resource      resource name; first path segment when null
     * @param ?string $operationName operation name; inferred from method and path when null
     * @param ?string $format        known API format ("json" or "jsonapi"); detected from the headers when null
     */
    public function import(
        CurlRequest $request,
        string $apiName,
        ?string $responseJson = null,
        ?string $baseUri = null,
        ?string $resource = null,
        ?string $operationName = null,
        ?string $format = null,
    ): ImportedEndpoint {
        $review = [];
        foreach ($request->unknownOptions as $option) {
            $review[] = \sprintf('curl option "%s" was ignored', $option);
        }

        [$baseUri, $path, $query] = $this->splitUrl($request->url, $baseUri);
        $segments = '' === $path ? [] : explode('/', $path);
        if ([] === $segments) {
            throw new \InvalidArgumentException(\sprintf('The URL "%s" has no resource path after the base URI "%s".', $request->url, $baseUri));
        }

        $resource ??= $segments[0];
        [$pathTemplate, $parameters, $isParameter] = $this->templatePath($segments, $review);
        $operationName ??= $this->operationName($request->method, $segments, $isParameter);
        $isCrud = \in_array($operationName, ['list', 'get', 'create', 'update', 'delete'], true);

        $operation = ['method' => $request->method, 'path' => $pathTemplate];
        if ([] !== $parameters) {
            $operation['parameters'] = $parameters;
        }

        if ([] !== $query) {
            $operation['query'] = $query;
            $review[] = 'query parameters are assumed optional';
        }

        $headers = $request->headers;
        $auth = $this->detectAuth($request, $apiName, $headers);

        $contentType = ($request->header('Content-Type') ?? '').' '.($request->header('Accept') ?? '');
        $format ??= str_contains($contentType, 'application/vnd.api+json') ? 'jsonapi' : 'json';
        $jsonApi = 'jsonapi' === $format;

        $type = null;
        if (null !== $request->body) {
            $body = $this->decode($request->body);
            if ($jsonApi && isset($body->data->attributes)) {
                [$operation['body'], $type] = $this->describeJsonApiBody($body->data, $review);
            } elseif (null !== $body) {
                $operation['body'] = ['shape' => $this->shapeGenerator->generate($body)];
                $this->reviewShape($operation['body']['shape'], 'request body', $review);
            } else {
                $operation['body'] = ['raw' => $request->body];
                $review[] = 'request body is not JSON, kept as raw text';
            }
        }

        $object = null;
        if (null !== $responseJson) {
            $response = $this->decode($responseJson);
            [$items, $isList] = $jsonApi ? $this->resourceObjects($response) : [[], false];
            $itemType = $this->commonType($items);

            // A JSON:API document of the resource itself: its objects describe the resource, once for all operations.
            if ($isCrud && null !== $itemType && (null === $type || $type === $itemType)) {
                $type = $itemType;
                $operation['response'] = ['data' => $isList ? 'list' : 'object'];
                $object = ['shape' => $this->shapeGenerator->generateMerged($items)];
                $objectReview = [];
                $this->reviewShape($object['shape'], 'object', $objectReview);
                if ([] !== $objectReview) {
                    $object['review'] = $objectReview;
                }
            } elseif (null !== $response) {
                $operation['response'] = ['shape' => $this->shapeGenerator->generate($response)];
                $this->reviewShape($operation['response']['shape'], 'response', $review);
            } else {
                $review[] = 'response is not JSON, ignored';
            }
        } elseif ($jsonApi && $isCrud && 'delete' !== $operationName) {
            $operation['response'] = ['data' => 'list' === $operationName ? 'list' : 'object'];
            $review[] = 'no response example: assumed to be a JSON:API document of the resource';
        } else {
            $review[] = 'no response example';
        }

        // On nested routes (e.g. orders/{id}/order-items), the bodies describe another resource type.
        if (!$isCrud) {
            $type = null;
        }

        if ([] !== $review) {
            $operation['review'] = $review;
        }

        $api = array_filter([
            'base_uri' => $baseUri,
            'format' => $format,
            'headers' => $headers,
            'auth' => $auth,
        ], static fn ($value) => null !== $value && [] !== $value);

        return new ImportedEndpoint($api, $resource, $type, $operationName, $operation, $object);
    }

    /**
     * Describes a resource object from standalone examples, as documented in "The X object" sections.
     * Full JSON:API documents are accepted too (API responses, webhook payloads): their data is used.
     * Several examples are merged into one shape.
     *
     * @param string|list<string> $json
     *
     * @return array{?string, array{shape: string, review?: list<string>}} [JSON:API type, object descriptor]
     */
    public function importObject(string|array $json): array
    {
        $items = [];
        foreach ((array) $json as $example) {
            $data = $this->decode($example);
            if (null === $data) {
                throw new \InvalidArgumentException('An object example is not valid JSON.');
            }
            [$objects] = $this->resourceObjects($data);
            array_push($items, ...([] === $objects ? [$data] : $objects));
        }

        $object = ['shape' => $this->shapeGenerator->generateMerged($items)];
        $review = [];
        $this->reviewShape($object['shape'], 'object', $review);
        if ([] !== $review) {
            $object['review'] = $review;
        }

        return [$this->commonType($items), $object];
    }

    /**
     * @return array{string, string, array<string, array{type: string, required: bool, example: string}>}
     */
    private function splitUrl(string $url, ?string $baseUri): array
    {
        [$urlWithoutQuery, $queryString] = explode('?', $url, 2) + [1 => ''];

        if (null !== $baseUri) {
            $baseUri = rtrim($baseUri, '/').'/';
            if (!str_starts_with($urlWithoutQuery.'/', $baseUri)) {
                throw new \InvalidArgumentException(\sprintf('The URL "%s" does not start with the API base URI "%s".', $url, $baseUri));
            }
            $path = substr($urlWithoutQuery, \strlen($baseUri));
        } else {
            $parts = parse_url($urlWithoutQuery);
            if (!isset($parts['scheme'], $parts['host'])) {
                throw new \InvalidArgumentException(\sprintf('Cannot parse the URL "%s".', $url));
            }
            $baseUri = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/';
            $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), 'strlen'));
            while ($segments && preg_match(self::VERSION_SEGMENT, $segments[0])) {
                $baseUri .= array_shift($segments).'/';
            }
            $path = implode('/', $segments);
        }

        $query = [];
        foreach ('' === $queryString ? [] : explode('&', $queryString) as $pair) {
            [$name, $value] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']);
            $query[$name] = ['type' => $this->scalarType($value), 'required' => false, 'example' => $value];
        }

        return [$baseUri, trim($path, '/'), $query];
    }

    /**
     * @param list<string> $segments
     * @param list<string> $review
     *
     * @return array{string, array<string, array{example?: string}>, list<bool>}
     */
    private function templatePath(array $segments, array &$review): array
    {
        $template = [];
        $parameters = [];
        $isParameter = [];

        foreach ($segments as $i => $segment) {
            if (0 < $i && preg_match(self::PLACEHOLDER_SEGMENT, $segment, $m)) {
                $name = u($m[1] ?: $m[2])->camel()->toString();
                $parameters[$name] = [];
            } elseif (0 < $i && preg_match(self::ID_SEGMENT, $segment)) {
                $name = 1 === $i ? 'id' : u($segments[$i - 1])->camel()->toString().'Id';
                $parameters[$name] = ['example' => $segment];
                $review[] = \sprintf('path parameter {%s} guessed from the example value "%s"', $name, $segment);
            } else {
                $template[] = $segment;
                $isParameter[] = false;
                continue;
            }
            $template[] = '{'.$name.'}';
            $isParameter[] = true;
        }

        return [implode('/', $template), $parameters, $isParameter];
    }

    /**
     * @param list<string> $segments
     * @param list<bool>   $isParameter
     */
    private function operationName(string $method, array $segments, array $isParameter): string
    {
        if (1 === \count($segments)) {
            $crud = ['GET' => 'list', 'POST' => 'create'][$method] ?? null;
        } elseif (2 === \count($segments) && $isParameter[1]) {
            $crud = ['GET' => 'get', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'][$method] ?? null;
        }
        if (null !== ($crud ?? null)) {
            return $crud;
        }

        // Action or nested route: named after the path segments that follow the resource.
        $words = [];
        foreach (\array_slice($segments, 1) as $i => $segment) {
            if (!$isParameter[$i + 1]) {
                $words[] = $segment;
            }
        }
        $name = u(implode('-', $words) ?: 'call')->camel()->toString();

        return 'GET' === $method || 'POST' === $method ? $name : strtolower($method).ucfirst($name);
    }

    /**
     * Extracts the authentication scheme and removes its header from $headers.
     *
     * @param array<string, string> $headers
     *
     * @return ?array{type: string, env: string, header?: string}
     */
    private function detectAuth(CurlRequest $request, string $apiName, array &$headers): ?array
    {
        $env = u($apiName)->snake()->upper()->toString().'_API_KEY';

        if (null !== $request->user) {
            return ['type' => 'basic', 'env' => $env];
        }

        foreach ($headers as $name => $value) {
            if (0 === strcasecmp($name, 'Authorization')) {
                unset($headers[$name]);
                if (preg_match('/^Bearer\s/i', $value)) {
                    return ['type' => 'bearer', 'env' => $env];
                }
                if (preg_match('/^Basic\s/i', $value)) {
                    return ['type' => 'basic', 'env' => $env];
                }

                return ['type' => 'header', 'header' => $name, 'env' => $env];
            }
            if (preg_match(self::PLACEHOLDER_VALUE, $value)) {
                unset($headers[$name]);

                return ['type' => 'header', 'header' => $name, 'env' => $env];
            }
        }

        return null;
    }

    /**
     * @return mixed null when the text is not JSON (or is the JSON null)
     */
    private function decode(string $json): mixed
    {
        try {
            return $this->decoder->decode($json, false);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Splits a JSON:API request resource object into its attributes and relationships,
     * which become the parameters of the generated method.
     *
     * @param list<string> $review
     *
     * @return array{array{attributes: array{shape: string}, relationships?: array<string, string>}, ?string} [descriptor, JSON:API type]
     */
    private function describeJsonApiBody(\stdClass $data, array &$review): array
    {
        $body = ['attributes' => ['shape' => $this->shapeGenerator->generate($data->attributes, true)]];
        $review[] = 'request body: all attributes are typed as optional, remove the "?" of the required ones';
        $this->reviewShape($body['attributes']['shape'], 'request body', $review);

        // Related resource types, "stores" for a to-one relationship, "tags[]" for a to-many one.
        foreach (get_object_vars($data->relationships ?? new \stdClass()) as $name => $relationship) {
            $linkage = $relationship->data ?? null;
            $first = \is_array($linkage) ? ($linkage[0] ?? null) : $linkage;
            if (\is_string($first->type ?? null)) {
                $body['relationships'][$name] = $first->type.(\is_array($linkage) ? '[]' : '');
            } else {
                $review[] = \sprintf('request body: the type of the relationship "%s" is unknown', $name);
            }
        }

        return [$body, \is_string($data->type ?? null) ? $data->type : null];
    }

    /**
     * @return array{list<\stdClass>, bool} the resource objects of a JSON:API document, and whether "data" is a list
     */
    private function resourceObjects(mixed $document): array
    {
        $data = $document instanceof \stdClass ? ($document->data ?? null) : null;
        $items = \is_array($data) ? $data : [$data];
        $isResource = static fn ($item) => $item instanceof \stdClass && \is_string($item->type ?? null) && isset($item->attributes);

        return \is_array($data) || $isResource($data)
            ? [array_values(array_filter($items, $isResource)), \is_array($data)]
            : [[], false];
    }

    /**
     * @param list<\stdClass> $items
     */
    private function commonType(array $items): ?string
    {
        $types = array_unique(array_map(static fn ($item) => \is_string($item->type ?? null) ? $item->type : '', $items));

        return 1 === \count($types) && '' !== reset($types) ? reset($types) : null;
    }

    /**
     * @param list<string> $review
     */
    private function reviewShape(string $shape, string $what, array &$review): void
    {
        if (preg_match('/:\s*mixed,/', $shape)) {
            $review[] = \sprintf('%s: "mixed" marks values that are null in the example, their real type is unknown', $what);
        }
        if (str_contains($shape, 'list<mixed>')) {
            $review[] = \sprintf('%s: "list<mixed>" marks empty arrays in the example, they may be empty objects', $what);
        }
    }

    private function scalarType(string $value): string
    {
        return match (true) {
            1 === preg_match('/^-?\d+$/', $value) => 'int',
            \in_array($value, ['true', 'false'], true) => 'bool',
            default => 'string',
        };
    }
}
