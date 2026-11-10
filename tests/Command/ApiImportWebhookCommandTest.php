<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiImportWebhookCommandTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-import-webhook-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->dir.'/lemonsqueezy/_api.yaml', Yaml::dump(['base_uri' => 'https://api.lemonsqueezy.com/v1/', 'format' => 'jsonapi']));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function execute(string $command, array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find($command));
        $tester->execute(['api' => 'lemonsqueezy', '--dir' => $this->dir] + $input);

        return $tester;
    }

    public function testPayloadGivesObjectOfUndescribedResource(): void
    {
        $this->execute('api-forge:import-webhook', [
            '--events' => self::FIXTURES.'Docs Event Types • Lemon Squeezy.md',
            '--payload' => [self::FIXTURES.'order-created-payload.json'],
        ])->assertCommandIsSuccessful();

        $webhooks = Yaml::parseFile($this->dir.'/lemonsqueezy/_webhooks.yaml');
        self::assertCount(17, $webhooks['events']);
        self::assertSame(['path' => 'meta.event_name'], $webhooks['event_name']);
        self::assertArrayNotHasKey('review', $webhooks['events']['order_created']);

        $orders = Yaml::parseFile($this->dir.'/lemonsqueezy/orders.yaml');
        self::assertSame(['resource' => 'orders', 'type' => 'orders'], array_intersect_key($orders, ['resource' => 1, 'type' => 1]));
        self::assertStringContainsString('subtotal: float,', $orders['object']['shape']);
    }

    public function testPayloadDifferingFromObjectIsReported(): void
    {
        $this->execute('api-forge:import-object', ['resource' => 'orders', 'files' => [self::FIXTURES.'order-object.json']])->assertCommandIsSuccessful();

        $tester = $this->execute('api-forge:import-webhook', ['--payload' => [self::FIXTURES.'order-created-payload.json']]);

        $tester->assertCommandIsSuccessful();
        $webhooks = Yaml::parseFile($this->dir.'/lemonsqueezy/_webhooks.yaml');
        self::assertStringStartsWith('payload data differs from the "orders" object shape', $webhooks['events']['order_created']['review'][0]);
    }

    public function testMergedObjectCoversAllExamples(): void
    {
        $this->execute('api-forge:import-object', [
            'resource' => 'orders',
            'files' => [self::FIXTURES.'order-object.json', self::FIXTURES.'order-created-payload.json'],
        ])->assertCommandIsSuccessful();

        $shape = Yaml::parseFile($this->dir.'/lemonsqueezy/orders.yaml')['object']['shape'];
        self::assertStringContainsString('        subtotal: int|float,', $shape);
        self::assertStringContainsString('        refunded: ?bool,', $shape);
        self::assertStringContainsString('        affiliate_id?: int,', $shape);
    }

    private function runShop(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import-webhook'));
        $tester->execute(['api' => 'shop', '--dir' => $this->dir] + $input);

        return $tester;
    }

    public function testAPlainJsonPayloadGivesTheObjectOfItsResourceAndTheEnvelope(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir.'/shop/_api.yaml', Yaml::dump(['base_uri' => 'https://api.shop.test/', 'format' => 'json']));
        $events = $this->dir.'/fixtures/webhook-events.md';
        $filesystem->dumpFile($events, "| Event | Group | Description |\n|---|---|---|\n| `transaction.completed` | Transaction | Completed. |\n| `transaction.paid` | Transaction | Paid. |\n| `customer.created` | Customer | Created. |\n");
        $payload = $this->dir.'/fixtures/webhook-transaction.completed.json';
        $filesystem->dumpFile($payload, '{"event_id": "evt_1", "event_type": "transaction.completed", "data": {"id": "txn_1", "billed_at": "2026-11-10"}}');

        $this->runShop(['--events' => $events])->assertCommandIsSuccessful();
        $webhooks = Yaml::parseFile($this->dir.'/shop/_webhooks.yaml');
        self::assertSame(['description' => 'Completed.'], $webhooks['events']['transaction.completed'], 'no resource guessed from the table');
        self::assertNotContains('events: resources were derived from the object names of the events doc', $webhooks['review']);

        $display = $this->runShop(['--payload' => [$payload]])->getDisplay();
        self::assertStringContainsString('object of the "transactions" resource set from the payload', $display);
        $webhooks = Yaml::parseFile($this->dir.'/shop/_webhooks.yaml');
        self::assertSame("array{\n    event_id: string,\n    event_type: string,\n}", $webhooks['envelope']['shape']);
        self::assertSame('data', $webhooks['envelope']['data']);
        self::assertSame('transactions', $webhooks['events']['transaction.completed']['resource']);
        self::assertArrayNotHasKey('shape', $webhooks['events']['transaction.completed']);
        self::assertSame('transactions', $webhooks['events']['transaction.paid']['resource'], 'same entity');
        self::assertArrayNotHasKey('resource', $webhooks['events']['customer.created']);
        self::assertContains('events: resource "transactions" set on transaction.paid from the payload of "transaction.completed", by their name: check that their payloads hold the same object', $webhooks['review']);
        self::assertStringContainsString('billed_at: string,', Yaml::parseFile($this->dir.'/shop/transactions.yaml')['object']['shape']);

        // The docs page next to the payload: existing values win, --force replaces the object shape
        $filesystem->dumpFile($this->dir.'/fixtures/webhook-transaction.completed.docs.md', "event_idstringrequired\n\nevent_typestringrequired\n\ndataobjectrequired\n\nidstringrequired\n\nbilled_atstring | nullrequired\n");
        $display = $this->runShop(['--payload' => [$payload]])->getDisplay();
        self::assertStringContainsString('docs page read:', $display);
        self::assertStringStartsWith('payload data differs from the "transactions" object shape', Yaml::parseFile($this->dir.'/shop/_webhooks.yaml')['events']['transaction.completed']['review'][1]);
        self::assertStringContainsString('billed_at: string,', Yaml::parseFile($this->dir.'/shop/transactions.yaml')['object']['shape']);

        self::assertStringContainsString('object of the "transactions" resource replaced from the payload', $this->runShop(['--payload' => [$payload], '--force' => true])->getDisplay());
        self::assertStringContainsString('billed_at: ?string,', Yaml::parseFile($this->dir.'/shop/transactions.yaml')['object']['shape']);
        self::assertSame(['resource "transactions" derived from the event name: rename the file and this value if the API calls it otherwise'], Yaml::parseFile($this->dir.'/shop/_webhooks.yaml')['events']['transaction.completed']['review'], 'the notes of the replaced example are gone');
    }

    public function testAPlainJsonPayloadWithoutObjectIsTheShapeOfItsEvent(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/shop/_api.yaml', Yaml::dump(['base_uri' => 'https://api.shop.test/', 'format' => 'json']));
        $payload = $this->dir.'/fixtures/ping.json';
        (new Filesystem())->dumpFile($payload, '{"type": "ping", "sent_at": 1}');

        $this->runShop(['--payload' => [$payload]])->assertCommandIsSuccessful();

        $webhooks = Yaml::parseFile($this->dir.'/shop/_webhooks.yaml');
        self::assertSame("array{\n    type: string,\n    sent_at: int,\n}", $webhooks['events']['ping']['shape']);
        self::assertArrayNotHasKey('envelope', $webhooks);
    }

    public function testRequiresInput(): void
    {
        self::assertSame(Command::FAILURE, $this->execute('api-forge:import-webhook', [])->getStatusCode());
    }
}
