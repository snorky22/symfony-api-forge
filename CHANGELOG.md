# Changelog

All notable changes are documented in this file. The project follows [Semantic Versioning](https://semver.org);
until 1.0, minor versions may contain breaking changes.

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
