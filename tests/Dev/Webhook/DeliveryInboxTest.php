<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use Snorky22\ApiForge\Dev\Webhook\DeliveryInbox;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Test\WebhookTestTrait;
use Snorky22\ApiForge\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Deliveries to the Lemon Squeezy example, recorded in the inbox with their outcome.
 */
final class DeliveryInboxTest extends WebTestCase
{
    use WebhookTestTrait;

    private const PAYLOAD = __DIR__.'/../../Fixtures/order-created-payload.json';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::INBOX);
        $this->client = self::createClient();
    }

    /**
     * A delivery as the provider sends it: signed, without the header of api-forge's deliveries.
     */
    private function provider(string $body, ?string $signature = null, string $type = 'lemonsqueezy'): void
    {
        $this->client->request('POST', '/webhook/'.$type, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, TestKernel::WEBHOOK_SECRET),
        ], content: $body);
    }

    private function inbox(): DeliveryInbox
    {
        return self::getContainer()->get(DeliveryInbox::class);
    }

    public function testDeliveriesAndTheirOutcome(): void
    {
        $body = file_get_contents(self::PAYLOAD);
        $this->provider($body);
        $this->provider($body, 'bad');
        $this->provider($body, type: 'stripe');
        self::sendWebhook($this->client, 'lemonsqueezy', $body);

        $deliveries = $this->inbox()->latest();
        self::assertSame(['simulated', 'provider', 'provider', 'provider'], array_column($deliveries, 'origin'));
        self::assertSame(['queued', 'unknown_type', 'rejected', 'queued'], array_column($deliveries, 'status'));
        self::assertSame('order_created', $deliveries[3]['event']);
        self::assertSame(hash('sha256', $body), $deliveries[3]['event_id']);
        self::assertSame($body, $deliveries[3]['body']);
        self::assertSame('Invalid signature.', $deliveries[2]['reason']);
        self::assertSame(406, $deliveries[2]['http_status']);

        // The worker consumes the queued event: its outcome is recorded
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $message = new ConsumeRemoteEventMessage('lemonsqueezy', new RemoteEvent('order_created', hash('sha256', $body), []));
        $dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope($message), 'async', new \RuntimeException('Database down')));
        // Messenger's retry listener reacts to the failure: the event will be consumed again
        self::assertSame(['failed', 'Database down (will be retried)'], [$this->inbox()->latest()[0]['status'], $this->inbox()->latest()[0]['reason']]);
        $dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope($message), 'async'));
        self::assertSame('consumed', $this->inbox()->latest()[0]['status']);
    }

    public function testDoctorSummary(): void
    {
        $body = file_get_contents(self::PAYLOAD);
        $this->provider($body);
        $this->provider($body, 'bad');

        $checks = self::getContainer()->get(WebhookDiagnostics::class)->deliveries('lemonsqueezy');

        self::assertStringStartsWith('Last delivery from the provider: ', $checks[0]->message);
        self::assertStringEndsWith(', order_created, rejected.', $checks[0]->message);
        self::assertSame('warning', $checks[1]->level);
        self::assertStringContainsString('1 of the last 2 deliveries were not consumed; the latest: order_created rejected', $checks[1]->message);
        self::assertStringContainsString(': Invalid signature. See api-forge:webhook:inbox --show=', $checks[1]->message);
    }

    public function testInboxAndReplayCommands(): void
    {
        $this->provider(file_get_contents(self::PAYLOAD), 'bad');
        $application = new Application(self::$kernel);
        $id = $this->inbox()->latest()[0]['id'];

        $inbox = new CommandTester($application->find('api-forge:webhook:inbox'));
        $inbox->execute([]);
        self::assertStringContainsString('rejected: Invalid signature.', $inbox->getDisplay());
        $inbox->execute(['--show' => substr($id, 0, 19)]);
        self::assertStringContainsString('"event_name": "order_created"', $inbox->getDisplay());
        self::assertStringContainsString('x-signature: bad', $inbox->getDisplay());

        // Replayed: signed again with the secret of the routing
        $replay = new CommandTester($application->find('api-forge:webhook:replay'));
        $replay->execute(['id' => $id]);
        $replay->assertCommandIsSuccessful();
        self::assertStringContainsString('Replayed '.$id.' (order_created): HTTP 202', $replay->getDisplay());
        self::assertSame(['simulated', 'queued'], [$this->inbox()->latest()[0]['origin'], $this->inbox()->latest()[0]['status']]);
    }

    public function testOnlyTheLastDeliveriesAreKept(): void
    {
        $inbox = new DeliveryInbox(TestKernel::INBOX.'-keep', 2);
        foreach (['a', 'b', 'c'] as $i => $event) {
            $inbox->save(['id' => '20260101-00000'.$i.'-000-abc', 'received_at' => '', 'type' => 'shop', 'event' => $event, 'event_id' => null, 'origin' => 'provider', 'status' => 'consumed', 'http_status' => 200, 'reason' => null, 'headers' => [], 'body' => '']);
        }

        self::assertSame(['c', 'b'], array_column($inbox->latest('shop'), 'event'));
        (new Filesystem())->remove(TestKernel::INBOX.'-keep');
    }
}
