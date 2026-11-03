<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use Snorky22\ApiForge\Dev\Doctor\Check;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Snorky22\ApiForge\Tests\App\TestKernel;
use Symfony\Component\Filesystem\Filesystem;
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
        return array_map(static fn (Check $check) => $check->level.': '.$check->message, $checks);
    }

    public function testChainOfTheExample(): void
    {
        (new Filesystem())->remove(TestKernel::INBOX);
        self::bootKernel();

        self::assertSame([
            'ok: Endpoint: http://localhost/webhook/lemonsqueezy (path /webhook/lemonsqueezy)',
            'ok: Request parser routed, signing secret set.',
            "ok: Consumer registered (#[AsRemoteEventConsumer('lemonsqueezy')]).",
            'ok: The consumer handles 1 of the 17 events: order_created.',
            'warning: No delivery from the provider recorded yet (api-forge:webhook:inbox): nothing has reached the endpoint.',
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

    public function testRegisteredWebhooks(): void
    {
        self::bootKernel();
        $diagnostics = self::getContainer()->get(WebhookDiagnostics::class);

        [$checks, $urls] = $diagnostics->registrations('lemonsqueezy', [
            ['id' => '1', 'url' => 'https://72e1.ngrok-free.app', 'events' => ['order_created']],
            ['id' => '2', 'url' => 'https://shop.example.com/webhook/lemonsqueezy/', 'events' => ['order_refunded']],
        ]);

        self::assertSame([
            'warning: Webhook 1 (https://72e1.ngrok-free.app) does not send to the endpoint path /webhook/lemonsqueezy: its deliveries never reach the application.',
            'ok: Webhook 2 (https://shop.example.com/webhook/lemonsqueezy/) sends to the endpoint.',
            'warning: Webhook 2 (https://shop.example.com/webhook/lemonsqueezy/) does not send order_created: the consumer handles them, they never arrive.',
        ], self::summary($checks));
        self::assertSame(['https://shop.example.com/webhook/lemonsqueezy/'], $urls);

        [$checks, $urls] = $diagnostics->registrations('lemonsqueezy', []);
        self::assertStringStartsWith('error: No webhook registered at the provider sends to /webhook/lemonsqueezy', self::summary($checks)[0]);
        self::assertSame([], $urls);
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

        $check = (new WebhookDiagnostics($sender))->ping('lemonsqueezy', 'https://abcd.ngrok-free.app/webhook/lemonsqueezy');

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
