# Workflow

`api-forge:guide` summarizes this page and tells, for an API, its state and the next step:

```bash
bin/console api-forge:guide              # the workflow, the conventions, the APIs
bin/console api-forge:guide lemonsqueezy # examples, descriptors, review notes, doctor probe, generated code
```

Prefer forms to files and commands? [`api-forge:serve`](web.md) runs the same workflow in your browser.

The next step comes with the commands to copy: the examples of `tests/Fixtures/<api>/` not imported yet (a
request whose method and path are no operation of the descriptors) with their exact `api-forge:import`
command, then `api-forge:generate` when the generated code is out of date, then `api-forge:doctor`.

## The workflow at a glance

1. **Collect** what the API docs give you, in `tests/Fixtures/<api>/`: cURL samples, JSON responses, and, when
   the docs only describe something in words, a small PHP array that you write from the description.
2. **Import** each example: `api-forge:import <api> <example>`. The importer writes the descriptors
   (`config/api/<api>/*.yaml`) and lists what it had to guess under `review`.
3. **Complete** the examples rather than the descriptors: fill what is null or empty, point to the types the docs
   describe elsewhere, add the docs page as text. Import again.
4. **Generate**: `api-forge:generate <api>`, then **check** with `api-forge:doctor <api>`.

The descriptors are produced by the importer: fix a type by improving the example and importing again. The files
in `tests/Fixtures/<api>/` are the source, a human checks them once, and they stay in git.

## Where the files go and how they are named

Keep the examples in `tests/Fixtures/<api>/`: they are the input of the imports, and fixtures for the tests (not in
`src/`, which is shipped with the package). The pattern is `<operation>-<resource>`, then what the file is, given by
its extension:

| File | Content | Examples |
|---|---|---|
| `<operation>-<resource>.curl` | a documented request: the cURL sample of the docs | get-account.curl, list-products.curl |
| `<operation>-<resource>.json` | its example response | get-account.json, list-products.json |
| `<operation>-<resource>.php` | the same, written by hand as an array when the docs describe it in prose: request and response | create-price.php, list-prices.php |
| `<operation>-<resource>.docs.md` | the docs page of its response copied as text: required fields, nulls, enums | list-prices.docs.md |
| `<operation>-<resource>.body.json` | the JSON body of its request, next to the .curl (an array example has "body") | create-products.body.json |
| `<operation>-<resource>.body.docs.md` | the docs page of that body copied as text: required keys, enums | create-products.body.docs.md |
| `<type>-type.json` | a data type the other examples point to by its file name (also .php) | duration-type.json, unit-price-override-type.php |
| `<resource>-object.json` | an object example ("The product object") | transaction-object.json |
| `webhook-events.md` | the events table of the docs | webhook-events.md |
| `webhook-<event>.json` | a webhook payload example | webhook-transaction.completed.json |

`<operation>` is `list`, `get`, `create`, `update`, `delete`, or the action of the docs (`refund`, `cancel`).
For a second example of the same operation, add a variant: `list-prices-include.php`, `list-customers-by-email.curl`.

What api-forge reads from a file name, and what it does not:

| It reads | It does not read |
|---|---|
| the **extension**: `.curl` (or none) is a request, `.json` a JSON document, `.php` / `.array` an array | the **operation and the resource**: they come from the request inside the file (its method and path). A file named `anything.php` holding `'request' => 'GET /prices'` is the `list` operation of `prices` |
| the **base name** to pair files: `api-forge:import` finds `list-prices.docs.md` next to the example it imports; `api-forge:guide` pairs `list-prices.json` with `list-prices.curl` in the command it proposes (`--response=`) | |
| the **start and the end** to leave a file out of the examples to import: `webhook-…`, `…-object.…`, `…-type.…` | |

So the names are for you and for `api-forge:guide`, which proposes the imports and pairs the files. Keep to the
pattern: the operation and the resource tell what the file is without opening it.

## Which file for what the docs give you

| The docs give you | You write | Import with |
|---|---|---|
| a cURL sample and a JSON response | `list-prices.curl` and `list-prices.json` | `api-forge:import paddle …/list-prices.curl --response=…/list-prices.json` |
| a JSON response with no request sample | `list-prices.curl` with the request (`curl https://api.example.com/prices`), and the JSON | same |
| the endpoint and its parameters in words (no sample) | `create-price.php`: the request and its response as an array ([below](#giving-the-request)) | `api-forge:import paddle …/create-price.php` |
| query parameters (`?include=product`) | a second example of the same operation: a curl with the query string, or a `.php` with `query` | `api-forge:import` it: its query parameters join the operation |
| a request body (POST, PUT, PATCH) | the JSON in `<operation>-<resource>.body.json` next to the `.curl` (or `-d` in the curl, or `body` in the `.php`); the page of its parameters as `<operation>-<resource>.body.docs.md` | `api-forge:import` the `.curl`: it finds both |
| a field that is described as a type elsewhere ("see the Duration object") | a type file `duration-type.json`, and its name as the value in the example | automatic, when the example is imported |
| a page listing the fields (required, null, enums) | `list-prices.docs.md`: the page's field list copied as text | automatic, when the example is imported |
| the object on its own ("The customer object") | `customer-object.json` | `api-forge:import-object <api> customers …/customer-object.json` |
| the webhook events and payloads | `webhook-events.md`, `webhook-order_created.json` | `api-forge:import-webhook` ([Adding webhooks](#adding-webhooks)) |

## Giving the request

The request of an operation is what api-forge needs first: its method and path make the operation, and its
parameters become the method's arguments. Two ways to give it.

### As a curl command

Copy the sample of the docs into `<operation>-<resource>.curl`: the URL, `-X`, `-H`, `-d` (see
[`api-forge:import`](commands.md#api-forgeimport) for the syntax it reads). The query string is part of the URL, the
body is `-d`:

```bash
curl 'https://api.paddle.com/prices?status=active&include=product' -H 'Authorization: Bearer YOUR_API_KEY'
curl https://api.paddle.com/prices -X POST -H 'Content-Type: application/json' -d '{"product_id": "pro_01", "description": "Monthly"}'
```

### As an array, when there is no sample

When the docs describe the request in words, write it as `<operation>-<resource>.php` (or `.array`, the bare
array without `<?php return`). api-forge turns it into the curl command above. It is data, never run code, and may hold
comments: write the constraints of the docs next to the values.

```php
<?php

return [
    'request' => 'POST /prices',            // "METHOD path", once the API has a base URI; before, "METHOD https://full/url"
    'query' => ['include' => 'product'],    // optional: ?include=product (a list is joined with commas, a nested array is name[key])
    'headers' => ['Paddle-Version' => '1'], // optional
    'body' => [                             // optional: sent as JSON
        'product_id' => 'pro_01gsz97mq9pa4fkyy0wqenepkz',
        'description' => 'Monthly',
        'unit_price' => ['amount' => '1000', 'currency_code' => 'EUR'],
    ],
    'response' => [                         // optional: the example response
        'data' => ['id' => 'pri_01', 'status' => 'active'],
    ],
];
```

| Key | Content |
|---|---|
| `request` | `'GET /prices'`, `'GET /prices/{price_id}'` (a path parameter is written between braces), or with the full URL for the first import of an API |
| `query` | the query parameters, name => example value |
| `headers` | headers to send, name => value |
| `body` | the JSON body, as an array |
| `response` | the example response, as an array |

Only `request` is needed. The other keys are optional, and the first import of an API needs the full URL since there
is no base URI yet (the importer reads it, with the format and the authentication, from the first request).

### The request body and the response: which JSON is which

Next to `<operation>-<resource>.curl`, the two JSON files are told apart by their name:

| File | Is | Docs page, as text |
|---|---|---|
| `create-products.json` | the **response** (what the API returns) | `create-products.docs.md` |
| `create-products.body.json` | the **request body** (what you send) | `create-products.body.docs.md` |

```bash
bin/console api-forge:import paddle tests/Fixtures/paddle/create-products.curl --response=tests/Fixtures/paddle/create-products.json
```

`create-products.curl` holds the method and the URL (`curl -X POST https://api.paddle.com/products`), not the body: the importer
finds `create-products.body.json` (or `.body.array`) next to it and uses it as the body; a body given twice (`-d` and the
file) is refused. The body docs page tells the keys the API requires: without it every key of the body is typed
as required and a note asks you to mark the optional ones with `?`.

### What the importer does with each part

| In the example | In the descriptor |
|---|---|
| method and path | the operation: `list` (`GET prices`), `get` (`GET prices/{id}`), `create` (`POST`), `update`, `delete`, or the action (`POST prices/{id}/archive` → `archive`); the name can be changed with `--operation` and `--resource` |
| `{price_id}` or an id in the path | a path parameter, in camel case (`priceId`): a review note asks you to check the name |
| query parameters | `query:`, each optional (`type: string`, `required: false`, the example value): set `required: true` where the docs say so |
| a JSON body | `body.shape`, from the body's values; all its keys are required, mark the optional ones with `?` |
| the response | `response.shape`, see [Giving the response](#giving-the-response) |

### Several examples of the same operation

Import each example in turn. When the method and path are the same as an existing operation, the new example is
merged with it: its query parameters join those already known (`list-prices.curl`, then `list-prices-include.php`:
`include` becomes a query parameter of `prices.list`), and what the descriptor already holds is kept: a response
shape is only added when the operation has none. A different method or path makes another operation (`POST prices`
after `GET prices` is `create`). A different operation under the same name is refused unless you choose names
(`--operation`) or pass `--force`.

## Giving the response

A response is read from a JSON file (`--response=…/list-prices.json`), from a `response` key of an array example, or
from the example's own file when it is an array. The importer types the fields from the values it sees. Four things
make that complete.

### A JSON response

`--response=…/list-prices.json`, or the JSON next to the curl with the same base name, as the guide proposes. JSON
copied from a documentation page is accepted as it is: comments, `{...}` / `[...]` placeholders, trailing commas.
Strings in single quotes are not JSON: the review note says on which line.

### An array response

In an array example, `'response' => […]`. For a response alone (`--response=…/list-prices.php`, an object example
for `import-object`, a webhook payload for `--payload`), the file holds the JSON document itself as an array.
`api-forge:json-to-php` writes the array of an existing JSON example, to complete it by hand.

### Completing what the example does not show

The importer can only type what the example shows: a `null`, an empty list or a missing field stays `mixed` (the review
notes list the keys). Complete the **example**, not the descriptor.

| The example has | Do |
|---|---|
| `"custom_data": null` for a free-form object | nothing if the docs page is imported (it says `object \| null`); else write `{}` (an empty object, typed `array<string, mixed>`), not `[]` (an empty list) |
| `"unit_price_overrides": []` for a list of objects | write one entry, or point to a type file (below) |
| a field some items have and others do not | a list is merged: the keys missing from some items become optional |


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

### The documentation page as text

An example shows what is returned once, not what the API promises. The page of the docs says more: which fields
are required, which can be null, the values of an enum. Copy the page's field list as text next to the example,
as `<operation>-<resource>.docs.md` (select the response part of the page and paste it in a file):

```
trial_periodobject | nullrequired
Trial period for the product related to this price.
intervalstringrequired
Values
*   `day`
*   `week`
```

`api-forge:import` finds it by its name (or `--docs=<file>`) and applies it to the response shape, on the structure
of the example: the example, checked by a human, holds the nesting, since the copied page is flat (no
indentation, no parents).

| The docs say | The shape gets |
|---|---|
| not `required` | an optional key (`name?: string`); `required` makes a key required even if an example lacks it |
| `string \| null` | `?string`, `…\|null` |
| `Values` listing all the values | `'day'\|'week'\|'month'\|'year'` (the doctor warns about other values); not for lists cut by "Show all values" or longer than 12 values |
| an `object`, `integer`… where the example only has `null` or `[]` | `array<string, mixed>`, `int`…: no more `mixed` note |

The notes about the docs say what to do and where: a field the page describes and the example lacks (with where it sits in
the page, and its values: add it to the example), a field the example has and the page text does not describe (complete
the pasted text, or remove it), an object the page describes with fields and the example shows empty (give it an example
value), an object the docs call free-form and the example fills (replace its value with `{}`: one note, not one per key).

A name is aligned only when the page and the example hold it the same number of times (`unit_price` four times in
the page, three in the example: the example lacks one). Then nothing is applied to that name and the review
notes say which: complete the example (a type file for the missing object, see above) and import again. Objects of
the page that the example does not have (a field behind an `include` parameter, an early access field) are set aside
with their fields, and reported. Nothing is guessed: a doubt is a note, never a wrong type. Only responses of
plain JSON APIs, for now (not the objects of a JSON:API resource, nor request bodies).

Other formats of documentation can be added: implement `ExampleFormat` (extensions, an operation, a JSON
document); autoconfiguration registers it.

## Worked example: Paddle prices

The files of `tests/Fixtures/paddle/` in the order they are made, and the commands. The docs gave a cURL sample and a
JSON response for the list, a page describing the fields, and prose for `unit_price_overrides` and the query
parameters.

1. **The request and the response of the list**, copied from the docs: `list-prices.curl`, `list-prices.json`.
   ```bash
   bin/console api-forge:import paddle tests/Fixtures/paddle/list-prices.curl \
       --response=tests/Fixtures/paddle/list-prices.json
   ```
2. **The types the docs describe elsewhere**, as files, and their names as values in `list-prices.json`:
   `unit_price_overrides.array` (a list of overrides: `"unit_price_overrides": ["unit_price_overrides.array"]`, in
   brackets since it is a list), `trial_period.array` and `import_meta.array` (single objects: `"trial_period":
   "trial_period.array"`, no brackets), `product-type.json` (the product returned with `include=product`).
3. **The docs page as text**, next to the response: `list-prices.docs.md`. It is found by its name; it gives the
   optional keys, the nulls and the enums.
4. **The query parameter `include`**, which the docs describe in words: `list-prices-include.php`
   ```php
   <?php return ['request' => 'GET /prices', 'query' => ['include' => 'product']];
   ```
   ```bash
   bin/console api-forge:import paddle tests/Fixtures/paddle/list-prices-include.php
   ```
5. **Import again** to apply the completed example to the response. The operation exists, so what it holds is kept:
   replace it with `--force` (the response shape and the query are rebuilt from the examples; do the query examples
   of step 4 afterwards):
   ```bash
   bin/console api-forge:import paddle tests/Fixtures/paddle/list-prices.curl \
       --response=tests/Fixtures/paddle/list-prices.json --force
   bin/console api-forge:import paddle tests/Fixtures/paddle/list-prices-include.php
   ```
6. **Read the review notes** it prints, fix the examples they point to, import again. Delete the notes you accept
   (`allowances` is an early access field of the docs, not in the example, on purpose).
7. **Generate and check**: `bin/console api-forge:generate paddle`, then `bin/console api-forge:doctor paddle`.

`bin/console api-forge:guide paddle` shows at any time which examples are not imported yet, the notes left, and the
next command.

## Importing, and importing again

An import never overwrites what is in the descriptor: values already there win, so hand-made fixes survive. To
apply an improved example to an operation that exists, pass `--force`: it replaces that operation (not the others of
the resource). Re-importing rewrites the YAML file, so YAML comments are lost: keep notes in `review` entries.

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
