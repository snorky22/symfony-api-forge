# symfony-api-forge

Symfony bundle that generates services for REST APIs and their webhooks from their documentation: the
curl examples and the JSON examples found in the API owner's docs.

```
curl + JSON ────api-forge:import──────────┐
object JSON ────api-forge:import-object───┤
events table,  ─api-forge:import-webhook──┼──> config/api/<api>/*.yaml ──api-forge:generate──> src/Api/<Namespace>/Generated/
payload JSON                              │    descriptors, edited by hand                     config/packages/api_<api>.yaml
```

- **Lenient import, strict generation**: documentation examples become editable YAML descriptors, with
  every guess listed for review; the code is regenerated from the descriptors, so manual fixes survive.
- **Typed REST clients**: one service per resource on a scoped HttpClient, JSON:API request bodies and
  pagination, PHPStan / PhpStorm array shapes for every response.
- **Signed webhooks**: request parser checking the HMAC signature (Symfony Webhook), typed consumer
  methods per event (RemoteEvent), asynchronous processing with Messenger.
- **Local webhook testing**: `api-forge:webhook:send` and `WebhookTestTrait` deliver payloads signed like
  the API, without a tunnel nor a real event; `api-forge:webhook:check` finds what breaks the chain; the
  delivery inbox records what arrived and why it was rejected, `api-forge:webhook:replay` delivers it again.
- **Generated doctors**: one per API, regenerated with its services; `api-forge:doctor` checks the
  credentials, calls a harmless probe and diagnoses what fails (unreachable, rejected key, wrong URL…).

## Requirements

- PHP 8.2 or later
- Symfony 7.4

## Installation

```bash
composer require snorky22/symfony-api-forge
```

With Symfony Flex, the bundle is registered automatically. Otherwise, add it to `config/bundles.php`:

```php
return [
    // ...
    Snorky22\ApiForge\ApiForgeBundle::class => ['all' => true],
];
```

The bundle provides the `api-forge:*` commands. The generated code only depends on the runtime classes of
the package (`Snorky22\ApiForge\Core`), so the bundle can be enabled in the dev environment only
(`['dev' => true]`).

To receive webhooks, the `/webhook/{type}` route of the Symfony Webhook component must be imported; the
Flex recipe of `symfony/webhook` adds it (`config/routes/webhook.yaml`). For asynchronous processing,
install `symfony/messenger`, see [Webhooks](docs/webhooks.md).

## Configuration

All the options are optional; these are the defaults:

```yaml
# config/packages/api_forge.yaml
api_forge:
    descriptor_dir: '%kernel.project_dir%/config/api'   # descriptors
    namespace: 'App\Api'                                # namespace of the generated code
    source_dir: 'src/Api'                               # its directory, relative to the project
    config_dir: 'config/packages'                       # generated configuration, relative to the project
```

## Quick start

```bash
# 1. Import documented requests (and example responses), objects and webhooks into descriptors
# (documentation examples in tests/Fixtures/<api>/, named as `api-forge:guide` shows)
bin/console api-forge:import lemonsqueezy tests/Fixtures/lemonsqueezy/list-customers.curl
bin/console api-forge:import-object lemonsqueezy customers tests/Fixtures/lemonsqueezy/customer-object.json
bin/console api-forge:import-webhook lemonsqueezy --events=tests/Fixtures/lemonsqueezy/webhook-events.md \
    --payload=tests/Fixtures/lemonsqueezy/webhook-order_created.json

# 2. Review config/api/lemonsqueezy/*.yaml, fix the guesses listed under "review"

# 3. Generate the services
bin/console api-forge:generate lemonsqueezy
```

Secrets go in `.env.local`, under the names given in the descriptors (e.g. `LEMONSQUEEZY_API_KEY`,
`LEMONSQUEEZY_WEBHOOK_SECRET`).

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

[`examples/lemonsqueezy/`](examples/lemonsqueezy/) shows the result for Lemon Squeezy: descriptors,
generated code and configuration, and a webhook consumer.

## Documentation

| Page | Content |
|---|---|
| [Architecture](docs/architecture.md) | Pipeline, design choices, package layout |
| [Workflow](docs/workflow.md) | Adding an API or an endpoint, step by step; documentation examples (`api-forge:guide`) |
| [Descriptors](docs/descriptors.md) | Reference of the descriptor format (`config/api/`) |
| [Commands](docs/commands.md) | Console commands and their options |
| [Generated services](docs/generated-services.md) | Using the generated code, the runtime layer, errors, pagination |
| [Doctor](docs/doctor.md) | Generated doctors: what they check, the probe |
| [Webhooks](docs/webhooks.md) | Receiving webhooks: setup, consumers, payload types, simulated deliveries, testing |
| [Roadmap](docs/roadmap.md) | Known limitations and next steps |

## Development

```bash
git clone git@github.com:snorky22/symfony-api-forge.git
cd symfony-api-forge
composer install
vendor/bin/phpunit
```

The tests run a small application (`tests/App/TestKernel.php`) with the bundle and the Lemon Squeezy
example. After changing the generator, regenerate the example, which a test checks:

```bash
php tests/console api-forge:generate lemonsqueezy --dir=examples/lemonsqueezy/config/api --output=examples/lemonsqueezy
```

## License

MIT, see [LICENSE](LICENSE).
