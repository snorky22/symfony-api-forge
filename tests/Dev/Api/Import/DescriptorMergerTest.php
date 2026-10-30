<?php

namespace App\Tests\Dev\Api\Import;

use App\Dev\Api\Import\DescriptorMerger;
use App\Dev\Api\Import\ImportedEndpoint;
use PHPUnit\Framework\TestCase;

final class DescriptorMergerTest extends TestCase
{
    private const API = [
        'base_uri' => 'https://x.test/v1/',
        'format' => 'json',
        'headers' => ['Accept' => 'application/json'],
    ];

    private function endpoint(array $operation, string $name = 'list', array $api = self::API, ?string $type = null): ImportedEndpoint
    {
        return new ImportedEndpoint($api, 'orders', $type, $name, $operation + ['method' => 'GET', 'path' => 'orders']);
    }

    public function testCreatesDescriptors(): void
    {
        $result = (new DescriptorMerger())->merge(null, null, $this->endpoint(['review' => ['x']], type: 'orders'));

        self::assertSame(self::API, $result['api']);
        self::assertSame([
            'resource' => 'orders',
            'type' => 'orders',
            'operations' => ['list' => ['method' => 'GET', 'path' => 'orders', 'review' => ['x']]],
        ], $result['resource']);
        self::assertSame(['operation "list" added'], $result['notes']);
    }

    public function testMergeKeepsExistingValuesAndAddsQuery(): void
    {
        $resource = ['resource' => 'orders', 'operations' => ['list' => [
            'method' => 'GET',
            'path' => 'orders',
            'query' => ['page' => ['type' => 'int', 'required' => true]],
            'response' => ['shape' => 'edited by hand'],
            'review' => ['no response example'],
        ]]];
        $endpoint = $this->endpoint([
            'query' => ['page' => ['type' => 'string', 'required' => false], 'sort' => ['type' => 'string', 'required' => false]],
            'response' => ['shape' => 'generated'],
            'review' => ['query parameters are assumed optional'],
        ]);

        $operation = (new DescriptorMerger())->merge(self::API, $resource, $endpoint)['resource']['operations']['list'];

        self::assertSame([
            'method' => 'GET',
            'path' => 'orders',
            'query' => ['page' => ['type' => 'int', 'required' => true], 'sort' => ['type' => 'string', 'required' => false]],
            'response' => ['shape' => 'edited by hand'],
            'review' => ['query parameters are assumed optional'],
        ], $operation);
    }

    public function testDifferentHeadersBecomeOperationHeaders(): void
    {
        $api = ['headers' => ['Accept' => 'text/csv', 'X-Extra' => '1']] + self::API;

        $result = (new DescriptorMerger())->merge(self::API, null, $this->endpoint([], api: $api));

        self::assertSame(self::API, $result['api']);
        self::assertSame(['Accept' => 'text/csv', 'X-Extra' => '1'], $result['resource']['operations']['list']['headers']);
    }

    public function testConflictingOperationIsRejected(): void
    {
        $resource = ['resource' => 'orders', 'operations' => ['list' => ['method' => 'GET', 'path' => 'orders']]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--operation');

        (new DescriptorMerger())->merge(self::API, $resource, $this->endpoint(['path' => 'orders/{id}/items']));
    }

    public function testForceReplacesOperation(): void
    {
        $resource = ['resource' => 'orders', 'operations' => ['list' => ['method' => 'GET', 'path' => 'orders', 'review' => ['old']]]];

        $result = (new DescriptorMerger())->merge(self::API, $resource, $this->endpoint(['path' => 'orders/all']), true);

        self::assertSame(['method' => 'GET', 'path' => 'orders/all'], $result['resource']['operations']['list']);
        self::assertSame(['operation "list" replaced'], $result['notes']);
    }

    public function testRealResponseExampleSettlesAssumedOne(): void
    {
        $resource = ['resource' => 'orders', 'operations' => ['list' => [
            'method' => 'GET',
            'path' => 'orders',
            'response' => ['data' => 'list'],
            'review' => ['no response example: assumed to be a JSON:API document of the resource'],
        ]]];
        $endpoint = new ImportedEndpoint(self::API, 'orders', 'orders', 'list', ['method' => 'GET', 'path' => 'orders', 'response' => ['data' => 'list']], ['shape' => 'array{id: string}']);

        $result = (new DescriptorMerger())->merge(self::API, $resource, $endpoint);

        self::assertArrayNotHasKey('review', $result['resource']['operations']['list']);
        self::assertSame(['shape' => 'array{id: string}'], $result['resource']['object']);
        self::assertSame(['resource', 'type', 'object', 'operations'], array_keys($result['resource']));
    }

    public function testMissingResponseExampleIsIgnoredWhenResponseIsKnown(): void
    {
        $resource = ['resource' => 'orders', 'operations' => ['list' => ['method' => 'GET', 'path' => 'orders', 'response' => ['data' => 'list']]]];
        $endpoint = $this->endpoint(['response' => ['data' => 'list'], 'review' => ['no response example: assumed to be a JSON:API document of the resource']]);

        self::assertArrayNotHasKey('review', (new DescriptorMerger())->merge(self::API, $resource, $endpoint)['resource']['operations']['list']);
    }

    public function testExistingObjectIsKeptUnlessForced(): void
    {
        $resource = ['resource' => 'customers', 'object' => ['shape' => 'edited'], 'operations' => []];
        $merger = new DescriptorMerger();

        self::assertSame(['shape' => 'edited'], $merger->mergeObject($resource, 'customers', 'customers', ['shape' => 'new'])['resource']['object']);
        self::assertSame(['shape' => 'new'], $merger->mergeObject($resource, 'customers', 'customers', ['shape' => 'new'], true)['resource']['object']);
    }

    public function testTypeMismatchIsReported(): void
    {
        $resource = ['resource' => 'orders', 'type' => 'orders', 'operations' => []];

        $result = (new DescriptorMerger())->merge(self::API, $resource, $this->endpoint([], type: 'order-items'));

        self::assertSame('orders', $result['resource']['type']);
        self::assertContains('JSON:API type "order-items" differs from the resource type "orders"', $result['notes']);
    }
}
