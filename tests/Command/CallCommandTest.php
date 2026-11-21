<?php

namespace Snorky22\ApiForge\Tests\Command;

use Snorky22\ApiForge\Tests\App\ApiMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * Calls the generated Lemon Squeezy resources of the test application, answered by ApiMock.
 */
final class CallCommandTest extends KernelTestCase
{
    private const CUSTOMERS = 'App\Api\LemonSqueezy\Generated\Resource\Customers';

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('api-forge:call'));
    }

    private static function api(): ApiMock
    {
        $api = self::getContainer()->get(ApiMock::class);
        self::assertInstanceOf(ApiMock::class, $api);

        return $api;
    }

    public function testCallsAMethodWithJsonAndTextArguments(): void
    {
        $tester = $this->tester();
        self::api()->next = new JsonMockResponse(['data' => ['type' => 'customers', 'id' => '7']]);

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => self::CUSTOMERS, 'method' => 'get', 'arguments' => ['7', '{"include": "store"}']], ['interactive' => false]));
        self::assertStringContainsString('Customers::get(7, {"include":"store"})', $tester->getDisplay());
        self::assertStringContainsString("\"id\": \"7\"\n", $tester->getDisplay());
        self::assertSame(['GET https://api.lemonsqueezy.com/v1/customers/7?include=store'], self::api()->urls);
    }

    public function testAsksWhatIsMissing(): void
    {
        $tester = $this->tester();
        // method, then $id; $query left empty
        $tester->setInputs(['get', 'abc', '']);

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => self::CUSTOMERS]));
        self::assertStringContainsString('$id (string|int)', $tester->getDisplay());
        self::assertSame(['GET https://api.lemonsqueezy.com/v1/customers/abc'], self::api()->urls);
    }

    public function testAnErrorOfTheApi(): void
    {
        $tester = $this->tester();
        self::api()->next = new JsonMockResponse(['errors' => [['detail' => 'Unauthenticated.']]], ['http_code' => 401]);

        self::assertSame(Command::FAILURE, $tester->execute(['class' => self::CUSTOMERS, 'method' => 'list'], ['interactive' => false]));
        self::assertStringContainsString('Unauthenticated.', $tester->getDisplay());
    }

    public function testAnIteratorIsCut(): void
    {
        $tester = $this->tester();
        self::api()->next = new JsonMockResponse(['data' => [['id' => '1'], ['id' => '2'], ['id' => '3']]]);

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => self::CUSTOMERS, 'method' => 'iterate', '--max' => 2], ['interactive' => false]));
        self::assertStringContainsString('the first 2 are printed', $tester->getDisplay());
    }

    public function testUnknownResourceOrMethod(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'Nope\Nothing', 'method' => 'list'], ['interactive' => false]));
        self::assertStringContainsString('Unknown resource', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['class' => self::CUSTOMERS, 'method' => 'request'], ['interactive' => false]));
        self::assertStringContainsString('Known: create, get, iterate, list, update', $tester->getDisplay());
    }
}
