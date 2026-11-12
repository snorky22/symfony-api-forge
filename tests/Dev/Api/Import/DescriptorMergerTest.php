<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Import;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Import\DescriptorMerger;
use Snorky22\ApiForge\Dev\Api\Import\ImportedEndpoint;

final class DescriptorMergerTest extends TestCase
{
    private const API = [
        'base_uri' => 'https://x.test/v1/',
        'format' => 'json',
        'headers' => ['Accept' => 'application/json'],
    ];

    /** The probe guessed for the first GET without parameters */
    private const DOCTOR = ['doctor' => ['probe' => 'orders.list', 'review' => ['probe guessed: "orders.list" is a GET without parameters, check that it is harmless and cheap']]];

    private function endpoint(array $operation, string $name = 'list', array $api = self::API, ?string $type = null): ImportedEndpoint
    {
        return new ImportedEndpoint($api, 'orders', $type, $name, $operation + ['method' => 'GET', 'path' => 'orders']);
    }

    public function testCreatesDescriptors(): void
    {
        $result = (new DescriptorMerger())->merge(null, null, $this->endpoint(['review' => ['x']], type: 'orders'));

        self::assertSame(self::API + self::DOCTOR, $result['api']);
        self::assertSame([
            'resource' => 'orders',
            'type' => 'orders',
            'operations' => ['list' => ['method' => 'GET', 'path' => 'orders', 'review' => ['x']]],
        ], $result['resource']);
        self::assertSame(['operation "list" added', 'doctor probe: "orders.list"'], $result['notes']);
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

        self::assertSame(self::API + self::DOCTOR, $result['api']);
        self::assertSame(['Accept' => 'text/csv', 'X-Extra' => '1'], $result['resource']['operations']['list']['headers']);
    }

    public function testProbeIsGuessedOnceForAHarmlessGet(): void
    {
        $merger = new DescriptorMerger();
        $create = $merger->merge(self::API, null, $this->endpoint(['method' => 'POST'], 'create'));
        self::assertArrayNotHasKey('doctor', $create['api']);
        $get = $merger->merge(self::API, null, $this->endpoint(['path' => 'orders/{id}', 'parameters' => ['id' => []]], 'get'));
        self::assertArrayNotHasKey('doctor', $get['api']);
        $required = $merger->merge(self::API, null, $this->endpoint(['query' => ['store' => ['required' => true]]]));
        self::assertArrayNotHasKey('doctor', $required['api']);

        $list = $merger->merge(self::API + ['doctor' => ['probe' => 'stores.list']], null, $this->endpoint([]));
        self::assertSame(['probe' => 'stores.list'], $list['api']['doctor'], 'An existing probe is kept');
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
        self::assertSame(['operation "list" replaced', 'doctor probe: "orders.list"'], $result['notes']);
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

    public function testAnObjectAlreadyDescribedIsKeptOrReplaced(): void
    {
        $existing = ['resource' => 'orders', 'object' => ['shape' => 'array{id: string}'], 'operations' => []];
        $endpoint = new ImportedEndpoint(self::API, 'orders', null, 'list', ['method' => 'GET', 'path' => 'orders', 'response' => ['data' => 'list']], ['shape' => 'array{id: string, total: int}']);

        $kept = (new DescriptorMerger())->merge(self::API, $existing, $endpoint);
        self::assertSame('array{id: string}', $kept['resource']['object']['shape']);
        self::assertSame(['response: the objects of the response differ from the "orders" object already described (from another example): the object is kept'], $kept['resource']['operations']['list']['review']);

        $replaced = (new DescriptorMerger())->merge(self::API, $existing, $endpoint, true);
        self::assertSame('array{id: string, total: int}', $replaced['resource']['object']['shape']);
        self::assertContains('resource object replaced', $replaced['notes']);
        self::assertArrayNotHasKey('review', $replaced['resource']['operations']['list']);
    }
}
