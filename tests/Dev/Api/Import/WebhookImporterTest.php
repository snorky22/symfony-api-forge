<?php

namespace App\Tests\Dev\Api\Import;

use App\Dev\Api\Import\WebhookImporter;
use App\Dev\ArrayShapeGenerator;
use App\Dev\LenientJsonDecoder;
use PHPUnit\Framework\TestCase;

final class WebhookImporterTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/';

    private function importer(): WebhookImporter
    {
        return new WebhookImporter(new LenientJsonDecoder(), new ArrayShapeGenerator());
    }

    public function testImportsEventsTableFromDocs(): void
    {
        $events = $this->importer()->importEvents(file_get_contents(self::FIXTURES.'Docs Event Types • Lemon Squeezy.md'));

        self::assertCount(17, $events);
        self::assertSame(['resource' => 'orders', 'description' => 'Occurs when a new order is successfully placed.'], $events['order_created']);
        self::assertSame('subscription-invoices', $events['subscription_payment_success']['resource']);
        self::assertSame('license-keys', $events['license_key_created']['resource']);
        self::assertSame('affiliates', $events['affiliate_activated']['resource']);
        // Markdown is reduced to text: no backticks, links replaced by their text
        self::assertSame(
            'Occurs when a new subscription is successfully created. An order_created event will always be sent alongside a subscription_created event.',
            $events['subscription_created']['description'],
        );
    }

    public function testIgnoresRowsThatAreNotEvents(): void
    {
        $markdown = "| Name | Description |\n|---|---|\n| `user.deleted` | | A user is deleted |\nSome text | not a row\n";

        self::assertSame(['user.deleted' => ['description' => 'A user is deleted']], $this->importer()->importEvents($markdown));
    }

    public function testImportsJsonApiPayload(): void
    {
        $payload = $this->importer()->importPayload(file_get_contents(self::FIXTURES.'order-created-payload.json'), 'jsonapi');

        self::assertSame('order_created', $payload['event']);
        self::assertSame('meta.event_name', $payload['event_path']);
        self::assertSame('orders', $payload['type']);
        self::assertSame("array{\n    event_name: string,\n}", $payload['meta']);
        self::assertStringContainsString('        subtotal: float,', $payload['data']);
        self::assertNull($payload['shape']);
    }

    public function testImportsJsonPayload(): void
    {
        $payload = $this->importer()->importPayload('{"type": "invoice.paid", "object": {"id": 1}}', 'json');

        self::assertSame(['invoice.paid', 'type', null, null, null], [$payload['event'], $payload['event_path'], $payload['type'], $payload['meta'], $payload['data']]);
        self::assertSame("array{\n    type: string,\n    object: array{\n        id: int,\n    },\n}", $payload['shape']);
    }

    public function testRejectsInvalidPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->importer()->importPayload('[1, 2]', 'json');
    }
}
