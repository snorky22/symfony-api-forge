<?php

namespace Snorky22\ApiForge\Tests\Command;

use Snorky22\ApiForge\Tests\App\ApiMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The generated doctor of the Lemon Squeezy example, through api-forge:doctor (HTTP answered by ApiMock).
 */
final class DoctorCommandTest extends KernelTestCase
{
    private function doctor(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:doctor'));
        $tester->execute($input);

        return $tester;
    }

    public function testDiagnosesTheExample(): void
    {
        $tester = $this->doctor();

        $display = $tester->getDisplay();
        // The test application has no API key: the doctor says so
        self::assertStringContainsString('✘ LEMONSQUEEZY_API_KEY (credentials) is empty: set it in .env.local.', $display);
        self::assertStringContainsString('✔ LEMONSQUEEZY_WEBHOOK_SECRET (webhook signing secret) is set.', $display);
        self::assertStringContainsString('✔ Probe customers.list: the API answers and accepts the credentials', $display);
        self::assertStringContainsString('✔ Consumer registered', $display);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['GET https://api.lemonsqueezy.com/v1/customers'], self::getContainer()->get(ApiMock::class)->urls);
    }

    public function testRejectedCredentials(): void
    {
        self::bootKernel();
        self::getContainer()->get(ApiMock::class)->next = new JsonMockResponse(['errors' => [['detail' => 'Unauthenticated.']]], ['http_code' => 401]);
        $tester = new CommandTester((new Application(self::$kernel))->find('api-forge:doctor'));
        $tester->execute(['apis' => ['lemonsqueezy']]);

        self::assertStringContainsString('✘ Probe customers.list: the API rejects the credentials (HTTP 401): check LEMONSQUEEZY_API_KEY.', $tester->getDisplay());
    }

    public function testUnknownApi(): void
    {
        $tester = $this->doctor(['apis' => ['stripe']]);

        self::assertStringContainsString('No doctor for "stripe"; known APIs: lemonsqueezy.', $tester->getDisplay());
        self::assertSame(1, $tester->getStatusCode());
    }
}
