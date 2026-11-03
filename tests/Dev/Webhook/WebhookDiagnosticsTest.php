<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use Snorky22\ApiForge\Dev\Webhook\WebhookCheck;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Checks of the webhook chain of the Lemon Squeezy example: routed, signed, consumed, queued on "async".
 */
final class WebhookDiagnosticsTest extends KernelTestCase
{
    /**
     * @return list<string> "level: message"
     */
    private static function summary(array $checks): array
    {
        return array_map(static fn (WebhookCheck $check) => $check->level.': '.$check->message, $checks);
    }

    public function testChainOfTheExample(): void
    {
        self::bootKernel();

        self::assertSame([
            'ok: Endpoint: http://localhost/webhook/lemonsqueezy (path /webhook/lemonsqueezy)',
            'ok: Request parser routed, signing secret set.',
            "ok: Consumer registered (#[AsRemoteEventConsumer('lemonsqueezy')]).",
            'warning: Events queued on the "async" transport: a worker must run (messenger:consume async), or they are never consumed.',
        ], self::summary(self::getContainer()->get(WebhookDiagnostics::class)->check('lemonsqueezy')));
    }

    public function testUnknownType(): void
    {
        self::bootKernel();
        $checks = self::getContainer()->get(WebhookDiagnostics::class)->check('stripe');

        self::assertSame('error', $checks[1]->level);
        self::assertStringContainsString('Unknown webhook type "stripe"', $checks[1]->message);
        self::assertSame("error: No consumer registered with #[AsRemoteEventConsumer('stripe')]: deliveries are accepted, then fail.", self::summary($checks)[2]);
    }

    /**
     * @return iterable<string, array{MockResponse|\Throwable, string, string}>
     */
    public static function pings(): iterable
    {
        yield 'endpoint' => [new MockResponse('', ['http_code' => 202]), 'ok', 'reaches the endpoint, signed with the same secret (HTTP 202)'];
        yield 'another page (no path)' => [new MockResponse('<html>Shop</html>', ['http_code' => 200]), 'error', 'answers HTTP 200, not the webhook endpoint (202): the path should be /webhook/lemonsqueezy'];
        yield 'offline tunnel' => [new MockResponse('The endpoint is offline. (ERR_NGROK_3200)', ['http_code' => 404]), 'error', 'or the tunnel is offline. The endpoint is offline. (ERR_NGROK_3200)'];
        yield 'other secret' => [new MockResponse('Invalid signature.', ['http_code' => 406]), 'error', 'rejects the signature (HTTP 406)'];
        yield 'unreachable' => [new TransportException('Could not resolve host'), 'error', 'is unreachable (tunnel down? wrong host?): Could not resolve host'];
    }

    #[DataProvider('pings')]
    public function testPing(MockResponse|\Throwable $response, string $level, string $message): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $client = new MockHttpClient(static fn () => $response instanceof \Throwable ? throw $response : $response);
        $parsers = ['lemonsqueezy' => ['parser' => $container->get('App\Api\LemonSqueezy\Generated\Webhook\LemonSqueezyRequestParser'), 'secret' => 's3cret']];
        $sender = new WebhookSender($parsers, $container->get(UrlGeneratorInterface::class), $container->get(HttpKernelInterface::class), $client);

        $check = (new WebhookDiagnostics($sender, ['lemonsqueezy']))->ping('lemonsqueezy', 'https://abcd.ngrok-free.app/webhook/lemonsqueezy');

        self::assertSame($level, $check->level);
        self::assertStringContainsString($message, $check->message);
    }

    public function testCommand(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:webhook:check'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Consumer registered', $tester->getDisplay());
        self::assertStringContainsString('The webhook chain is ready.', $tester->getDisplay());
    }
}
