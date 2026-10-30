<?php

namespace App\Tests\Dev\Api\Import;

use App\Dev\Api\Import\WebhookMerger;
use PHPUnit\Framework\TestCase;

final class WebhookMergerTest extends TestCase
{
    private const PAYLOAD = [
        'event' => 'order_created',
        'event_path' => 'meta.event_name',
        'type' => 'orders',
        'meta' => 'array{event_name: string}',
        'data' => 'array{id: string}',
        'shape' => null,
    ];

    public function testFirstImportCreatesSkeleton(): void
    {
        $result = (new WebhookMerger())->mergeEvents(null, 'lemon-squeezy', ['order_created' => ['resource' => 'orders']]);

        self::assertSame([
            'signature' => ['header' => 'X-Signature', 'algorithm' => 'sha256', 'encoding' => 'hex', 'env' => 'LEMON_SQUEEZY_WEBHOOK_SECRET'],
            'events' => ['order_created' => ['resource' => 'orders']],
            'review' => [
                WebhookMerger::DEFAULT_SIGNATURE_NOTE,
                WebhookMerger::MISSING_EVENT_NAME_NOTE,
                'events: resources were derived from the object names of the events doc',
            ],
        ], $result['webhooks']);
        self::assertSame(['1 event(s) added, 0 already known'], $result['notes']);
    }

    public function testExistingEventsWin(): void
    {
        $webhooks = ['signature' => [], 'events' => ['order_created' => ['resource' => 'my-orders']]];

        $result = (new WebhookMerger())->mergeEvents($webhooks, 'shop', ['order_created' => ['resource' => 'orders', 'description' => 'New order']]);

        self::assertSame(['resource' => 'my-orders', 'description' => 'New order'], $result['webhooks']['events']['order_created']);
        self::assertSame(['0 event(s) added, 1 already known'], $result['notes']);
    }

    public function testPayloadSetsEventNameLocationMetaAndEvent(): void
    {
        $merger = new WebhookMerger();
        $webhooks = $merger->mergeEvents(null, 'shop', [])['webhooks'];

        $result = $merger->mergePayload($webhooks, 'shop', self::PAYLOAD, null, 'orders', ['check me']);

        $webhooks = $result['webhooks'];
        self::assertSame(['signature', 'event_name', 'meta', 'events', 'review'], array_keys($webhooks));
        self::assertSame(['path' => 'meta.event_name'], $webhooks['event_name']);
        self::assertSame(['shape' => 'array{event_name: string}'], $webhooks['meta']);
        self::assertSame(['resource' => 'orders', 'review' => ['check me']], $webhooks['events']['order_created']);
        self::assertNotContains(WebhookMerger::MISSING_EVENT_NAME_NOTE, $webhooks['review']);
        self::assertSame(['event name read from "meta.event_name"', 'event "order_created" added'], $result['notes']);
    }

    public function testJsonPayloadShapeAndExplicitEventName(): void
    {
        $payload = ['event' => null, 'event_path' => null, 'type' => null, 'meta' => null, 'data' => null, 'shape' => 'array{id: int}'];

        $webhooks = (new WebhookMerger())->mergePayload(null, 'shop', $payload, 'ping', null)['webhooks'];

        self::assertSame(['shape' => 'array{id: int}'], $webhooks['events']['ping']);
        self::assertContains(WebhookMerger::MISSING_EVENT_NAME_NOTE, $webhooks['review']);
    }

    public function testEventNameIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--event');

        (new WebhookMerger())->mergePayload(null, 'shop', ['event' => null] + self::PAYLOAD, null, null);
    }
}
