<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Core\Webhook\AbstractSignedRequestParser;
use Snorky22\ApiForge\Core\Webhook\AbstractWebhookConsumer;
use Snorky22\ApiForge\Dev\Webhook\WebhookMaker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Symfony\Component\Yaml\Yaml;

final class WebhookMakerTest extends TestCase
{
    private const SECRET = 'pdl_ntfset_test';

    /**
     * Loads written code (a class of a namespace of its own, so that each test can load one).
     */
    private static function load(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'api-forge-webhook-');
        file_put_contents($file, $code);
        try {
            require $file;
        } finally {
            unlink($file);
        }
    }

    /**
     * An instance of a class loaded by load() (unknown to static analysis).
     */
    private static function instance(string $class): object
    {
        return new $class();
    }

    private static function paddleRequest(string $body, int $timestamp, string $secret = self::SECRET): Request
    {
        // The header as Paddle's docs build it: ts=<timestamp>;h1=<HMAC-SHA256 of "timestamp:body">
        $signature = hash_hmac('sha256', $timestamp.':'.$body, $secret);

        return new Request(server: [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PADDLE_SIGNATURE' => \sprintf('ts=%d;h1=%s', $timestamp, $signature),
        ], content: $body);
    }

    public function testNames(): void
    {
        $maker = new WebhookMaker();

        self::assertSame('paddle', $maker->defaultType('Paddle'));
        self::assertSame('lemon_squeezy', $maker->defaultType('Shops\LemonSqueezy'));
        self::assertSame('LEMON_SQUEEZY_WEBHOOK_SECRET', $maker->secretVariable('lemon_squeezy'));
        self::assertSame('onTransactionCompleted', $maker->method('transaction.completed'));
        self::assertSame('onOrderCreated', $maker->method('order_created'));
        self::assertSame('onSubscriptionPaymentFailed', $maker->method('subscription.payment_failed'));
    }

    public function testThePaddleParserAcceptsAPaddleSignature(): void
    {
        $maker = new WebhookMaker();
        $preset = WebhookMaker::SCHEMES['paddle'];
        $code = $maker->parser('Probe\PaddleScheme\PaddleRequestParser', 'paddle', $preset['constants'], $preset['docs']);
        self::assertStringContainsString(' * @see https://developer.paddle.com/webhooks/about/signature-verification', $code);
        self::assertStringNotContainsString('SIGNATURE_ALGORITHM', $code);
        self::load($code);
        $parser = self::instance('Probe\PaddleScheme\PaddleRequestParser');
        self::assertInstanceOf(AbstractSignedRequestParser::class, $parser);
        $body = '{"event_id":"evt_01hv8x2axb33yr5y238zfwcn5p","event_type":"transaction.completed","data":{"id":"txn_1"}}';

        $event = $parser->parse(self::paddleRequest($body, time()), self::SECRET);

        self::assertInstanceOf(RemoteEvent::class, $event);
        self::assertSame('transaction.completed', $event->getName());
        self::assertSame('evt_01hv8x2axb33yr5y238zfwcn5p', $event->getId());
        self::assertSame(200, $parser->createSuccessfulResponse()->getStatusCode());

        // Replay protection: a timestamp older than the 5 seconds of the scheme
        try {
            $parser->parse(self::paddleRequest($body, time() - 60), self::SECRET);
            self::fail('An old timestamp was expected to be rejected.');
        } catch (RejectWebhookException $e) {
            self::assertStringContainsString('tolerance', $e->getMessage());
        }

        // Another secret
        $this->expectException(RejectWebhookException::class);
        $parser->parse(self::paddleRequest($body, time(), 'another'), self::SECRET);
    }

    public function testAParserNeedsAHeaderAndAnEventName(): void
    {
        $maker = new WebhookMaker();

        try {
            $maker->parser('Probe\P', 'p', ['EVENT_NAME_PATH' => 'type']);
            self::fail('The header was expected to be required.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('header', $e->getMessage());
        }
        $this->expectExceptionMessage('event name');
        $maker->parser('Probe\P', 'p', ['SIGNATURE_HEADER' => 'X-Signature']);
    }

    public function testConsumerAndAddedEvents(): void
    {
        $maker = new WebhookMaker();
        $code = $maker->consumer('Probe\Consumer\ShopWebhookConsumer', 'shop', ['order_created']);

        self::assertStringContainsString("#[AsRemoteEventConsumer('shop')]", $code);
        self::assertSame(['order_created'], $maker->eventsOf($code));
        // A method written by hand is kept; an event present is not added twice
        $code = str_replace('// TODO: what the application does', 'throw new \DomainException(\'order_created handled\');', $code);
        $added = $maker->addEvents($code, ['order_refunded', 'order_created']);
        self::assertSame(['order_created', 'order_refunded'], $maker->eventsOf($added));
        self::assertStringContainsString("throw new \\DomainException('order_created handled');", $added);
        self::assertSame($added, $maker->addEvents($added, ['order_refunded']));

        self::load($added);
        $consumer = self::instance('Probe\Consumer\ShopWebhookConsumer');
        self::assertInstanceOf(AbstractWebhookConsumer::class, $consumer);
        $consumer->consume(new RemoteEvent('unknown', 'evt_2', []));
        $consumer->consume(new RemoteEvent('order_refunded', 'evt_3', []));
        $this->expectExceptionMessage('order_created handled');
        $consumer->consume(new RemoteEvent('order_created', 'evt_1', []));
    }

    public function testMethodsNeverClash(): void
    {
        $maker = new WebhookMaker();
        // "a.b" and "a_b" give the same name; "unknown.event" the one of the fallback method; a key with a quote
        $code = $maker->consumer('Probe\Clash\ClashWebhookConsumer', 'clash', ['a.b', 'a_b', 'unknown.event', "it's"]);
        self::assertSame(['a.b', 'a_b', 'unknown.event', "it's"], $maker->eventsOf($code));
        self::assertStringContainsString("'a_b' => 'onAB2',", $code);
        self::assertStringContainsString("'unknown.event' => 'onUnknownEvent2',", $code);

        // Edited by hand: a key in double quotes with a comment, the last entry without its comma
        $code = str_replace("        'a.b' => 'onAB',", "        \"a.b\" => 'onAB', // the first", $code);
        $code = (string) preg_replace("/(=> 'onItS'),(\n    \];)/", '$1$2', $code);
        $added = $maker->addEvents($code, ['order.created', 'order_created', 'a.b']);
        self::assertSame(['a.b', 'a_b', 'unknown.event', "it's", 'order.created', 'order_created'], $maker->eventsOf($added));
        self::assertStringContainsString("'order_created' => 'onOrderCreated2',", $added);

        self::load($added);
        self::assertInstanceOf(AbstractWebhookConsumer::class, self::instance('Probe\Clash\ClashWebhookConsumer'));
    }

    public function testRoutingAndSamplePayload(): void
    {
        $maker = new WebhookMaker();

        self::assertSame([
            'parameters' => ['env(PADDLE_WEBHOOK_SECRET)' => ''],
            'framework' => ['webhook' => ['routing' => ['paddle' => [
                'service' => 'App\API\Paddle\Webhook\PaddleRequestParser',
                'secret' => '%env(PADDLE_WEBHOOK_SECRET)%',
            ]]]],
        ], Yaml::parse($maker->routing('paddle', 'App\API\Paddle\Webhook\PaddleRequestParser', 'A hint.')));
        self::assertSame('{"event_id":"evt_test_1","event_type":"transaction.completed","data":{}}', $maker->samplePayload(WebhookMaker::SCHEMES['paddle']['constants'], 'transaction.completed'));
        self::assertSame('{"meta":{"event_name":"order_created"},"data":{}}', $maker->samplePayload(WebhookMaker::SCHEMES['lemonsqueezy']['constants'], 'order_created'));
    }
}
