# Generated services

## What is generated

For an API `lemonsqueezy` with `namespace: LemonSqueezy`:

| File | Content |
|---|---|
| `src/Api/LemonSqueezy/Generated/Resource/<Resource>.php` | One service per resource, one method per operation |
| `src/Api/LemonSqueezy/Generated/LemonSqueezyApi.php` | Entry point: one public property per resource |
| `src/Api/LemonSqueezy/Generated/Webhook/*.php` | Webhook request parser, event constants and payload types, base consumer, see [Webhooks](webhooks.md) |
| `config/packages/api_lemonsqueezy.yaml` | Scoped HTTP client `lemonsqueezy.client` (base URI, auth, headers), webhook routing |

All are autowired. Inject the entry point, or a single resource:

```php
use App\Api\LemonSqueezy\Generated\LemonSqueezyApi;
use App\Api\LemonSqueezy\Generated\Resource\Customers;

public function __construct(private LemonSqueezyApi $lemonSqueezy) {}
// or
public function __construct(private Customers $customers) {}
```

The secret is read from the environment variable named in `_api.yaml` (`LEMONSQUEEZY_API_KEY`). It
defaults to an empty string, so the application boots without it; requests are then rejected by the
API (401).

## Methods

Method parameters, in order: path parameters, required query parameters, body, optional query
parameters, then `array $query` for undocumented query parameters (e.g. `page[size]`, `include`).

```php
$api = $this->lemonSqueezy;

$page = $api->customers->list(filterStoreId: 1, query: ['page[size]' => 50]);
$customer = $api->customers->get(1);
$created = $api->customers->create(['name' => 'John Doe', 'email' => 'john@example.com'], ['store' => 1]);
$api->customers->update(1, ['status' => 'archived']);
```

### Return values

Methods return the decoded response as an array, typed in PHPDoc. For JSON:API APIs:

```php
$customer['data']['attributes']['email'];  // get, create, update: data is one resource object
$page['data'][0]['id'];                    // list: data is a list of resource objects
$page['links']['next'];                    // links, meta, included are kept
```

Each resource class declares the shape of its object (`@phpstan-type Customer array{...}`), so
PhpStorm and PHPStan know the attributes.

### Pagination

For JSON:API `list` operations, `iterate()` yields every resource object of every page, following the
`links.next` URLs:

```php
foreach ($api->customers->iterate(filterStoreId: 1) as $customer) {
    // ...
}
```

### JSON:API request bodies

Create and update methods take the attributes and the related resource ids; the document is built for
you, with the resource `type`, and the `id` for updates:

```php
$api->checkouts->create(['custom_price' => 50000], ['store' => 1, 'variant' => 1]);
// {"data": {"type": "checkouts", "attributes": {...}, "relationships": {
//     "store": {"data": {"type": "stores", "id": "1"}}, "variant": {"data": {"type": "variants", "id": "1"}}}}}
```

## Errors

| Situation | Exception |
|---|---|
| The API answers with a 4xx or 5xx status | `Snorky22\ApiForge\Core\ApiException` |
| The response is not valid JSON | `Snorky22\ApiForge\Core\ApiException` |
| A network error | `Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface` |

`ApiException` has the HTTP status (`statusCode`), the decoded error response (`content`), and a
message including the API's error details (JSON:API `errors[].detail`, or `message` / `error`):

```php
try {
    $api->customers->create(['name' => 'John Doe']);
} catch (ApiException $e) {
    $e->statusCode;           // 422
    $e->content['errors'];    // as returned by the API
}
```

## Runtime layer (`Snorky22\ApiForge\Core`)

Classes of the package shared by all generated classes:

- `AbstractResource::request()` fills the path placeholders, drops `null` query values, sends the
  request through the scoped client, decodes the response and converts errors to `ApiException`.
- `AbstractJsonApiResource` adds `document()` (JSON:API request bodies) and `paginate()`.

## Customizing

Never edit generated files: they are overwritten. Instead:

- fix the descriptors and regenerate, for anything about the API itself (types, parameters, names);
- write your own classes outside `Generated/` for behavior: generated resource classes are not
  `final`, so they can be extended or decorated.

## Testing code that uses the services

Build the resources with a mock client:

```php
use Snorky22\ApiForge\Dev\LenientJsonDecoder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

// The lenient decoder accepts documentation JSON ({...} placeholders, trailing commas)
$response = new JsonMockResponse((new LenientJsonDecoder())->decode(file_get_contents('path/to/orders-example.json')));
$orders = new Orders(new MockHttpClient($response, 'https://api.lemonsqueezy.com/v1/'));

$orders->list();
$response->getRequestUrl();  // https://api.lemonsqueezy.com/v1/orders
```
