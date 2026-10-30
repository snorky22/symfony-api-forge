# API services generator

Generates Symfony services for REST APIs and their webhooks from their documentation: the curl
examples and the JSON examples found in the API owner's docs.

```
curl + JSON ────app:api:import──────────┐
object JSON ────app:api:import-object───┤
events table,  ─app:api:import-webhook──┼──> config/api/<api>/*.yaml ──app:api:generate──> src/Api/<Namespace>/Generated/
payload JSON                            │    descriptors, edited by hand                   config/packages/api_<api>.yaml
```

## Documentation

| Page | Content |
|---|---|
| [Architecture](architecture.md) | Pipeline, design choices, directory layout |
| [Workflow](workflow.md) | Adding an API or an endpoint, step by step |
| [Descriptors](descriptors.md) | Reference of the descriptor format (`config/api/`) |
| [Commands](commands.md) | Console commands and their options |
| [Generated services](generated-services.md) | Using the generated code, the runtime layer, errors, pagination |
| [Webhooks](webhooks.md) | Receiving webhooks: setup, consumers, payload types, testing |
| [Roadmap](roadmap.md) | Known limitations and next steps |

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

## Supported APIs

| API | Descriptors | Generated code | Webhook endpoint |
|---|---|---|---|
| Lemon Squeezy | `config/api/lemonsqueezy/` | `src/Api/LemonSqueezy/Generated/` | `/webhook/lemonsqueezy` |
