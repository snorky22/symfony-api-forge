<?php

namespace Snorky22\ApiForge\Tests\Core\Webhook;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Core\Webhook\AbstractSignedRequestParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class AbstractSignedRequestParserTest extends TestCase
{
    private const SECRET = 'secret';

    private function request(string $body, array $headers): Request
    {
        $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request(server: $server, content: $body);
    }

    public function testPayloadEventNameAndBodyHashId(): void
    {
        $body = '{"meta": {"event_name": "order_created"}}';

        $event = (new PathParser())->parse($this->request($body, ['X-Signature' => strtoupper(hash_hmac('sha256', $body, self::SECRET))]), self::SECRET);

        self::assertSame('order_created', $event->getName());
        self::assertSame(hash('sha256', $body), $event->getId());
        self::assertSame(['meta' => ['event_name' => 'order_created']], $event->getPayload());
    }

    public function testHeaderEventNamePrefixedBase64SignatureAndDeliveryId(): void
    {
        $body = '{"id": "evt_1"}';
        $parser = new HeaderParser();
        $signature = 'sha1='.base64_encode(hash_hmac('sha1', $body, self::SECRET, true));

        self::assertSame($signature, $parser->sign($body, self::SECRET));
        $event = $parser->parse($this->request($body, ['Webhook-Signature' => $signature, 'Webhook-Event' => 'ping']), self::SECRET);

        self::assertSame('ping', $event->getName());
        self::assertSame('evt_1', $event->getId());
    }

    public function testDeliveryHeadersAreAcceptedByTheParser(): void
    {
        $body = '{"id": "evt_1"}';
        $parser = new HeaderParser();

        $headers = $parser->deliveryHeaders($body, self::SECRET, 'ping');

        self::assertSame(['Content-Type', 'Webhook-Signature', 'Webhook-Event'], array_keys($headers));
        self::assertSame('ping', $parser->parse($this->request($body, $headers), self::SECRET)->getName());
        self::assertArrayNotHasKey('X-Event-Name', (new PathParser())->deliveryHeaders($body, self::SECRET, 'ping'));
    }

    public function testPingPayloadHoldsOnlyTheEventName(): void
    {
        $parser = new PathParser();
        $payload = $parser->pingPayload();

        self::assertSame('{"meta":{"event_name":"api_forge_ping"}}', $payload);
        self::assertSame('api_forge_ping', $parser->parse($this->request($payload, $parser->deliveryHeaders($payload, self::SECRET)), self::SECRET)->getName());
        self::assertSame('{}', (new HeaderParser())->pingPayload());
    }

    public function testInvalidSignature(): void
    {
        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Invalid signature.');

        (new PathParser())->parse($this->request('{}', ['X-Signature' => 'abc']), self::SECRET);
    }

    public function testMissingSecretIsAConfigurationError(): void
    {
        try {
            (new PathParser())->parse($this->request('{}', ['X-Signature' => hash_hmac('sha256', '{}', '')]), '');
            self::fail('No exception thrown.');
        } catch (RejectWebhookException $e) {
            self::assertSame(500, $e->getStatusCode());
        }
    }

    public function testTimestampedSignatureInAStructuredHeader(): void
    {
        $body = '{"event_type": "transaction.completed", "event_id": "evt_1"}';
        $parser = new TimestampParser();
        $now = time();

        $header = $parser->sign($body, self::SECRET, $now);
        self::assertSame(\sprintf('ts=%d;h1=%s', $now, hash_hmac('sha256', $now.':'.$body, self::SECRET)), $header);

        $event = $parser->parse($this->request($body, ['Paddle-Signature' => $header]), self::SECRET);
        self::assertSame('transaction.completed', $event->getName());
        self::assertSame('evt_1', $event->getId());
    }

    public function testSeveralSignaturesDuringRotation(): void
    {
        $body = '{"event_type": "ping"}';
        $parser = new TimestampParser();
        $now = time();
        $old = hash_hmac('sha256', $now.':'.$body, 'old-secret');
        $current = hash_hmac('sha256', $now.':'.$body, self::SECRET);

        foreach (["ts=$now;h1=$old;h1=$current", "ts=$now;h1=$current;h1=$old"] as $header) {
            self::assertSame('ping', $parser->parse($this->request($body, ['Paddle-Signature' => $header]), self::SECRET)->getName());
        }
    }

    public function testTimestampOutsideTheToleranceIsRejected(): void
    {
        $body = '{"event_type": "ping"}';
        $parser = new TimestampParser();

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('outside the tolerance');

        $parser->parse($this->request($body, ['Paddle-Signature' => $parser->sign($body, self::SECRET, time() - 600)]), self::SECRET);
    }

    public function testTheTimestampIsSigned(): void
    {
        $body = '{"event_type": "ping"}';
        $parser = new TimestampParser();
        $now = time();
        $forged = \sprintf('ts=%d;h1=%s', $now + 1, hash_hmac('sha256', $now.':'.$body, self::SECRET));

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Invalid signature.');

        $parser->parse($this->request($body, ['Paddle-Signature' => $forged]), self::SECRET);
    }

    public function testHeaderWithoutTimestampOrSignatureIsRejected(): void
    {
        foreach (['', 'garbage', 'ts=1', 'h1=abc', 'ts=x;h1=abc'] as $header) {
            try {
                (new TimestampParser())->parse($this->request('{"event_type": "ping"}', ['Paddle-Signature' => $header]), self::SECRET);
                self::fail(\sprintf('"%s" accepted.', $header));
            } catch (RejectWebhookException $e) {
                self::assertSame(406, $e->getStatusCode());
            }
        }
    }

    public function testGetRequestIsRejected(): void
    {
        $this->expectException(RejectWebhookException::class);

        (new PathParser())->parse(Request::create('/', 'GET'), self::SECRET);
    }
}

final class PathParser extends AbstractSignedRequestParser
{
    protected const SIGNATURE_HEADER = 'X-Signature';
    protected const EVENT_NAME_PATH = 'meta.event_name';
}

final class HeaderParser extends AbstractSignedRequestParser
{
    protected const SIGNATURE_HEADER = 'Webhook-Signature';
    protected const SIGNATURE_ALGORITHM = 'sha1';
    protected const SIGNATURE_ENCODING = 'base64';
    protected const SIGNATURE_PREFIX = 'sha1=';
    protected const EVENT_NAME_HEADER = 'Webhook-Event';
    protected const EVENT_ID_PATH = 'id';
}

final class TimestampParser extends AbstractSignedRequestParser
{
    protected const SIGNATURE_HEADER = 'Paddle-Signature';
    protected const SIGNATURE_CONTENT = '{timestamp}:{body}';
    protected const SIGNATURE_PARTS = ['separator' => ';', 'timestamp' => 'ts', 'signature' => 'h1'];
    protected const SIGNATURE_TOLERANCE = 300;
    protected const EVENT_NAME_PATH = 'event_type';
    protected const EVENT_ID_PATH = 'event_id';
}
