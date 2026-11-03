<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Descriptor;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Descriptor\ApiDefinition;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Descriptor\InvalidDescriptorException;
use Snorky22\ApiForge\Dev\Api\Descriptor\ShapeValidator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class DescriptorLoaderTest extends TestCase
{
    private const API = [
        'base_uri' => 'https://x.test/v1',
        'format' => 'jsonapi',
        'headers' => ['Content-Type' => 'application/vnd.api+json'],
        'auth' => ['type' => 'bearer', 'env' => 'SHOP_API_KEY'],
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/descriptor-loader-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /**
     * @param array<string, array> $resources
     */
    private function load(array $api, array $resources, ?array $webhooks = null): ApiDefinition
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir.'/shop/_api.yaml', Yaml::dump($api, 10));
        if (null !== $webhooks) {
            $filesystem->dumpFile($this->dir.'/shop/_webhooks.yaml', Yaml::dump($webhooks, 10));
        }
        foreach ($resources as $name => $resource) {
            $filesystem->dumpFile($this->dir.'/shop/'.$name.'.yaml', Yaml::dump($resource, 10));
        }

        return (new DescriptorLoader(new DescriptorStore($this->dir), new ShapeValidator()))->load('shop');
    }

    /**
     * @return list<string>
     */
    private function errors(array $api, array $resources, ?array $webhooks = null): array
    {
        try {
            $this->load($api, $resources, $webhooks);
        } catch (InvalidDescriptorException $e) {
            return array_map(fn (string $error) => str_replace($this->dir.'/shop/', '', $error), $e->errors);
        }
        self::fail('The descriptors are valid.');
    }

    public function testLoadsAndNormalizes(): void
    {
        $definition = $this->load(self::API + ['review' => ['check auth']], ['order-items' => [
            'resource' => 'order-items',
            'type' => 'order-items',
            'object' => ['shape' => 'array{id: string}', 'review' => ['check object']],
            'operations' => [
                'list' => ['method' => 'get', 'path' => 'order-items', 'query' => ['filter[order_id]' => ['type' => 'int']], 'response' => ['data' => 'list']],
                'update' => [
                    'method' => 'PATCH',
                    'path' => 'order-items/{id}',
                    'parameters' => ['id' => ['example' => '1']],
                    'body' => ['attributes' => ['shape' => 'array{quantity?: int}'], 'relationships' => ['order' => 'orders']],
                    'review' => ['check body'],
                ],
            ],
        ]]);

        self::assertSame('Shop', $definition->namespace);
        self::assertSame('https://x.test/v1/', $definition->api['base_uri']);
        self::assertSame(['Content-Type' => 'application/vnd.api+json'], $definition->api['headers']);
        $list = $definition->resources['order-items']['operations']['list'];
        self::assertSame('GET', $list['method']);
        self::assertSame(['filter[order_id]' => ['type' => 'int', 'required' => false, 'example' => null, 'description' => null]], $list['query']);
        self::assertSame([
            '_api.yaml: check auth',
            'order-items.yaml: object: check object',
            'order-items.yaml: operation "update": check body',
        ], array_map(fn (string $note) => str_replace($this->dir.'/shop/', '', $note), $definition->review));
    }

    public function testCustomNamespace(): void
    {
        self::assertSame('MyShop', $this->load(['namespace' => 'MyShop'] + self::API, [])->namespace);
    }

    public function testCollectsAllErrors(): void
    {
        $errors = $this->errors(['format' => 'json'] + self::API, [
            'orders' => ['resource' => 'orders', 'operations' => [
                'request' => ['method' => 'GET', 'path' => 'orders'],
                'get' => ['method' => 'GET', 'path' => 'orders/{id}', 'response' => ['shape' => 'array{id: int']],
                'update' => ['method' => 'PATCH', 'path' => 'orders/{id}', 'parameters' => ['id' => [], 'uuid' => []], 'body' => ['attributes' => ['shape' => 'array{}']]],
                'search' => ['method' => 'GET', 'path' => 'orders', 'query' => ['page[size]' => [], 'page_size' => []], 'response' => ['data' => 'list']],
            ]],
            'customers' => ['resource' => 'clients', 'operations' => []],
        ]);

        self::assertSame([
            'customers.yaml: "resource" is "clients", it must match the file name "customers".',
            'orders.yaml: operation "request": "request" cannot be used as a method name (reserved: request, paginate, document, iterate).',
            'orders.yaml: operation "get": path parameters {id} are not declared in "parameters".',
            'orders.yaml: operation "get": "response.shape" is not a valid PHPDoc type: Unexpected token "", expected \'}\' at offset 13 on line 1',
            'orders.yaml: operation "update": parameters "uuid" do not appear in the path.',
            'orders.yaml: operation "update": "body.attributes" requires the "jsonapi" API format.',
            'orders.yaml: operation "search": several parameters would be named "$pageSize".',
            'orders.yaml: operation "search": "response.data" requires the "jsonapi" API format.',
        ], $errors);
    }

    public function testSchemaErrors(): void
    {
        $errors = $this->errors(self::API, ['orders' => ['resource' => 'orders', 'operations' => [
            'list' => ['method' => 'FETCH', 'path' => 'orders'],
        ]]]);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('orders.yaml: The value "FETCH" is not allowed for path "resource.operations.list.method"', $errors[0]);
    }

    public function testBodyNeedsExactlyOneKind(): void
    {
        $errors = $this->errors(self::API, ['orders' => ['resource' => 'orders', 'type' => 'orders', 'operations' => [
            'create' => ['method' => 'POST', 'path' => 'orders', 'body' => ['shape' => 'array{}', 'raw' => 'x']],
        ]]]);

        self::assertStringContainsString('Exactly one of "shape", "raw" or "attributes" is required.', $errors[0]);
    }

    public function testAttributesNeedResourceType(): void
    {
        $errors = $this->errors(self::API, ['orders' => ['resource' => 'orders', 'operations' => [
            'create' => ['method' => 'POST', 'path' => 'orders', 'body' => ['attributes' => ['shape' => 'array{}']]],
        ]]]);

        self::assertSame(['orders.yaml: operation "create": "body.attributes" requires the resource "type".'], $errors);
    }

    public function testDoctorProbe(): void
    {
        $orders = ['resource' => 'orders', 'operations' => [
            'list' => ['method' => 'GET', 'path' => 'orders'],
            'get' => ['method' => 'GET', 'path' => 'orders/{id}', 'parameters' => ['id' => []]],
            'create' => ['method' => 'POST', 'path' => 'orders', 'body' => ['shape' => 'array{a: int}']],
        ]];

        $definition = $this->load(self::API + ['doctor' => ['probe' => 'orders.get', 'parameters' => ['id' => '%env(ORDER_ID)%'], 'review' => ['check it']]], ['orders' => $orders]);
        self::assertSame(['probe' => 'orders.get', 'parameters' => ['id' => '%env(ORDER_ID)%'], 'review' => ['check it']], $definition->api['doctor']);
        self::assertContains('_api.yaml: doctor: check it', array_map(fn (string $note) => str_replace($this->dir.'/shop/', '', $note), $definition->review));

        self::assertSame(['_api.yaml: doctor.probe "orders.create" is a POST operation: the probe must be a harmless GET.'], $this->errors(self::API + ['doctor' => ['probe' => 'orders.create']], ['orders' => $orders]));
        self::assertSame(['_api.yaml: doctor.probe "orders.cancel" is not an operation of the API.'], $this->errors(self::API + ['doctor' => ['probe' => 'orders.cancel']], ['orders' => $orders]));
        self::assertSame(['_api.yaml: doctor.parameters must give the path parameters of the probe "orders.get": id.'], $this->errors(self::API + ['doctor' => ['probe' => 'orders.get']], ['orders' => $orders]));
    }

    public function testLoadsWebhooks(): void
    {
        $definition = $this->load(self::API, [
            'orders' => ['resource' => 'orders', 'type' => 'orders', 'object' => ['shape' => 'array{id: string}'], 'operations' => []],
        ], [
            'signature' => ['header' => 'X-Signature', 'env' => 'SHOP_WEBHOOK_SECRET'],
            'event_name' => ['path' => 'meta.event_name'],
            'meta' => ['shape' => 'array{event_name: string}'],
            'events' => [
                'order_created' => ['resource' => 'orders', 'description' => 'New order'],
                'subscription_created' => ['resource' => 'subscriptions'],
                'subscription_updated' => ['resource' => 'subscriptions'],
            ],
            'review' => ['check signature'],
        ]);

        self::assertSame(['header' => 'X-Signature', 'env' => 'SHOP_WEBHOOK_SECRET', 'algorithm' => 'sha256', 'encoding' => 'hex', 'prefix' => ''], $definition->webhooks['signature']);
        self::assertSame(['path' => 'meta.event_name', 'header' => null], $definition->webhooks['event_name']);
        self::assertSame(['resource' => 'orders', 'description' => 'New order', 'shape' => null, 'review' => []], $definition->webhooks['events']['order_created']);
        self::assertSame([
            '_webhooks.yaml: check signature',
            '_webhooks.yaml: the resource "subscriptions" has no object shape: the payload data of 2 events is untyped (import the resource object with api-forge:import-object)',
        ], array_map(fn (string $note) => str_replace($this->dir.'/shop/', '', $note), $definition->review));
    }

    public function testWebhookErrors(): void
    {
        $errors = $this->errors(['format' => 'json'] + self::API, [], [
            'signature' => ['header' => 'X-Signature', 'algorithm' => 'sha256', 'env' => 'SHOP_WEBHOOK_SECRET'],
            'event_name' => ['path' => 'type'],
            'events' => [
                'order.created' => ['resource' => 'orders'],
                'order_created' => ['shape' => 'array{'],
            ],
        ]);

        self::assertSame([
            '_webhooks.yaml: event "order.created": "resource" requires the "jsonapi" API format, use "shape" instead.',
            '_webhooks.yaml: event "order_created": its constant "ORDER_CREATED" or method "onOrderCreated" is invalid or already used by "order.created".',
            '_webhooks.yaml: event "order_created": "shape" is not a valid PHPDoc type: Unexpected token "", expected type at offset 6 on line 1',
        ], $errors);
    }

    public function testWebhookSchemaErrors(): void
    {
        $errors = $this->errors(self::API, [], [
            'signature' => ['header' => 'X-Signature', 'algorithm' => 'nope', 'env' => 'SHOP_WEBHOOK_SECRET'],
            'event_name' => ['path' => 'type', 'header' => 'X-Event'],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Unknown HMAC algorithm "nope".', $errors[0]);
    }

    public function testMissingApiFile(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        $this->expectExceptionMessage('_api.yaml: file not found.');

        (new DescriptorLoader(new DescriptorStore($this->dir), new ShapeValidator()))->load('shop');
    }
}
