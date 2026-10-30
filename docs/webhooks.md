# Webhooks

Webhooks are received with the Symfony [Webhook](https://symfony.com/doc/current/webhook.html) and
RemoteEvent components:

```
POST /webhook/lemonsqueezy
  → LemonSqueezyRequestParser   checks the signature, reads the event name    (generated)
  → RemoteEvent, queued on the Messenger "async" transport; the endpoint answers 202
  → worker: LemonSqueezyWebhookConsumer::onOrderCreated($payload)              (your code)
```

## Setup

1. Register the webhook URL in the API's dashboard: `https://<your-host>/webhook/lemonsqueezy`.
2. Put the signing secret in `.env.local`, under the name given by `signature.env` in
   `_webhooks.yaml`:
   ```
   LEMONSQUEEZY_WEBHOOK_SECRET=...
   ```
   Without it, every webhook is rejected (HTTP 500): unsigned requests are never accepted.
3. Run the Messenger worker, which consumes the queued events:
   ```bash
   bin/console messenger:consume async
   ```
   Routing `ConsumeRemoteEventMessage` to `async` (`config/packages/messenger.yaml`) lets the endpoint
   answer at once, as API providers expect; failed events are retried by Messenger, then stored in the
   `failed` transport. The `async` and `failed` transports use Doctrine (`MESSENGER_TRANSPORT_DSN`), so
   the database must be configured. Remove the routing line to process webhooks synchronously instead.

## Handling events

The generated `AbstractLemonSqueezyWebhookConsumer` has one empty method per documented event. Extend
it, and override the methods of the events you handle; the others are ignored:

```php
// src/Webhook/LemonSqueezyWebhookConsumer.php
#[AsRemoteEventConsumer('lemonsqueezy')]
final class LemonSqueezyWebhookConsumer extends AbstractLemonSqueezyWebhookConsumer
{
    protected function onOrderCreated(array $payload, RemoteEvent $event): void
    {
        $payload['data']['attributes']['user_email'];   // typed: OrderCreatedPayload
    }

    protected function onUnknownEvent(RemoteEvent $event): void
    {
        // events without a method, e.g. new events of the API
    }
}
```

Exactly one consumer must be registered per API name (`lemonsqueezy`): without one, the queued events
fail. `src/Webhook/LemonSqueezyWebhookConsumer.php` is a starting point that logs new orders.

### Payload types

`LemonSqueezyWebhookEvents` holds the event names as constants (`LemonSqueezyWebhookEvents::ORDER_CREATED`,
and `ALL`), and the type of each payload (`@phpstan-type OrderCreatedPayload`). For JSON:API APIs, a
payload is `array{meta: WebhookMeta, data: <resource object>}`, where the resource object is the shape
of the resource's descriptor (`Order` for `orders`). Events whose resource has no object shape get
`data: array<string, mixed>`: import the resource object to type them.

### Event ids and duplicates

APIs retry deliveries that fail. Each `RemoteEvent` has an id: the delivery id if the API sends one
(`event_id` in `_webhooks.yaml`), otherwise a SHA-256 hash of the body. A retried delivery has the same
id, so consumers can detect duplicates by storing the ids they have processed.

## Responses

| Situation | Status |
|---|---|
| Valid webhook, queued | 202 |
| Invalid or missing signature, not JSON, no event name, not POST | 406 |
| Webhook secret not configured | 500 |
| Unknown API name in the URL | 404 |

## Testing

Sign the body with the secret of `.env.test` (`LEMONSQUEEZY_WEBHOOK_SECRET=test-webhook-secret`); in the
test environment the `async` transport is in memory, so queued events can be inspected:

```php
$body = file_get_contents('tests/Fixtures/order-created-payload.json');
$client->request('POST', '/webhook/lemonsqueezy', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
], content: $body);

$transport = self::getContainer()->get('messenger.transport.async');
$transport->getSent()[0]->getMessage()->getEvent()->getName();  // "order_created"
```

Consumers can be tested directly: `$consumer->consume(new RemoteEvent('order_created', '1', $payload))`.
See `tests/Webhook/`.
