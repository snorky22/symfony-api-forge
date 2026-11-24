# Webhooks

Webhooks are received with the Symfony [Webhook](https://symfony.com/doc/current/webhook.html) and
RemoteEvent components:

```
POST /webhook/lemonsqueezy
  → LemonSqueezyRequestParser   checks the signature, reads the event name    (generated)
  → RemoteEvent, queued on the Messenger "async" transport; the endpoint answers 202
  → worker: LemonSqueezyWebhookConsumer::onOrderCreated($payload)              (your code)
```

The parser and the consumer are generated from the descriptors (`api-forge:import-webhook`, `api-forge:generate`),
or written by hand with `api-forge:make:webhook` (see [Hand-written webhooks](#hand-written-webhooks)).

## Setup

1. Register the webhook URL in the API's dashboard: `https://<your-host>/webhook/lemonsqueezy`.
2. Put the signing secret in `.env.local`, under the name given by `signature.env` in
   `_webhooks.yaml`:
   ```
   LEMONSQUEEZY_WEBHOOK_SECRET=...
   ```
   Without it, every webhook is rejected (HTTP 500): unsigned requests are never accepted.
3. Recommended: process the events asynchronously, so the endpoint answers at once, as API providers
   expect. Route them to an asynchronous transport in `config/packages/messenger.yaml`:
   ```yaml
   framework:
       messenger:
           transports:
               async: '%env(MESSENGER_TRANSPORT_DSN)%'
           routing:
               Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage: async
   ```
   and run the worker:
   ```bash
   bin/console messenger:consume async
   ```
   Failed events are then retried by Messenger. Without this routing, webhooks are processed
   synchronously, during the request.

In the tests of the bundle, `tests/App/TestKernel.php` has this setup with an in-memory transport.

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
fail. `examples/lemonsqueezy/src/Webhook/LemonSqueezyWebhookConsumer.php` is a starting point that logs
new orders.

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
| Valid webhook, queued | 202, or the parser's `SUCCESS_STATUS` (200 for Paddle, which retries any other status) |
| Invalid or missing signature, not JSON, no event name, not POST | 406 |
| Webhook secret not configured | 500 |
| Unknown API name in the URL | 404 |

## Simulating deliveries locally

No tunnel (ngrok…) and no real event are needed to try a consumer: `api-forge:webhook:send` signs a payload
with the secret of the webhook routing, exactly like the API, and delivers it to the application,
in-process:

```bash
bin/console api-forge:webhook:send lemonsqueezy tests/fixtures/order-created-payload.json \
    --set meta.custom_data.user_id=42
```

The output gives the status and explains it: accepted and consumed, or accepted and queued on an
asynchronous transport (then run the worker), or rejected by the parser (406) with the reason.
With `--url`, the delivery goes over HTTP instead, e.g. to check a running server or a tunnel end to end.
See [Commands](commands.md#api-forgewebhooksend).

## Delivery inbox

In development, every request reaching `/webhook/{type}` is recorded with its outcome, in
`var/api-forge/<env>/webhooks/` (the last 50 per type):

```bash
bin/console api-forge:webhook:inbox                       # most recent first
bin/console api-forge:webhook:inbox --show=20261103-0810  # headers and body of one
bin/console api-forge:webhook:replay 20261103-0810        # deliver it again, e.g. after fixing the consumer
```

```
 Id                           Received              Type           Event           Origin     Outcome
 20261103-081014-221-a1b2c3   2026-11-03 08:10:14   lemonsqueezy   order_created   provider   rejected: Invalid signature.
 20261103-080958-104-d4e5f6   2026-11-03 08:09:58   lemonsqueezy   order_created   simulated  consumed
```

The outcome is `consumed`, `queued` (then updated by the Messenger worker: `consumed` or `failed` with the
error), `rejected` (with the parser's reason), `failed` or `unknown_type`. The origin tells the provider's
deliveries from those of api-forge (`simulated` by `webhook:send` and the tests, `ping` by the doctor). A
replay is signed again with the routing secret. The doctor reports the last deliveries from the provider.

```yaml
# config/packages/api_forge.yaml
api_forge:
    webhook_inbox:
        enabled: true                   # false to record nothing
        directory: '%kernel.project_dir%/var/api-forge/%kernel.environment%/webhooks'
        keep: 50
```

## Checking the chain

When deliveries don't arrive, `api-forge:webhook:check` checks every link: routes imported, parser routed,
secret set, consumer registered, asynchronous transport (a worker must then run). With `--ping`, it also
sends a signed ping (event `api_forge_ping`, ignored by the consumers) to the public URL registered in the
API's dashboard:

```bash
bin/console api-forge:webhook:check lemonsqueezy --ping=https://abcd.ngrok-free.app/webhook/lemonsqueezy
```

It tells a URL missing the path (another page answers, not the endpoint's 202), an offline tunnel, a
server with another secret (406) or an unreachable host. It can't check the secret stored at the API
itself: the API's dashboard shows the response its first real delivery got.

## Testing

Define a webhook secret for the test environment (e.g. `LEMONSQUEEZY_WEBHOOK_SECRET=test-webhook-secret`
in `.env.test`), and use an in-memory `async` transport in the test environment (`when@test` in
`messenger.yaml`: `async: 'in-memory://'`), so queued events can be inspected. `WebhookTestTrait` posts
deliveries signed like the API:

```php
use Snorky22\ApiForge\Test\WebhookTestTrait;

final class OrderWebhookTest extends WebTestCase
{
    use WebhookTestTrait;

    public function testOrderCreated(): void
    {
        $client = self::createClient();
        $payload = json_decode(file_get_contents('tests/fixtures/order-created-payload.json'), true);
        $payload['meta']['custom_data'] = ['user_id' => '42'];

        self::assertSame(202, self::sendWebhook($client, 'lemonsqueezy', $payload)->getStatusCode());

        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->getSent()[0]->getMessage()->getEvent()->getName();  // "order_created"
    }
}
```

Without the async routing, the consumer runs during the request, and the test can check its effects
directly.

Consumers can be tested directly: `$consumer->consume(new RemoteEvent('order_created', '1', $payload))`.
See `tests/Example/` in the bundle.

## Hand-written webhooks

Without descriptors nor generated code, as for the [hand-written REST resources](rest-resources.md):
`api-forge:make:webhook` writes, next to the resources of the API (`src/API/<Api>/Webhook`):

| File | Content |
|---|---|
| `<Api>RequestParser.php` | The signature scheme, as constants of `AbstractSignedRequestParser` |
| `<Api>WebhookConsumer.php` | `#[AsRemoteEventConsumer('<type>')]`, `HANDLERS` and one method per event you handle, with TODOs |
| `config/packages/<type>_webhook.yaml` | The webhook type: its parser and its secret (`<TYPE>_WEBHOOK_SECRET`, empty by default) |
| `config/routes/webhook.yaml` | The route `POST /webhook/{type}`, when the application doesn't have it |

```
$ bin/console api-forge:make:webhook

 API, relative to App\API (e.g. Paddle):
 > Paddle

 Signature scheme:
  [paddle      ] Paddle-Signature: ts=…;h1=…, HMAC-SHA256 of "timestamp:body"; answered with 200 (Paddle)
  [stripe      ] Stripe-Signature: t=…,v1=…, HMAC-SHA256 of "timestamp.body" (Stripe)
  [github      ] X-Hub-Signature-256: sha256=<HMAC-SHA256 of the body>, event in X-GitHub-Event (GitHub; content type application/json)
  [shopify     ] X-Shopify-Hmac-Sha256: base64 HMAC-SHA256 of the body, topic in X-Shopify-Topic (Shopify)
  [mollie      ] X-Mollie-Signature: sha256=<HMAC-SHA256 of the body>, event in "type" (Mollie next-gen webhooks)
  [lemonsqueezy] X-Signature: HMAC-SHA256 of the body, event in meta.event_name; answered with 200 (Lemon Squeezy)
  [custom      ] another scheme: answer the questions (header, signed content, event name…)
 > paddle

 Known events: transaction.created, transaction.ready, transaction.paid, transaction.completed, …
 Events to handle (comma-separated, empty: none for now):
 > transaction.completed
```

The schemes are a list in the bundle (`WebhookMaker::SCHEMES`), enriched as providers are used: each gives the
parser constants and the provider's events (suggested, autocompleted).

| Scheme | Signature | Event name, id | Answer |
|---|---|---|---|
| `paddle` | `Paddle-Signature: ts=…;h1=…`, HMAC-SHA256 hex of `ts:body`, 5 s tolerance | `event_type`, `event_id` | 200 |
| `stripe` | `Stripe-Signature: t=…,v1=…`, HMAC-SHA256 hex of `t.body`, 300 s tolerance | `type`, `id` | 2xx (202) |
| `github` | `X-Hub-Signature-256: sha256=…`, HMAC-SHA256 hex of the body | headers `X-GitHub-Event`, `X-GitHub-Delivery` | 2xx (202) |
| `shopify` | `X-Shopify-Hmac-Sha256`, HMAC-SHA256 base64 of the body | headers `X-Shopify-Topic`, `X-Shopify-Webhook-Id` | 200 |
| `mollie` | `X-Mollie-Signature: sha256=…`, HMAC-SHA256 hex of the body (next-gen webhooks) | `type`, `id` | 200 |
| `lemonsqueezy` | `X-Signature`, HMAC-SHA256 hex of the body | `meta.event_name` (no id: a hash of the body) | 200 |

- GitHub: the webhook's content type must be `application/json` (the form-encoded one is rejected); the event is the
  header (`issues`), its action is in the payload (`action`): branch on it in the method. A `ping` event arrives when
  the webhook is created.
- Shopify: HTTPS subscriptions only (EventBridge and Pub/Sub deliveries are not signed this way); the secret is the
  app's client secret.
- Mollie: next-gen webhooks only. Three of the suggested events are in beta (`refund.refunded`,
  `chargeback.received`, `dispute.updated`), and Mollie still recommends its classic webhooks for payment updates.
- Lemon Squeezy: its docs say it no longer accepts new sign-ups and shuts down on March 31, 2027.
- Not expressible as constants, a parser of your own is needed: Square (the notification URL is part of the signed
  content), PayPal (RSA signature, certificate), Mollie's classic webhooks (unsigned, form-encoded, the object is
  fetched from the API), Standard Webhooks / Svix (id and timestamp in other headers than the signature). `custom` asks for the constants: the signature
header, whether it holds a timestamp (`t=…,v1=…`, `ts=…;h1=…`), the signed content, a prefix (`sha256=`), the
algorithm and encoding, where the event name and id are, and the status of the response. The parser is only
constants: check them against the provider's docs (its `@see`), and correct them there.

The written parser for Paddle:

```php
final class PaddleRequestParser extends AbstractSignedRequestParser
{
    protected const SIGNATURE_HEADER = 'Paddle-Signature';
    protected const SIGNATURE_CONTENT = '{timestamp}:{body}';
    protected const SIGNATURE_PARTS = ['separator' => ';', 'timestamp' => 'ts', 'signature' => 'h1'];
    protected const SIGNATURE_TOLERANCE = 5;
    protected const EVENT_NAME_PATH = 'event_type';
    protected const EVENT_ID_PATH = 'event_id';
    protected const SUCCESS_STATUS = 200;
}
```

Run the command again on the same API to add events to the consumer: it lists those it has, suggests the others,
and adds the chosen ones (`HANDLERS` entries and empty methods), keeping the rest of the file as it is. Without
interaction: `--scheme`, `--events`, `--type` (the end of the URL, default from the API: `paddle`).

Then:

1. Set the secret in `.env.local`: `PADDLE_WEBHOOK_SECRET=...` (for Paddle, the secret key of the notification
   destination, in *Paddle > Events > Notifications*).
2. Try a delivery signed like the provider, without the provider (the command prints a minimal payload):
   ```bash
   bin/console api-forge:webhook:send paddle var/paddle-event.json
   bin/console api-forge:webhook:inbox paddle
   bin/console api-forge:webhook:check paddle
   ```
3. For real deliveries, the provider needs a public HTTPS URL to `/webhook/paddle`: in development, a tunnel
   (Hookdeck, ngrok, Cloudflare Tunnel). Paddle's simulator (*Paddle > Events > Simulations*) sends signed test
   events to a destination, without a real payment.

Queue the events (`ConsumeRemoteEventMessage: async`, see [Setup](#setup)) when the provider waits for the answer:
Paddle waits 5 seconds, then retries. The tools of this page (`webhook:send`, the inbox, `webhook:replay`,
`webhook:check`, `WebhookTestTrait`) work the same with hand-written webhooks; `webhook:check` counts as handled
the events of `HANDLERS` whose method exists.

