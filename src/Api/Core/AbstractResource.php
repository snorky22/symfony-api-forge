<?php

namespace App\Api\Core;

use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class of the generated API resources.
 *
 * The HTTP client is expected to be scoped to the API (base URI, authentication, default headers).
 */
abstract class AbstractResource
{
    public function __construct(
        protected readonly HttpClientInterface $client,
    ) {
    }

    /**
     * Sends a request and returns the decoded JSON response, or [] when the response has no content.
     *
     * @param string                    $path       relative to the base URI with {name} placeholders, or an absolute URL
     * @param array<string, int|string> $parameters values of the path placeholders
     * @param array<string, mixed>      $query      null values are left out
     * @param mixed                     $json       JSON body
     * @param ?string                   $body       raw body
     * @param array<string, string>     $headers    headers added to the client's default ones
     *
     * @return array<mixed>
     *
     * @throws ApiException when the API answers with an error status or invalid JSON
     */
    protected function request(
        string $method,
        string $path,
        array $parameters = [],
        array $query = [],
        mixed $json = null,
        ?string $body = null,
        array $headers = [],
    ): array {
        $url = str_contains($path, '://') ? $path : preg_replace_callback('/\{(\w+)\}/', static function (array $match) use ($parameters): string {
            if (!isset($parameters[$match[1]])) {
                throw new \InvalidArgumentException(\sprintf('Missing value for the path parameter "%s".', $match[1]));
            }

            return rawurlencode((string) $parameters[$match[1]]);
        }, $path);

        $options = ['query' => array_filter($query, static fn ($value) => null !== $value)];
        if (null !== $json) {
            $options['json'] = $json;
        }
        if (null !== $body) {
            $options['body'] = $body;
        }
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }

        $response = $this->client->request($method, $url, $options);
        try {
            return '' === $response->getContent() ? [] : $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw ApiException::fromResponse($e->getResponse(), $e);
        } catch (DecodingExceptionInterface $e) {
            throw new ApiException(\sprintf('Invalid JSON returned for %s "%s": %s', $method, $url, $e->getMessage()), $response->getStatusCode(), [], $e);
        }
    }
}
