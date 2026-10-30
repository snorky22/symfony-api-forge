<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiImportCommandTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-import-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function import(string $curl, array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:api:import'));
        $tester->execute(['api' => 'lemonsqueezy', 'curl' => self::FIXTURES.$curl, '--dir' => $this->dir] + $options);

        return $tester;
    }

    public function testImportsAndMergesExamples(): void
    {
        $this->import('curl-example-1', ['--response' => self::FIXTURES.'json-example.json'])->assertCommandIsSuccessful();
        $this->import('curl-example-2')->assertCommandIsSuccessful();
        $tester = $this->import('curl-example-3', ['--response' => self::FIXTURES.'json-example-2.json']);
        $tester->assertCommandIsSuccessful();

        self::assertSame('https://api.lemonsqueezy.com/v1/', Yaml::parseFile($this->dir.'/lemonsqueezy/_api.yaml')['base_uri']);

        $orders = Yaml::parseFile($this->dir.'/lemonsqueezy/orders.yaml');
        self::assertSame('orders', $orders['type']);
        self::assertArrayHasKey('filter[user_email]', $orders['operations']['list']['query']);
        self::assertSame(['data' => 'list'], $orders['operations']['list']['response']);
        self::assertStringStartsWith("array{\n    type: string,", $orders['object']['shape']);
        self::assertSame(['query parameters are assumed optional'], $orders['operations']['list']['review']);

        $checkouts = Yaml::parseFile($this->dir.'/lemonsqueezy/checkouts.yaml');
        self::assertSame(['POST', 'checkouts'], [$checkouts['operations']['create']['method'], $checkouts['operations']['create']['path']]);
        self::assertStringContainsString('operation "create" added', $tester->getDisplay());
    }

    public function testImportsObject(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:api:import-object'));
        $tester->execute(['api' => 'lemonsqueezy', 'resource' => 'customers', 'files' => [self::FIXTURES.'customer-object.json'], '--dir' => $this->dir]);
        $tester->assertCommandIsSuccessful();
        $this->import('curl-customer-retrieve')->assertCommandIsSuccessful();

        $customers = Yaml::parseFile($this->dir.'/lemonsqueezy/customers.yaml');
        self::assertSame(['resource', 'type', 'object', 'operations'], array_keys($customers));
        self::assertSame('customers', $customers['type']);
        self::assertSame(['data' => 'object'], $customers['operations']['get']['response']);
    }

    public function testFailsOnConflict(): void
    {
        $this->import('curl-example-1')->assertCommandIsSuccessful();
        $tester = $this->import('curl-example-3', ['--operation' => 'list', '--resource' => 'orders']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
