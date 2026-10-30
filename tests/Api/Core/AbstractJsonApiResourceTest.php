<?php

namespace App\Tests\Api\Core;

use App\Api\Core\AbstractJsonApiResource;
use App\Api\Core\ApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;

final class AbstractJsonApiResourceTest extends TestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    private function resource(array $responses): TestResource
    {
        return new TestResource(ScopingHttpClient::forBaseUri(new MockHttpClient($responses), 'https://api.test/v1/', [
            'headers' => ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'],
        ]));
    }

    public function testRequestBuildsUrlAndDropsNullQueryValues(): void
    {
        $response = new JsonMockResponse(['data' => []]);

        self::assertSame(['data' => []], $this->resource([$response])->call('GET', 'orders/{id}/order-items', ['id' => 'a b'], ['filter[x]' => 1, 'y' => null]));
        self::assertSame('https://api.test/v1/orders/a%20b/order-items?filter[x]=1', $response->getRequestUrl());
    }

    public function testJsonBodyKeepsJsonApiContentType(): void
    {
        $response = new JsonMockResponse(['data' => []]);

        $this->resource([$response])->call('POST', 'orders', json: ['data' => ['type' => 'orders']]);

        self::assertContains('Content-Type: application/vnd.api+json', $response->getRequestOptions()['headers']);
        self::assertSame('{"data":{"type":"orders"}}', $response->getRequestOptions()['body']);
    }

    public function testMissingPathParameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource([])->call('GET', 'orders/{id}');
    }

    public function testEmptyResponseGivesEmptyArray(): void
    {
        self::assertSame([], $this->resource([new MockResponse('', ['http_code' => 204])])->call('DELETE', 'orders/{id}', ['id' => 1]));
    }

    public function testErrorResponseThrowsApiExceptionWithDetails(): void
    {
        $resource = $this->resource([new JsonMockResponse(['errors' => [['detail' => 'The name field is required.'], ['title' => 'Bad']]], ['http_code' => 422])]);

        try {
            $resource->call('POST', 'orders', json: ['data' => []]);
            self::fail('No exception thrown.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame('HTTP 422 returned for "https://api.test/v1/orders": The name field is required.; Bad', $e->getMessage());
            self::assertSame('The name field is required.', $e->content['errors'][0]['detail']);
        }
    }

    public function testInvalidJsonThrowsApiException(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->resource([new MockResponse('<html>')])->call('GET', 'orders');
    }

    public function testDocument(): void
    {
        $document = $this->resource([])->buildDocument(['name' => 'a'], ['store' => 1, 'tags' => [1, '2'], 'variant' => null], ['store' => 'stores', 'tags' => 'tags[]', 'variant' => 'variants'], 5);

        self::assertSame(['data' => [
            'type' => 'customers',
            'id' => '5',
            'attributes' => ['name' => 'a'],
            'relationships' => [
                'store' => ['data' => ['type' => 'stores', 'id' => '1']],
                'tags' => ['data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']]],
                'variant' => ['data' => null],
            ],
        ]], $document);
    }

    public function testDocumentRejectsUnknownRelationship(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown relationship "shop"');

        $this->resource([])->buildDocument([], ['shop' => 1], ['store' => 'stores']);
    }

    public function testPaginateFollowsNextLinks(): void
    {
        $first = new JsonMockResponse(['data' => [['id' => '1'], ['id' => '2']], 'links' => ['next' => 'https://api.test/v1/customers?page%5Bnumber%5D=2']]);
        $second = new JsonMockResponse(['data' => [['id' => '3']], 'links' => ['next' => null]]);

        $objects = iterator_to_array($this->resource([$first, $second])->all('customers', ['filter[store_id]' => 1]));

        self::assertSame(['1', '2', '3'], array_column($objects, 'id'));
        self::assertSame('https://api.test/v1/customers?filter[store_id]=1', $first->getRequestUrl());
        self::assertSame('https://api.test/v1/customers?page%5Bnumber%5D=2', $second->getRequestUrl());
    }

    public function testPaginateStopsOnRepeatedLink(): void
    {
        $page = ['data' => [['id' => '1']], 'links' => ['next' => 'https://api.test/v1/customers?page=2']];

        $objects = iterator_to_array($this->resource([new JsonMockResponse($page), new JsonMockResponse($page)])->all('customers'));

        self::assertCount(2, $objects);
    }
}

/**
 * Exposes the protected API of the base class.
 */
final class TestResource extends AbstractJsonApiResource
{
    protected const TYPE = 'customers';

    public function call(string $method, string $path, array $parameters = [], array $query = [], mixed $json = null): array
    {
        return $this->request($method, $path, $parameters, $query, $json);
    }

    public function buildDocument(array $attributes, array $relationships = [], array $relationshipTypes = [], int|string|null $id = null): array
    {
        return $this->document($attributes, $relationships, $relationshipTypes, $id);
    }

    public function all(string $path, array $query = []): \Generator
    {
        return $this->paginate($path, [], $query);
    }
}
