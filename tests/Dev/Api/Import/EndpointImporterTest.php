<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Import\EndpointImporter;
use Snorky22\ApiForge\Dev\Api\Import\ImportedEndpoint;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

final class EndpointImporterTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/';

    private function import(string $curl, ?string $response = null, ?string $baseUri = null, ?string $resource = null): ImportedEndpoint
    {
        $importer = new EndpointImporter(new LenientJsonDecoder(), new ArrayShapeGenerator());

        return $importer->import((new CurlParser())->parse($curl), 'lemonsqueezy', $response, $baseUri, $resource);
    }

    public function testImportsListWithQueryAndApiSettings(): void
    {
        $endpoint = $this->import(file_get_contents(self::FIXTURES.'curl-example-2'));

        self::assertSame([
            'base_uri' => 'https://api.lemonsqueezy.com/v1/',
            'format' => 'jsonapi',
            'headers' => ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'],
            'auth' => ['type' => 'bearer', 'env' => 'LEMONSQUEEZY_API_KEY'],
        ], $endpoint->api);
        self::assertSame('orders', $endpoint->resource);
        self::assertSame('list', $endpoint->operationName);
        self::assertSame(['GET', 'orders'], [$endpoint->operation['method'], $endpoint->operation['path']]);
        self::assertSame(['filter[user_email]' => ['type' => 'string', 'required' => false, 'example' => 'johndoe@example.com']], $endpoint->operation['query']);
        self::assertSame(['data' => 'list'], $endpoint->operation['response']);
        self::assertContains('no response example: assumed to be a JSON:API document of the resource', $endpoint->operation['review']);
        self::assertNull($endpoint->object);
    }

    public function testReviewNotesNameTheKeysOfUnknownTypes(): void
    {
        $endpoint = $this->import('curl https://api.test/prices', '{"data": [{"id": "p1", "trial_period": null, "custom_data": null, "unit_price_overrides": [], "nested": {"meta": null, "tags": []}}], "meta": {"cursor": null}}');

        self::assertSame([
            'response: "mixed" marks values that are null in the example, their real type is unknown (data[].trial_period, data[].custom_data, data[].nested.meta, meta.cursor)',
            'response: "list<mixed>" marks empty arrays in the example, they may be empty objects (data[].unit_price_overrides, data[].nested.tags)',
        ], array_values(array_filter($endpoint->operation['review'], static fn (string $note) => str_starts_with($note, 'response:'))));
    }

    public function testJsonApiListResponseDescribesTheResourceObject(): void
    {
        $endpoint = $this->import(file_get_contents(self::FIXTURES.'curl-example-1'), file_get_contents(self::FIXTURES.'json-example.json'));

        self::assertSame('orders', $endpoint->type);
        self::assertSame(['data' => 'list'], $endpoint->operation['response']);
        self::assertStringStartsWith("array{\n    type: string,\n    id: string,\n    attributes: array{\n        store_id: int,", $endpoint->object['shape']);
        self::assertSame(['object: "mixed" marks values that are null in the example, their real type is unknown (attributes.refunded_at, attributes.first_order_item.deleted_at)'], $endpoint->object['review']);
    }

    public function testImportsJsonApiCreate(): void
    {
        $endpoint = $this->import(file_get_contents(self::FIXTURES.'curl-example-3'), file_get_contents(self::FIXTURES.'json-example-2.json'));

        self::assertSame('create', $endpoint->operationName);
        self::assertSame('checkouts', $endpoint->type);
        self::assertStringContainsString('    custom_price?: int,', $endpoint->operation['body']['attributes']['shape']);
        self::assertSame(['store' => 'stores', 'variant' => 'variants'], $endpoint->operation['body']['relationships']);
        self::assertSame(['data' => 'object'], $endpoint->operation['response']);
        self::assertStringContainsString('url: string,', $endpoint->object['shape']);
        self::assertSame(['request body: all attributes are typed as optional, remove the "?" of the required ones'], $endpoint->operation['review']);
        self::assertSame(['object: "list<mixed>" marks empty arrays in the example, they may be empty objects (attributes.product_options.media, attributes.checkout_data.billing_address, attributes.checkout_data.custom, attributes.checkout_data.variant_quantities)'], $endpoint->object['review']);
    }

    public function testActionDocumentWithIdIdentifiesTheResource(): void
    {
        $endpoint = $this->import(<<<'CURL'
            curl -X "POST" "https://api.lemonsqueezy.com/v1/orders/1/refund" \
              -H 'Accept: application/vnd.api+json' \
              -H 'Content-Type: application/vnd.api+json' \
              -d '{"data": {"type": "orders", "id": "1", "attributes": {"amount": 100}}}'
            CURL);

        self::assertSame('refund', $endpoint->operationName);
        self::assertTrue($endpoint->operation['body']['id']);
        self::assertArrayNotHasKey('id', $this->import(file_get_contents(self::FIXTURES.'curl-example-3'))->operation['body']);
    }

    public function testSandboxAndLiveHosts(): void
    {
        $importer = new EndpointImporter(new LenientJsonDecoder(), new ArrayShapeGenerator());
        $curl = (new CurlParser())->parse('curl https://sandbox-api.paddle.com/products -H "Authorization: Bearer KEY"');

        // A first import from the sandbox: the live host is suggested
        $endpoint = $importer->import($curl, 'paddle');
        self::assertSame('https://sandbox-api.paddle.com/', $endpoint->api['base_uri']);
        self::assertContains('base URI: "https://sandbox-api.paddle.com/" looks like a sandbox: set the live host as base_uri, this one under environments, and base_uri_env to choose at runtime', $endpoint->operation['review'] ?? []);

        // The sandbox listed under environments: its URLs are imported against it
        $endpoint = $importer->import($curl, 'paddle', null, ['https://api.paddle.com/', 'https://sandbox-api.paddle.com/']);
        self::assertSame('products', $endpoint->operation['path']);

        $this->expectExceptionMessage('does not start with a base URI of the API: https://api.paddle.com/ (other hosts go under "environments" in _api.yaml)');
        $importer->import($curl, 'paddle', null, 'https://api.paddle.com/');
    }

    public function testToManyRelationship(): void
    {
        $curl = <<<'CURL'
            curl -X POST https://x.test/v1/posts -H 'Content-Type: application/vnd.api+json' \
              -d '{"data": {"type": "posts", "attributes": {"title": "t"}, "relationships": {"tags": {"data": [{"type": "tags", "id": "1"}]}}}}'
            CURL;

        self::assertSame(['tags' => 'tags[]'], $this->import($curl)->operation['body']['relationships']);
    }

    public function testPlainJsonBodyAndResponse(): void
    {
        $endpoint = $this->import("curl https://x.test/users -d '{\"name\": \"a\"}'", '{"id": 1, "name": "a"}');

        self::assertSame('json', $endpoint->api['format']);
        self::assertSame(['shape' => "array{\n    name: string,\n}"], $endpoint->operation['body']);
        self::assertSame(['shape' => "array{\n    id: int,\n    name: string,\n}"], $endpoint->operation['response']);
        self::assertNull($endpoint->object);
    }

    public function testImportsStandaloneObject(): void
    {
        $importer = new EndpointImporter(new LenientJsonDecoder(), new ArrayShapeGenerator());

        [$type, $object] = $importer->importObject(file_get_contents(self::FIXTURES.'customer-object.json'));

        self::assertSame('customers', $type);
        self::assertStringContainsString("        city: mixed,\n", $object['shape']);
        self::assertSame(['object: "mixed" marks values that are null in the example, their real type is unknown (attributes.city, attributes.region)'], $object['review']);
    }

    public function testImportsObjectFromDocument(): void
    {
        $importer = new EndpointImporter(new LenientJsonDecoder(), new ArrayShapeGenerator());

        [$type, $object] = $importer->importObject('{"data": [{"type": "tags", "id": "1", "attributes": {"name": "a"}}, {"type": "tags", "id": "2", "attributes": {"name": "b", "color": "red"}}]}');

        self::assertSame('tags', $type);
        self::assertStringContainsString("        color?: string,\n", $object['shape']);
    }

    public static function routeProvider(): iterable
    {
        yield 'get' => ['GET', '/v1/orders/1', 'get', 'orders/{id}', ['id' => ['example' => '1']]];
        yield 'update' => ['PATCH', '/v1/orders/1', 'update', 'orders/{id}', ['id' => ['example' => '1']]];
        yield 'delete by uuid' => ['DELETE', '/v1/checkouts/5e8b546c-c561-4a2c-a586-40c18bb2a195', 'delete', 'checkouts/{id}', ['id' => ['example' => '5e8b546c-c561-4a2c-a586-40c18bb2a195']]];
        yield 'nested route' => ['GET', '/v1/orders/1/order-items', 'orderItems', 'orders/{id}/order-items', ['id' => ['example' => '1']]];
        yield 'action' => ['POST', '/v1/subscription-invoices/1/generate-invoice', 'generateInvoice', 'subscription-invoices/{id}/generate-invoice', ['id' => ['example' => '1']]];
        yield 'documented placeholder' => ['GET', '/v1/orders/{order_id}', 'get', 'orders/{orderId}', ['orderId' => []]];
        yield 'second parameter' => ['DELETE', '/v1/orders/1/order-items/2', 'deleteOrderItems', 'orders/{id}/order-items/{orderItemsId}', ['id' => ['example' => '1'], 'orderItemsId' => ['example' => '2']]];
    }

    #[DataProvider('routeProvider')]
    public function testInfersOperationAndPathTemplate(string $method, string $path, string $name, string $template, array $parameters): void
    {
        $endpoint = $this->import(\sprintf('curl -X %s "https://api.lemonsqueezy.com%s"', $method, $path));

        self::assertSame($name, $endpoint->operationName);
        self::assertSame($template, $endpoint->operation['path']);
        self::assertSame($parameters, $endpoint->operation['parameters']);
    }

    public function testNestedRouteDoesNotSetResourceType(): void
    {
        $response = '{"data": [{"type": "order-items", "id": "1"}]}';

        self::assertNull($this->import('curl https://api.lemonsqueezy.com/v1/orders/1/order-items', $response)->type);
    }

    public function testUsesKnownBaseUri(): void
    {
        $endpoint = $this->import('curl https://x.test/rest/2/users', null, 'https://x.test/rest/2');

        self::assertSame('https://x.test/rest/2/', $endpoint->api['base_uri']);
        self::assertSame('users', $endpoint->operation['path']);
    }

    public function testRejectsUrlOutsideBaseUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->import('curl https://other.test/v1/users', null, 'https://x.test/v1/');
    }

    public function testDetectsApiKeyHeader(): void
    {
        $endpoint = $this->import("curl https://x.test/users -H 'X-Api-Key: <your-key>' -H 'Accept: application/json'");

        self::assertSame(['type' => 'header', 'header' => 'X-Api-Key', 'env' => 'LEMONSQUEEZY_API_KEY'], $endpoint->api['auth']);
        self::assertSame(['Accept' => 'application/json'], $endpoint->api['headers']);
        self::assertSame('json', $endpoint->api['format']);
    }
}
