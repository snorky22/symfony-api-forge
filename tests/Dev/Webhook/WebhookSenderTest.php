<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Snorky22\ApiForge\Test\WebhookTestTrait;
use Snorky22\ApiForge\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Simulated deliveries to the Lemon Squeezy example: signed with the routing secret, accepted, queued.
 */
final class WebhookSenderTest extends WebTestCase
{
    use WebhookTestTrait;

    private const PAYLOAD = __DIR__.'/../../Fixtures/order-created-payload.json';

    /**
     * @return list<RemoteEvent>
     */
    private static function queuedEvents(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_map(static fn ($envelope) => $envelope->getMessage()->getEvent(), array_filter(
            $transport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof ConsumeRemoteEventMessage,
        ));
    }

    public function testTestTraitPostsASignedDelivery(): void
    {
        $client = self::createClient();
        $payload = json_decode(file_get_contents(self::PAYLOAD), true);
        $payload['meta']['custom_data'] = ['user_id' => '42'];

        $response = self::sendWebhook($client, 'lemonsqueezy', $payload);

        self::assertSame(202, $response->getStatusCode());
        $events = self::queuedEvents();
        self::assertCount(1, $events);
        self::assertSame('order_created', $events[0]->getName());
        self::assertSame('42', $events[0]->getPayload()['meta']['custom_data']['user_id']);
    }

    public function testSenderSignsWithTheRoutingSecret(): void
    {
        self::bootKernel();
        $sender = self::getContainer()->get(WebhookSender::class);
        $body = file_get_contents(self::PAYLOAD);

        self::assertSame(['lemonsqueezy'], $sender->types());
        self::assertSame(['async'], $sender->asyncTransports('lemonsqueezy'));
        self::assertSame('http://localhost/webhook/lemonsqueezy', $sender->url('lemonsqueezy'));
        self::assertSame(hash_hmac('sha256', $body, TestKernel::WEBHOOK_SECRET), $sender->headers('lemonsqueezy', $body)['X-Signature']);
        self::assertSame(202, $sender->send('lemonsqueezy', $body)->getStatusCode());
        self::assertCount(1, self::queuedEvents());
    }

    public function testSendsOverHttp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url, $options];

            return new MockResponse('accepted', ['http_code' => 202]);
        });
        $parsers = ['lemonsqueezy' => ['parser' => $container->get('App\\Api\\LemonSqueezy\\Generated\\Webhook\\LemonSqueezyRequestParser'), 'secret' => 's3cret']];
        $sender = new WebhookSender($parsers, $container->get(UrlGeneratorInterface::class), $container->get(HttpKernelInterface::class), $client);

        self::assertSame([202, 'accepted'], $sender->sendTo('https://example.ngrok.app/webhook/lemonsqueezy', 'lemonsqueezy', '{"a":1}'));
        self::assertSame(['POST', 'https://example.ngrok.app/webhook/lemonsqueezy'], [$requests[0][0], $requests[0][1]]);
        self::assertContains('X-Signature: '.hash_hmac('sha256', '{"a":1}', 's3cret'), $requests[0][2]['headers']);
    }

    public function testUnknownType(): void
    {
        self::bootKernel();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown webhook type "stripe"; the known types are: lemonsqueezy.');

        self::getContainer()->get(WebhookSender::class)->headers('stripe', '{}');
    }

    public function testCommandSendsTheFileWithReplacedValues(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:webhook:send'));
        $tester->execute([
            'type' => 'lemonsqueezy',
            'file' => self::PAYLOAD,
            '--set' => ['meta.custom_data.user_id=42', 'data.attributes.status="refunded"', 'meta.test_mode=true'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('POST http://localhost/webhook/lemonsqueezy: HTTP 202', $tester->getDisplay());
        self::assertStringContainsString('Accepted, and queued on the "async" transport', $tester->getDisplay());
        $payload = self::queuedEvents()[0]->getPayload();
        self::assertSame(42, $payload['meta']['custom_data']['user_id']);
        self::assertTrue($payload['meta']['test_mode']);
        self::assertSame('refunded', $payload['data']['attributes']['status']);
    }

    public function testCommandReportsAnUnreadableFile(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:webhook:send'));

        self::assertSame(Command::FAILURE, $tester->execute(['type' => 'lemonsqueezy', 'file' => 'missing.json']));
        self::assertStringContainsString('Cannot read file "missing.json".', $tester->getDisplay());
    }
}
