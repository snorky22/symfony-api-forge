<?php

namespace App\Tests\Command;

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
        $this->execute('app:api:import-webhook', [
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
        $this->execute('app:api:import-object', ['resource' => 'orders', 'files' => [self::FIXTURES.'order-object.json']])->assertCommandIsSuccessful();

        $tester = $this->execute('app:api:import-webhook', ['--payload' => [self::FIXTURES.'order-created-payload.json']]);

        $tester->assertCommandIsSuccessful();
        $webhooks = Yaml::parseFile($this->dir.'/lemonsqueezy/_webhooks.yaml');
        self::assertStringStartsWith('payload data differs from the "orders" object shape', $webhooks['events']['order_created']['review'][0]);
    }

    public function testMergedObjectCoversAllExamples(): void
    {
        $this->execute('app:api:import-object', [
            'resource' => 'orders',
            'files' => [self::FIXTURES.'order-object.json', self::FIXTURES.'order-created-payload.json'],
        ])->assertCommandIsSuccessful();

        $shape = Yaml::parseFile($this->dir.'/lemonsqueezy/orders.yaml')['object']['shape'];
        self::assertStringContainsString('        subtotal: int|float,', $shape);
        self::assertStringContainsString('        refunded: ?bool,', $shape);
        self::assertStringContainsString('        affiliate_id?: int,', $shape);
    }

    public function testRequiresInput(): void
    {
        self::assertSame(Command::FAILURE, $this->execute('app:api:import-webhook', [])->getStatusCode());
    }
}
