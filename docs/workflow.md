# Workflow

`api-forge:guide` summarizes this page and tells, for an API, its state and the next step:

```bash
bin/console api-forge:guide              # the workflow, the conventions, the APIs
bin/console api-forge:guide lemonsqueezy # examples, descriptors, review notes, doctor probe, generated code
```

The next step comes with the commands to copy: the examples of `tests/Fixtures/<api>/` not imported yet (a
curl whose method and path are no operation of the descriptors) with their exact `api-forge:import`
command, then `api-forge:generate` when the generated code is out of date, then `api-forge:doctor`.

## Documentation examples

Keep the examples copied from the API docs in `tests/Fixtures/<api>/`: they are the input of the imports,
and fixtures for the tests (not in `src/`, which is shipped with the package). One file each, named after
the operation, its HTTP method or action, and the resource, as in the descriptors:

| File | Content | Examples |
|---|---|---|
| `<operation>-<resource>.curl` | a documented request: the cURL sample of the docs | get-account.curl, list-products.curl |
| `<operation>-<resource>.json` | its example response | get-account.json, list-products.json |
| `<operation>-<resource>.php` | the same, written by hand as an array when the docs describe it in prose: request and response | create-price.php, list-prices.php |
| `<type>-type.json` | a data type the other examples point to by its file name (also .php) | duration-type.json, unit-price-override-type.php |
| `<resource>-object.json` | an object example ("The product object") | transaction-object.json |
| `webhook-events.md` | the events table of the docs | webhook-events.md |
| `webhook-<event>.json` | a webhook payload example | webhook-transaction.completed.json |

`<operation>` is `list`, `get`, `create`, `update`, `delete`, or the action of the docs (`refund`, `cancel`).

## Adding an endpoint

1. Copy the curl example from the API docs into a file, and the example response (if any) into
   another one.
2. Import it:
   ```bash
   bin/console api-forge:import lemonsqueezy tests/Fixtures/lemonsqueezy/list-customers.curl \
       --response=tests/Fixtures/lemonsqueezy/list-customers.json
   ```
   The command shows the resource, the operation name and the path template it inferred, and the
   points to review.
3. If the docs describe the resource object on its own ("The customer object"), import it as well.
   It types all the operations of the resource, including those without a response example:
   ```bash
   bin/console api-forge:import-object lemonsqueezy customers tests/Fixtures/lemonsqueezy/customer-object.json
   ```
4. Review the descriptor in `config/api/<api>/<resource>.yaml` (see below), then delete the
   `review` notes you have dealt with.
5. Regenerate:
   ```bash
   bin/console api-forge:generate lemonsqueezy
   ```
6. Commit the descriptors and the generated files together: the diff of the generated code shows
   the effect of the descriptor changes.

When the docs describe a request or a response in prose only (no curl, no JSON to copy), write it as a PHP
array: the "plain text example" format of the table above, read by `api-forge:import`, `import-object` and
`import-webhook` like a curl or JSON file. See [Examples written as arrays](#examples-written-as-arrays).

Several examples of the same endpoint can be imported one after the other: the second one adds its
query parameters to the existing operation (e.g. `list-customers.curl` then `list-customers-by-email.curl`).

## Examples written as arrays

A file `<operation>-<resource>.php` is data, never run code (comments allowed: write the constraints of the
docs next to the values):

```php
<?php

return [
    'request' => 'POST /prices',            // "METHOD path" (once the API has a base URI), or "METHOD https://full/url"
    'query' => ['include' => 'product'],    // optional; a list is joined with commas, a nested array is name[key]
    'headers' => ['Paddle-Version' => '1'], // optional
    'body' => ['description' => 'Monthly', 'unit_price' => ['amount' => '1000', 'currency_code' => 'EUR']], // sent as JSON
    'response' => [                         // optional: the example response
        'data' => [[
            'id' => 'pri_01',
            // list of overrides, max 250; country_codes: min 1, unique
            'unit_price_overrides' => [['country_codes' => ['FR'], 'unit_price' => ['amount' => '900', 'currency_code' => 'EUR']]],
        ]],
    ],
];
```

```bash
bin/console api-forge:import paddle tests/Fixtures/paddle/create-price.php
```

api-forge turns it into the curl command and the JSON response the importers read. The values are the example:
fill the fields the docs leave empty or null (their type can't be guessed otherwise). An empty array is `[]`
in JSON, write one entry to type a list. Only arrays, strings, numbers, `true`, `false` and `null` are accepted:
a function call or a variable is refused with its line.

As a response (`--response`), an object example (`import-object`) or a webhook payload (`--payload`), a `.php`
file holds the JSON document itself as an array. `api-forge:json-to-php` writes the array of an existing JSON
example, to complete it by hand.

### Pointing to other data files

API documentation often describes a type once and points to it ("see the Duration object"). Do the same in an
example, whatever its format (JSON, array, a request body, an object, a webhook payload): a value that is the
name of a data file, with the extension `json`, `php` or `array`, is replaced by the content of that file. It is
resolved from the directory of the file that holds it (`../shared/money-type.json` works), the files may point
to others, and a cycle is an error.

```jsonc
// list-prices.json
{"data": [{"billing_cycle": "duration-type.json", "trial_period": "duration-type.json",
           "unit_price_overrides": ["unit-price-override-type.php"]}]}
```

```php
<?php return ['country_codes' => ['FR'], 'unit_price' => 'money-type.json'];   // unit-price-override-type.php
```

Name the type files `<type>-type.json`, `.php` or `.array` (a `.array` file is the bare array, without `<?php return`) (`api-forge:guide` leaves them out of the examples to import).
A string that must stay a file name is written with a leading backslash (`"\\export.json"` in JSON,
`'\export.json'` in PHP). A name that matches no file is an error, which says so. Documents without any such
value are read exactly as before. For now each use is expanded in the descriptor; curl files are not data
and can't be referenced.

Other formats of documentation can be added: implement `ExampleFormat` (extensions, an operation, a JSON
document); autoconfiguration registers it.

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
| `"mixed" marks values that are null in the example` (the keys are listed: `data[].trial_period`) | Set the real type, e.g. `?string` |
| `"list<mixed>" marks empty arrays in the example` (the keys are listed) | Use `array<string, mixed>` if it is an empty object |
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
