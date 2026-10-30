# symfony-api-forge

Generates Symfony services for REST APIs and their webhooks from their documentation: the curl
examples and the JSON examples found in the API owner's docs.

```
curl + JSON ────app:api:import──────────┐
object JSON ────app:api:import-object───┤
events table,  ─app:api:import-webhook──┼──> config/api/<api>/*.yaml ──app:api:generate──> src/Api/<Namespace>/Generated/
payload JSON                            │    descriptors, edited by hand                   config/packages/api_<api>.yaml
```

- **Lenient import, strict generation**: documentation examples become editable YAML descriptors, with
  every guess listed for review; the code is regenerated from the descriptors, so manual fixes survive.
- **Typed REST clients**: one service per resource on a scoped HttpClient, JSON:API request bodies and
  pagination, PHPStan / PhpStorm array shapes for every response.
- **Signed webhooks**: request parser checking the HMAC signature (Symfony Webhook), typed consumer
  methods per event (RemoteEvent), asynchronous processing with Messenger.

## Requirements

- PHP 8.2 or later, Composer
- Symfony 7.4

## Installation

```bash
git clone git@github.com:snorky22/symfony-api-forge.git
cd symfony-api-forge
composer install
bin/phpunit
```

Secrets go in `.env.local` (not committed), e.g. `LEMONSQUEEZY_API_KEY` and
`LEMONSQUEEZY_WEBHOOK_SECRET`.

## Quick start

```bash
# 1. Import documented requests (and example responses) into descriptors
bin/console app:api:import lemonsqueezy tests/Fixtures/curl-list-customers-1
bin/console app:api:import-object lemonsqueezy customers tests/Fixtures/customer-object.json
bin/console app:api:import-webhook lemonsqueezy --events=events.md --payload=tests/Fixtures/order-created-payload.json

# 2. Review config/api/lemonsqueezy/*.yaml, fix the guesses listed under "review"

# 3. Generate the services
bin/console app:api:generate lemonsqueezy
```

```php
public function __construct(private LemonSqueezyApi $lemonSqueezy) {}

foreach ($this->lemonSqueezy->customers->iterate() as $customer) {
    $customer['attributes']['email'];
}

#[AsRemoteEventConsumer('lemonsqueezy')]
final class LemonSqueezyWebhookConsumer extends AbstractLemonSqueezyWebhookConsumer
{
    protected function onOrderCreated(array $payload, RemoteEvent $event): void { /* ... */ }
}
```

## Documentation

| Page | Content |
|---|---|
| [Architecture](docs/architecture.md) | Pipeline, design choices, directory layout |
| [Workflow](docs/workflow.md) | Adding an API or an endpoint, step by step |
| [Descriptors](docs/descriptors.md) | Reference of the descriptor format (`config/api/`) |
| [Commands](docs/commands.md) | Console commands and their options |
| [Generated services](docs/generated-services.md) | Using the generated code, the runtime layer, errors, pagination |
| [Webhooks](docs/webhooks.md) | Receiving webhooks: setup, consumers, payload types, testing |
| [Roadmap](docs/roadmap.md) | Known limitations and next steps |

## Supported APIs

| API | Descriptors | Generated code | Webhook endpoint |
|---|---|---|---|
| Lemon Squeezy | `config/api/lemonsqueezy/` | `src/Api/LemonSqueezy/Generated/` | `/webhook/lemonsqueezy` |
