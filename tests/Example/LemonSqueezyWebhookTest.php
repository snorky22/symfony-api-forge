<?php

namespace Snorky22\ApiForge\Tests\Example;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;

/**
 * Posts webhooks to /webhook/lemonsqueezy, signed with the secret of .env.test.
 */
final class LemonSqueezyWebhookTest extends WebTestCase
{
    private const SECRET = 'test-webhook-secret';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function post(string $body, ?string $signature, string $type = 'lemonsqueezy'): int
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signature) {
            $server['HTTP_X_SIGNATURE'] = $signature;
        }
        $this->client->request('POST', '/webhook/'.$type, server: $server, content: $body);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return list<ConsumeRemoteEventMessage>
     */
    private function dispatched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
    }

    public function testSignedWebhookIsAcceptedAndQueued(): void
    {
        $body = file_get_contents(__DIR__.'/../Fixtures/order-created-payload.json');

        self::assertSame(202, $this->post($body, hash_hmac('sha256', $body, self::SECRET)));

        $messages = $this->dispatched();
        self::assertCount(1, $messages);
        self::assertSame('lemonsqueezy', $messages[0]->getType());
        $event = $messages[0]->getEvent();
        self::assertSame('order_created', $event->getName());
        self::assertSame(hash('sha256', $body), $event->getId());
        self::assertSame('johndoe@example.com', $event->getPayload()['data']['attributes']['user_email']);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $body = '{"meta": {"event_name": "order_created"}, "data": {}}';

        self::assertSame(406, $this->post($body, hash_hmac('sha256', $body, 'wrong-secret')));
        self::assertSame(406, $this->post($body, null));
        self::assertSame([], $this->dispatched());
    }

    public function testNonJsonBodyIsRejected(): void
    {
        self::assertSame(406, $this->post('not json', hash_hmac('sha256', 'not json', self::SECRET)));
    }

    public function testMissingEventNameIsRejected(): void
    {
        $body = '{"data": {}}';

        self::assertSame(406, $this->post($body, hash_hmac('sha256', $body, self::SECRET)));
    }

    public function testUnknownWebhookType(): void
    {
        self::assertSame(404, $this->post('{}', 'x', 'other'));
    }
}
