<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Import;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;
use Snorky22\ApiForge\Dev\Api\Import\ObjectSources;
use Symfony\Component\Filesystem\Filesystem;

final class ObjectSourcesTest extends TestCase
{
    private string $dir;
    private ObjectSources $objects;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/object-sources-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir.'/fixtures/webhook-transaction.completed.json', '{"event_type": "transaction.completed", "data": {"id": "txn_1", "billed_at": "2026-11-10"}}');
        $filesystem->dumpFile($this->dir.'/fixtures/webhook-transaction.completed.docs.md', "dataobjectrequired\n\nidstringrequired\n\nbilled_atstring | nullrequired\n");
        $filesystem->dumpFile($this->dir.'/fixtures/list-transactions.json', '{"data": [{"id": "txn_2", "billed_at": null, "origin": "web"}, {"id": "txn_3", "billed_at": null, "origin": "api"}], "meta": {}}');
        $this->objects = new ObjectSources(new ExampleReader([new PhpArrayExample()]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private const PAYLOAD = ['example' => 'fixtures/webhook-transaction.completed.json', 'path' => 'data'];
    private const LIST = ['example' => 'fixtures/list-transactions.json', 'path' => 'data[]'];

    public function testAPayloadAndAListResponseDescribeOneObject(): void
    {
        $object = $this->objects->describe($this->dir, [self::PAYLOAD, self::LIST]);

        self::assertSame("array{\n    id: string,\n    billed_at: ?string,\n    origin?: string,\n}", $object['shape']);
        self::assertSame([], $object['review']);
    }

    public function testSourcesAreFoundByTheConventions(): void
    {
        $resource = ['resource' => 'transactions', 'operations' => ['list' => ['method' => 'GET', 'path' => 'transactions', 'response' => ['data' => 'list']]]];
        $webhooks = ['events' => ['transaction.completed' => ['resource' => 'transactions'], 'transaction.paid' => ['resource' => 'transactions']]];

        self::assertSame([self::PAYLOAD, self::LIST], $this->objects->discover($this->dir, $this->dir.'/fixtures', 'transactions', $resource, $webhooks));
    }

    public function testAnObjectWithoutRecordedSourcesMergesWithTheOnesFound(): void
    {
        $update = $this->objects->update($this->dir, 'transactions', ['shape' => 'array{id: string}'], self::LIST, ['shape' => 'array{id: string, origin: string}'], 'merge', [self::PAYLOAD, self::LIST]);

        self::assertSame([self::PAYLOAD, self::LIST], $update['object']['sources']);
        self::assertStringContainsString('origin?: string,', $update['object']['shape']);
        self::assertSame(['object of "transactions" merged from 2 examples: fixtures/webhook-transaction.completed.json, fixtures/list-transactions.json'], $update['notes']);

        $this->expectExceptionMessage('has no example to merge with');
        $this->objects->update($this->dir, 'transactions', ['shape' => 'array{id: string}'], self::LIST, ['shape' => 'x'], 'merge');
    }

    public function testForceReplacesWithANewExampleOnlyASourceIsDescribedAgain(): void
    {
        $merged = ['shape' => 'array{id: string}', 'sources' => [self::PAYLOAD, self::LIST]];

        $again = $this->objects->update($this->dir, 'transactions', $merged, self::LIST, ['shape' => 'array{id: string}'], null, [], true);
        self::assertSame([self::PAYLOAD, self::LIST], $again['object']['sources']);
        self::assertSame(['object of "transactions" described again from its 2 examples'], $again['notes']);

        $other = ['example' => 'fixtures/get-transactions.json', 'path' => 'data'];
        self::assertSame([$other], $this->objects->update($this->dir, 'transactions', $merged, $other, ['shape' => 'array{id: int}'], null, [], true)['object']['sources']);
        self::assertSame($merged, $this->objects->update($this->dir, 'transactions', $merged, self::LIST, ['shape' => 'x'], 'keep')['object']);
    }
}
