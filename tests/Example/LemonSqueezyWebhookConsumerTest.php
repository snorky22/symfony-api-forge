<?php

namespace Snorky22\ApiForge\Tests\Example;

use App\Webhook\LemonSqueezyWebhookConsumer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;
use Symfony\Component\RemoteEvent\RemoteEvent;

final class LemonSqueezyWebhookConsumerTest extends TestCase
{
    public function testHandlesOrderCreated(): void
    {
        $logger = new MemoryLogger();
        $payload = (new LenientJsonDecoder())->decode(file_get_contents(__DIR__.'/../Fixtures/order-created-payload.json'));

        (new LemonSqueezyWebhookConsumer($logger))->consume(new RemoteEvent('order_created', '1', $payload));

        self::assertSame([['info', 'Lemon Squeezy order #1 created for johndoe@example.com.']], $logger->records);
    }

    public function testKnownEventWithoutHandlerIsIgnored(): void
    {
        $logger = new MemoryLogger();

        (new LemonSqueezyWebhookConsumer($logger))->consume(new RemoteEvent('subscription_updated', '1', []));

        self::assertSame([], $logger->records);
    }

    public function testUnknownEventIsLogged(): void
    {
        $logger = new MemoryLogger();

        (new LemonSqueezyWebhookConsumer($logger))->consume(new RemoteEvent('brand_new_event', '1', []));

        self::assertSame([['warning', 'Unhandled Lemon Squeezy webhook event "brand_new_event".']], $logger->records);
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [$level, strtr((string) $message, array_combine(array_map(static fn ($key) => '{'.$key.'}', array_keys($context)), array_map('strval', $context)))];
    }
}
