<?php

namespace Snorky22\ApiForge\Tests\App;

use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The APIs of the test application (framework.http_client.mock_response_factory): an empty JSON:API list,
 * or the response set by a test.
 */
final class ApiMock
{
    public ?ResponseInterface $next = null;

    /** @var list<string> */
    public array $urls = [];

    public function __invoke(string $method, string $url): ResponseInterface
    {
        $this->urls[] = $method.' '.$url;
        $response = $this->next ?? new JsonMockResponse(['data' => []]);
        $this->next = null;

        return $response;
    }
}
