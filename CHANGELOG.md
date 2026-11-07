# Changelog

All notable changes are documented in this file. The project follows [Semantic Versioning](https://semver.org);
until 1.0, minor versions may contain breaking changes.

## Unreleased

- The review notes about `mixed` and `list<mixed>` list the keys concerned as paths (`data[].trial_period`, up to 10)

- Array examples accept the extension `.array`: the bare array literal, without `<?php return`
- The review note of a response or request body that is not JSON says why (`LenientJsonDecoder::explain()`): the
  line of a single-quoted string, or the JSON error

- Examples can point to other data files, as API documentation points to types it describes elsewhere: a value
  that is a relative file name with a data extension (`"duration-type.json"`, `'money-type.php'`) is replaced by
  the content of that file, in JSON examples, array examples (request body and response), objects and webhook
  payloads. Resolved from the directory of the file that holds it, nested, cycles refused, a missing file is an
  error, a leading backslash keeps a string as it is; documents without such a value are untouched;
  `<type>-type.json|php` files are left out of the examples `api-forge:guide` proposes to import.
  `ExampleFormat::json()` is replaced by `document()` and `operation()` takes the resolver

- Documentation examples written as PHP arrays (`<operation>-<resource>.php`), for what the docs describe in prose
  only: `request` (`METHOD path` or URL), `query`, `headers`, `body` and `response`. `api-forge:import` turns
  them into the curl command and JSON response it reads; `import-object` and `import-webhook --payload` accept
  an array as the JSON document; `api-forge:guide` lists them with the examples to import. The file is read as
  data, never run (`PhpLiteral`: arrays, strings, numbers, true/false/null; comments allowed; anything else
  is refused with its line). `ExampleFormat` (tag `api_forge.example_format`) adds other formats of
  documentation examples; `ExampleReader` reads them

- `api-forge:yaml-to-php`: a YAML file (a descriptor) as a PHP array literal, multi-line strings as nowdocs;
  the code is checked (tokens of a literal only, then evaluated and compared with the parsed YAML, keys,
  order and types) before it is printed or written; `--output` writes a file that returns the array and
  never overwrites without `--force`; comments, which PHP data can't carry, are listed on stderr

- Hosts chosen at runtime: `environments` (other hosts of the API, e.g. a sandbox) and `base_uri_env` in
  `_api.yaml`; the generated client uses the environment variable, `base_uri` by default; the importer
  accepts the URLs of every listed host and suggests this setup for a first import from a sandbox host;
  the doctor tells the host in use

- `api-forge:guide`: the workflow, the naming of the documentation examples (`tests/Fixtures/<api>/`,
  `<operation>-<resource>.curl` / `.json`…, documented in `docs/workflow.md`), and the state of an API with
  its next step and the commands to copy (examples not imported yet, generation, doctor)

- Generated doctors: `Generated/<Namespace>Doctor.php`, regenerated with the services, checks the
  environment variables of the API and calls its probe (`doctor.probe` in `_api.yaml`, a harmless `GET`,
  guessed by the importer, validated by the loader), diagnosing unreachable hosts, rejected credentials,
  wrong base URIs, rate limits and provider errors; `api-forge:doctor` runs them with the webhook checks
- Webhook delivery inbox (development, `api_forge.webhook_inbox`): every delivery to `/webhook/{type}` is
  recorded with its origin (provider, simulated, ping) and outcome (consumed, queued then updated by the
  Messenger worker, rejected with the reason, failed); `api-forge:webhook:inbox` lists and shows them,
  `api-forge:webhook:replay` delivers one again; the doctor reports the last deliveries;
  `AbstractSignedRequestParser::describe()`
- Doctor, webhooks: event coverage (the events the application's consumer handles); `registration` in
  `_webhooks.yaml` tells the generated doctor how to read the webhooks registered at the provider, checked
  against the endpoint path and the handled events, and pinged; `ApiDiagnosis` runs the whole diagnosis of
  an API (for api-forge:doctor and bundles built on it); `api-forge:doctor --no-ping`
- Doctor contract check: the probe's response is compared with its shape in the descriptor (missing and
  new fields, types, enum values), with `ShapeChecker`, a runtime checker of values against array shapes.
  After upgrading, regenerate the services: the generated `probe()` now returns the response (a doctor
  generated before doesn't match `AbstractApiDoctor`; change its `probe(): void` to `probe(): array` with a
  `return` if the kernel can't boot to regenerate)

- `api-forge:webhook:send`: simulated webhook deliveries, signed with the routing secret, in-process or
  over HTTP, with values replaced in the payload (`--set`); no tunnel nor real event needed
- `WebhookTestTrait::sendWebhook()`: signed deliveries in functional tests
- `AbstractSignedRequestParser::deliveryHeaders()`: the headers of a delivery, as the API sends them
- `api-forge:webhook:check`: checks the webhook chain (routes, parser, secret, consumer, Messenger) and,
  with `--ping`, a public URL (tunnel); `WebhookDiagnostics`, `WebhookSender::ping()`,
  `AbstractSignedRequestParser::pingPayload()`; checks are `Dev\Doctor\Check` objects
- Descriptors: `body.id` (JSON:API) puts the resource id, the last path parameter, in the request
  document of actions like `POST orders/{id}/refund`; the importer sets it when the example document has
  an id. PATCH and PUT keep doing it by default
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
