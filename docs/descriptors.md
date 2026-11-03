# Descriptor format

Descriptors live in `config/api/<api>/`: `_api.yaml` for the API-wide settings, `_webhooks.yaml` for
webhooks (optional), and one `<resource>.yaml` per resource. File names starting with `_` are not
resources. The schema is defined in
`src/Dev/Api/Descriptor/DescriptorSchema.php`, and checked by `api-forge:generate`.

Keys are never normalized: header, parameter and relationship names are kept as written.

## `_api.yaml`

```yaml
base_uri: 'https://api.lemonsqueezy.com/v1/'   # required, a trailing "/" is added if missing
format: jsonapi                                # json (default) or jsonapi
namespace: LemonSqueezy                        # PHP namespace segment; default: API name in PascalCase
headers:                                       # default headers of every request
    Accept: application/vnd.api+json
    Content-Type: application/vnd.api+json
auth:
    type: bearer                               # bearer, basic or header
    env: LEMONSQUEEZY_API_KEY                  # environment variable holding the secret
    # header: X-Api-Key                        # required for the "header" type
review: []                                     # notes left by the importer
```

| Auth type | Secret sent as | Value of the variable |
|---|---|---|
| `bearer` | `Authorization: Bearer <value>` | the token |
| `basic` | `Authorization: Basic ...` | `user:password` |
| `header` | `<header>: <value>` | the key |

### Doctor

```yaml
doctor:
    probe: customers.list              # resource.operation: a harmless GET, called by api-forge:doctor
    parameters:                        # values of the probe's path parameters
        id: '%env(SHOP_CUSTOMER_ID)%'  # %env()% and %parameters% are injected from the container
    review: []
```

Guessed by the importer (the first `GET` without parameters); see [Doctor](doctor.md).

## `<resource>.yaml`

```yaml
resource: customers            # required, must match the file name
type: customers                # JSON:API type, required by "body.attributes"
object:                        # shape of one resource object (JSON:API)
    shape: |-
        array{
            type: string,
            id: string,
            attributes: array{
                name: string,
                city: ?string,
            },
        }
    review: []
review: []
operations:
    <name>: ...                # the generated method name
```

### Operations

```yaml
operations:
    list:
        method: GET                    # GET, POST, PUT, PATCH or DELETE
        path: customers                # relative to base_uri, with {placeholders}
        parameters:                    # one entry per {placeholder} of the path
            # id: { example: '1', description: ~ }
        query:
            'filter[store_id]':
                type: int              # string (default), int, float or bool
                required: false        # default false
                example: '1'
                description: ~
        headers: {}                    # added to the API headers for this operation
        body: ...                      # see below
        response: ...                  # see below
        review: []
```

Operation names must be valid PHP method names, other than `request`, `paginate`, `document` and
`iterate`. The importer names CRUD operations `list`, `get`, `create`, `update` and `delete`, and
other operations after their path: `orders/{id}/order-items` → `orderItems`,
`subscription-invoices/{id}/generate-invoice` → `generateInvoice`.

### Body

Exactly one of:

```yaml
body:
    shape: 'array{name: string}'       # JSON body, passed as "array $body"

body:
    raw: 'example text'                # text body, passed as "string $body"

body:                                  # JSON:API document, built from the method arguments
    attributes:
        shape: 'array{name?: string, email?: string}'
    relationships:                     # relationship name => related type
        store: stores                  # to-one
        tags: 'tags[]'                 # to-many
    id: true                           # the document carries the resource id (the last path parameter):
                                       # default for PATCH and PUT; set by the importer for actions like
                                       # "POST orders/{id}/refund" whose example document has an id
```

### Response

At most one of:

```yaml
response:
    shape: 'array{url: string}'        # the decoded response, as is

response:
    data: object                       # JSON:API document of one resource object ("object" shape)
    # data: list                       # JSON:API document of a list of resource objects
```

Without `response`, the method returns `array<mixed>` (`[]` for an empty response).

## `_webhooks.yaml`

```yaml
signature:                                 # HMAC of the raw request body
    header: X-Signature                    # required
    algorithm: sha256                      # any algorithm of hash_hmac_algos(), default sha256
    encoding: hex                          # hex (default) or base64
    prefix: ''                             # prefix of the header value, e.g. "sha256="
    env: LEMONSQUEEZY_WEBHOOK_SECRET       # required, environment variable holding the secret
event_name:                                # required, exactly one of:
    path: meta.event_name                  #   path in the payload, dot notation
    # header: X-Event-Name                 #   request header
event_id:                                  # optional, a unique delivery id; default: hash of the body
    # path: id
    # header: X-Delivery-Id
meta:                                      # JSON:API: shape of the payload "meta" member
    shape: 'array{event_name: string}'
events:
    order_created:                         # event name, as sent by the API
        resource: orders                   # JSON:API: data is an object of this resource
        # shape: 'array{...}'              # or: shape of the whole payload (plain JSON APIs)
        description: 'Occurs when a new order is successfully placed.'
        review: []
review: []
```

Each event gives a constant (`ORDER_CREATED`), a payload type (`OrderCreatedPayload`) and a consumer
method (`onOrderCreated`); these names must be unique. An event whose resource has no object shape
has untyped payload data, which `api-forge:generate` reports.

### Registration

```yaml
registration:                          # how the doctor reads the webhooks registered at the provider
    operation: webhooks.list           # resource.operation: a GET listing them
    parameters: {}                     # values of its path parameters
    query:                             # values of query parameters
        'filter[store_id]': '%env(SHOP_STORE_ID)%'
    url: attributes.url                # dot path of the URL in each item (required)
    events: attributes.events          # dot path of the subscribed events, if any
    items: null                        # key of the list: "data" for JSON:API by default
    id: id
```

See [Doctor](doctor.md#webhooks).

## Shapes

Shapes are PHPDoc types as written for PHPStan: `array{key: type, optional?: type}`, `list<type>`,
`?string`, `int|string`, `array<string, mixed>`, `mixed`. Keys that are not identifiers are quoted:
`'order-items': ...`. Shapes are validated with `phpstan/phpdoc-parser`.

## Import and merge rules

- Existing values always win: an import only fills in what is missing.
- Another example of the same operation (same method and path) adds its query parameters and headers.
- An import whose operation name exists with another method or path is refused: choose another
  name with `--operation`, or replace the operation with `--force`.
- Headers that differ from `_api.yaml` are stored on the operation.
- A response example that is a JSON:API document of the resource becomes `response.data`, and its
  objects give the resource `object` shape (if not defined yet).
