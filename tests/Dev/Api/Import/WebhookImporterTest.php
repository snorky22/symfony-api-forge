<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Import;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Import\WebhookImporter;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

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

    private const PAYLOAD_DOCS = <<<'DOCS'
        event_idstringrequired

        Unique ID of the event.

        event_typestringrequired

        Type of event.

        dataobjectrequired

        The entity.

        statusstringrequired

        Status of the entity.

        Values

        *   `completed`
        *   `paid`

        billed_atstring | nullrequired

        When it was billed.
        DOCS;

    public function testAJsonPayloadWithItsDocsPage(): void
    {
        $payload = $this->importer()->importPayload('{"event_id": "evt_1", "event_type": "transaction.completed", "data": {"status": "completed", "billed_at": "2026-11-10"}}', 'json', self::PAYLOAD_DOCS);

        self::assertStringContainsString("        status: 'completed'|'paid',\n", $payload['shape']);
        self::assertStringContainsString("        billed_at: ?string,\n", $payload['shape']);
        self::assertSame([], $payload['review']);
    }

    public function testAJsonApiPayloadWithItsDocsPage(): void
    {
        $docs = "metaobjectrequired\n\nevent_namestringrequired\n\ndataobjectrequired\n\ntypestringrequired\n\nidstringrequired\n\nattributesobjectrequired\n\nstatusstringrequired\n\nValues\n\n*   `paid`\n*   `refunded`\n\nrefunded_atstring | nullrequired\n";
        $payload = $this->importer()->importPayload('{"meta": {"event_name": "order_created"}, "data": {"type": "orders", "id": "1", "attributes": {"status": "paid", "refunded_at": null}}}', 'jsonapi', $docs);

        self::assertStringContainsString("        status: 'paid'|'refunded',\n", $payload['data']);
        self::assertStringContainsString("        refunded_at: ?string,\n", $payload['data']);
        self::assertSame("array{\n    event_name: string,\n}", $payload['meta']);
    }

    public function testADocsPageThatMatchesNothingIsReported(): void
    {
        $payload = $this->importer()->importPayload('{"type": "ping"}', 'json', "otherstringrequired\n\nSomething else.\n");

        self::assertContains('docs: no field of the documentation capture matches the example, nothing applied', $payload['review']);
    }

    public function testAPlainJsonPayloadHoldingAnObjectGivesItAndItsEnvelope(): void
    {
        $payload = $this->importer()->importPayload('{"event_id": "evt_1", "event_type": "transaction.completed", "data": {"id": "txn_1"}}', 'json', self::PAYLOAD_DOCS);

        self::assertSame("array{\n    id: string,\n}", $payload['data']);
        self::assertSame("array{\n    event_id: string,\n    event_type: string,\n}", $payload['envelope']);
        self::assertStringContainsString('    data: array{', $payload['shape']);
        self::assertNull($this->importer()->importPayload('{"type": "ping", "data": [1]}', 'json')['envelope'], 'a list is not an object');
    }

    public function testTheResourceOfAnEventComesFromItsEntity(): void
    {
        self::assertSame('transactions', $this->importer()->resourceOfEvent('transaction.completed'));
        self::assertSame('payment-methods', $this->importer()->resourceOfEvent('payment_method.saved'));
        self::assertSame('subscriptions', $this->importer()->resourceOfEvent('subscription:created'));
        self::assertNull($this->importer()->resourceOfEvent('order_created'));
    }

    public function testRejectsInvalidPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->importer()->importPayload('[1, 2]', 'json');
    }
}
