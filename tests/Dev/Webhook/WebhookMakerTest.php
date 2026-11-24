<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Each preset, against a delivery signed as the provider's docs describe it (built here, not with the parser).
     *
     * @return iterable<string, array{string, array<string, string>, string, string, int}>
     */
    public static function providerDeliveries(): iterable
    {
        $body = '{"id":"evt_1","type":"invoice.paid","data":{"object":{"id":"in_1"}}}';
        $now = time();
        // Stripe: t=<ts>,v1=<hex HMAC of "ts.body">; a v0 entry is ignored
        yield 'stripe' => ['stripe', ['Stripe-Signature' => \sprintf('t=%d,v1=%s,v0=ignored', $now, hash_hmac('sha256', $now.'.'.$body, self::SECRET))], $body, 'invoice.paid', 202];

        $push = '{"ref":"refs/heads/main"}';
        // GitHub: sha256=<hex HMAC of the body>, event and delivery in headers
        yield 'github' => ['github', [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $push, self::SECRET),
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        ], $push, 'push', 202];

        $order = '{"id":820982911946154508,"email":"jon@example.com"}';
        // Shopify: base64 HMAC of the body, topic in a header
        yield 'shopify' => ['shopify', [
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $order, self::SECRET, true)),
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Webhook-Id' => 'b54557e4-bdd9-4b37-8a5f-bf7d70bcd043',
        ], $order, 'orders/create', 200];

        $mollie = '{"resource":"event","id":"event_GvJ8WHrp5isUdRub9CJyH","type":"payment-link.paid","entityId":"pl_qng5gbbv8NAZ5gpM5ZYgx"}';
        // Mollie next-gen: sha256=<hex HMAC of the body>
        yield 'mollie' => ['mollie', ['X-Mollie-Signature' => 'sha256='.hash_hmac('sha256', $mollie, self::SECRET)], $mollie, 'payment-link.paid', 200];

        $lemon = '{"meta":{"event_name":"order_created"},"data":{"type":"orders","id":"1"}}';
        // Lemon Squeezy: <hex HMAC of the body>, answered with 200
        yield 'lemonsqueezy' => ['lemonsqueezy', ['X-Signature' => hash_hmac('sha256', $lemon, self::SECRET)], $lemon, 'order_created', 200];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('providerDeliveries')]
    public function testThePresetsAcceptTheProvidersDeliveries(string $scheme, array $headers, string $body, string $event, int $status): void
    {
        $preset = WebhookMaker::SCHEMES[$scheme];
        $namespace = 'Probe\Scheme'.ucfirst($scheme);
        self::load((new WebhookMaker())->parser($namespace.'\RequestParser', $scheme, $preset['constants'], $preset['docs']));
        $parser = self::instance($namespace.'\RequestParser');
        self::assertInstanceOf(AbstractSignedRequestParser::class, $parser);
        $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $remote = $parser->parse(new Request(server: $server, content: $body), self::SECRET);

        self::assertInstanceOf(RemoteEvent::class, $remote);
        self::assertSame($event, $remote->getName());
        self::assertSame($status, $parser->createSuccessfulResponse()->getStatusCode());
        // A tampered body is rejected
        $this->expectException(RejectWebhookException::class);
        $parser->parse(new Request(server: $server, content: $body.' '), self::SECRET);
    }

    public function testGithubOfficialTestVector(): void
    {
        $preset = WebhookMaker::SCHEMES['github'];
        self::load((new WebhookMaker())->parser('Probe\GithubVector\RequestParser', 'github', $preset['constants']));
        $parser = self::instance('Probe\GithubVector\RequestParser');
        self::assertInstanceOf(AbstractSignedRequestParser::class, $parser);

        // https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries#testing-the-webhook-payload-validation
        self::assertSame('sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17', $parser->sign('Hello, World!', "It's a Secret to Everybody"));
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
