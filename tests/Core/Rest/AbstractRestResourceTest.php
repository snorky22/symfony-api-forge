<?php

namespace Snorky22\ApiForge\Tests\Core\Rest;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Core\AbstractResource;
use Snorky22\ApiForge\Core\ApiException;
use Snorky22\ApiForge\Core\Rest\Crud;
use Snorky22\ApiForge\Core\Rest\Deletable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

require_once __DIR__.'/RestResources.php';

final class AbstractRestResourceTest extends TestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    private static function client(array $responses): HttpClientInterface
    {
        return ScopingHttpClient::forBaseUri(new MockHttpClient($responses), 'https://api.test/');
    }

    public function testList(): void
    {
        $response = new JsonMockResponse(['data' => [['id' => 'pri_1', 'status' => 'active']], 'meta' => []]);

        $page = (new Prices(self::client([$response])))->list(['product_id' => 'pro_1', 'after' => null]);

        self::assertSame('pri_1', $page['data'][0]['id']);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://api.test/prices?product_id=pro_1', $response->getRequestUrl());
    }

    public function testGetEncodesTheId(): void
    {
        $response = new JsonMockResponse(['data' => ['id' => 'pri 1', 'status' => 'active']]);

        (new Prices(self::client([$response])))->get('pri 1', ['include' => 'product']);

        self::assertSame('https://api.test/prices/pri%201?include=product', $response->getRequestUrl());
    }

    public function testCreateSendsTheBodyAsJson(): void
    {
        $response = new JsonMockResponse(['data' => ['id' => 'pri_1', 'status' => 'active']], ['http_code' => 201]);

        $created = (new Prices(self::client([$response])))->create(['product_id' => 'pro_1', 'description' => 'Monthly']);

        self::assertSame('pri_1', $created['data']['id']);
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://api.test/prices', $response->getRequestUrl());
        self::assertSame('{"product_id":"pro_1","description":"Monthly"}', $response->getRequestOptions()['body']);
    }

    public function testUpdateAndAMethodOfItsOwn(): void
    {
        $response = new JsonMockResponse(['data' => ['id' => 'pri_1', 'status' => 'archived']]);

        (new Prices(self::client([$response])))->archive('pri_1');

        self::assertSame('PATCH', $response->getRequestMethod());
        self::assertSame('https://api.test/prices/pri_1', $response->getRequestUrl());
        self::assertSame('{"status":"archived"}', $response->getRequestOptions()['body']);
    }

    public function testCrudExtendsTheFiveInterfaces(): void
    {
        $deleted = new MockResponse('', ['http_code' => 204]);
        $collections = new Collections(self::client([$deleted]));

        self::assertInstanceOf(Crud::class, $collections);
        self::assertInstanceOf(Deletable::class, $collections);

        $collections->delete(7);

        self::assertSame('DELETE', $deleted->getRequestMethod());
        self::assertSame('https://api.test/collections/7', $deleted->getRequestUrl());
    }

    public function testErrorStatusThrowsApiException(): void
    {
        $prices = new Prices(self::client([new JsonMockResponse(['message' => 'Price not found'], ['http_code' => 404])]));

        try {
            $prices->get('pri_x');
            self::fail('An ApiException was expected.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->statusCode);
            self::assertSame('HTTP 404 returned for "https://api.test/prices/pri_x": Price not found', $e->getMessage());
        }
    }

    public function testAResourceWithoutInterfaceUsesRequest(): void
    {
        $response = new JsonMockResponse(['data' => [['title' => 'Result']]]);
        $search = new class(self::client([$response])) extends AbstractResource {
            /**
             * @return array{data: list<array{title: string, ...}>, ...}
             */
            public function search(string $q): array
            {
                return $this->request('GET', 'search', query: ['q' => $q]);
            }
        };

        self::assertSame('Result', $search->search('symfony')['data'][0]['title']);
        self::assertSame('https://api.test/search?q=symfony', $response->getRequestUrl());
    }
}
