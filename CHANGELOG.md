# Changelog

All notable changes are documented in this file. The project follows [Semantic Versioning](https://semver.org);
until 1.0, minor versions may contain breaking changes.

## Unreleased

- `api-forge:webhook:send`: simulated webhook deliveries, signed with the routing secret, in-process or
  over HTTP, with values replaced in the payload (`--set`); no tunnel nor real event needed
- `WebhookTestTrait::sendWebhook()`: signed deliveries in functional tests
- `AbstractSignedRequestParser::deliveryHeaders()`: the headers of a delivery, as the API sends them
- `api-forge:webhook:check`: checks the webhook chain (routes, parser, secret, consumer, Messenger) and,
  with `--ping`, a public URL (tunnel); `WebhookDiagnostics`, `WebhookSender::ping()`,
  `AbstractSignedRequestParser::pingPayload()`
- `api-forge:webhook:send --url` only reports success for the endpoint's 202: another 2xx means the URL
  reaches another page (path missing)

- Example: Lemon Squeezy subscriptions, subscription invoices and license keys (object shapes reviewed
  against the attribute docs), which types the payloads of 13 more webhook events; webhook `meta` shape
  completed (`test_mode`, `webhook_id`, `custom_data`); signing scheme checked against the docs

## 0.1.0 - 2026-10-30

First release.

- `api-forge:import`: curl commands and example responses → descriptors
- `api-forge:import-object`: resource object examples → object shapes, several examples merged into one
- `api-forge:import-webhook`: events table and payload examples → `_webhooks.yaml`
- `api-forge:generate`: strict validation of the descriptors, generation of the resource services, entry
  point, webhook request parser, event constants and payload types, base consumer, and configuration
  (scoped HTTP client, webhook routing); `--check` and `--strict` for CI
- `api-forge:json-to-php`: JSON example → PHP array literal and PHPDoc array shape
- Runtime classes (`Snorky22\ApiForge\Core`): JSON and JSON:API resources, pagination, `ApiException`,
  signed webhook request parser, event consumer
- Example: Lemon Squeezy customers, orders, checkouts and webhooks, in `examples/lemonsqueezy/`
