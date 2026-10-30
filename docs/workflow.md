# Workflow

## Adding an endpoint

1. Copy the curl example from the API docs into a file, and the example response (if any) into
   another one.
2. Import it:
   ```bash
   bin/console api-forge:import lemonsqueezy path/to/curl --response=path/to/response.json
   ```
   The command shows the resource, the operation name and the path template it inferred, and the
   points to review.
3. If the docs describe the resource object on its own ("The customer object"), import it as well.
   It types all the operations of the resource, including those without a response example:
   ```bash
   bin/console api-forge:import-object lemonsqueezy customers path/to/customer-object.json
   ```
4. Review the descriptor in `config/api/<api>/<resource>.yaml` (see below), then delete the
   `review` notes you have dealt with.
5. Regenerate:
   ```bash
   bin/console api-forge:generate lemonsqueezy
   ```
6. Commit the descriptors and the generated files together: the diff of the generated code shows
   the effect of the descriptor changes.

Several examples of the same endpoint can be imported one after the other: the second one adds its
query parameters to the existing operation (e.g. `curl-list-customers-1` then `curl-list-customers-2`).

## Adding webhooks

1. Copy the table of event types from the docs into a Markdown file, and the payload examples into
   JSON files. Import them:
   ```bash
   bin/console api-forge:import-webhook lemonsqueezy --events=path/to/events.md \
       --payload=path/to/order-created-payload.json
   ```
   The first import creates `config/api/<api>/_webhooks.yaml`.
2. Check the `signature` scheme against the API docs: the importer writes a default
   (HMAC-SHA256 of the raw body, hex, in `X-Signature`), as signing schemes cannot be read from examples.
3. Payload data often differs from the documented resource object (e.g. Lemon Squeezy sends
   `subtotal: 1499.985` in webhooks, an integer in the order object). The importer reports it; merge
   all the examples into the object shape:
   ```bash
   bin/console api-forge:import-object lemonsqueezy orders path/to/order-object.json \
       path/to/order-created-payload.json --force
   ```
   This gives `subtotal: int|float`, `refunded: ?bool`, and makes optional the keys missing from some
   examples.
4. Regenerate, then handle the events in the consumer (see [Webhooks](webhooks.md)).

## Adding an API

Same as above with a new API name. The first import creates `config/api/<api>/_api.yaml` with the
base URI, format, default headers and authentication found in the curl command. Then:

- set `namespace` in `_api.yaml` if the API name does not give the right PHP casing
  (`lemonsqueezy` → `LemonSqueezy`);
- define the API key in `.env.local`, under the name given by `auth.env`
  (e.g. `LEMONSQUEEZY_API_KEY=...`), and the webhook secret under the name given by `signature.env`
  in `_webhooks.yaml`;
- write the webhook consumer, see [Webhooks](webhooks.md).

## Reviewing descriptors

The importer lists its guesses under `review`. The usual ones:

| Note | What to do |
|---|---|
| `path parameter {id} guessed from the example value "1"` | Check the parameter name |
| `"mixed" marks values that are null in the example` | Set the real type, e.g. `?string` |
| `"list<mixed>" marks empty arrays in the example` | Use `array<string, mixed>` if it is an empty object |
| `all attributes are typed as optional` | Remove the `?` of the required attributes |
| `query parameters are assumed optional` | Set `required: true` where needed |
| `no response example: assumed to be a JSON:API document of the resource` | Check the docs |
| `signature: default scheme ...` | Check the signing scheme in the API docs |
| `payload data differs from the "orders" object shape` | Merge the examples with `api-forge:import-object --force` |
| `the resource "subscriptions" has no object shape` (shown by `api-forge:generate`) | Import the resource object |

`api-forge:generate` lists the notes left, and `--strict` makes it fail while any remain.

Note: re-importing rewrites the YAML file, so YAML comments are lost. Keep notes in `review` entries.

## Keeping generated code up to date

Run `api-forge:generate <api> --check` in CI or in a test: it fails if the committed generated code does
not match the descriptors. In the bundle, `tests/Command/ApiGenerateCommandTest.php` runs it on the
Lemon Squeezy example.

## Converting a single JSON example

To look at a JSON example as PHP, or to get its PHPDoc shape (e.g. for test fixtures):

```bash
bin/console api-forge:json-to-php path/to/example.json --shape
pbpaste | bin/console api-forge:json-to-php --shape-only --tag='phpstan-type Order'
```
