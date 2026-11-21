# Hand-written REST resources

The other way to call a REST API: no descriptors, no generated code. You write one class per resource from the
API's docs, with only what you use: the actions you call, the fields you send, the keys you read. The optional
features you don't need are simply left out.

The classes are in `Snorky22\ApiForge\Core\Rest`, the runtime part of the package.

## The interfaces

One interface per action. A resource implements the ones the API offers and you use, nothing more.

| Interface   | Method                        | HTTP                 | Returns                       |
|-------------|-------------------------------|----------------------|-------------------------------|
| `Listable`  | `list(array $query = [])`     | `GET {path}`         | one page, as the API sends it |
| `Readable`  | `get($id, array $query = [])` | `GET {path}/{id}`    | the response, as is           |
| `Creatable` | `create(array $body)`         | `POST {path}`        | the response, as is           |
| `Updatable` | `update($id, array $body)`    | `PATCH {path}/{id}`  | the response, as is           |
| `Deletable` | `delete($id)`                 | `DELETE {path}/{id}` | nothing                       |

`Crud` extends the five, for a resource that has them all.

- The body is a PHP array sent as JSON; the response is the decoded JSON, a PHP array, as the docs show it
  (`data` is not unwrapped).
- The id is `int|string` (`pri_01h…`, `johndoe`, `42`), URL-encoded in the path.
- `null` values of `$query` are left out, so optional filters can be passed as variables.
- An empty response (204) gives `[]`.
- An error status, or invalid JSON, throws `Snorky22\ApiForge\Core\ApiException` (status code, decoded error
  body, and the API's message when it has a usual form).

## Starting from the command

`api-forge:make:resource` asks for what the class needs and writes it, like `make:entity`:

```
$ bin/console api-forge:make:resource

 Class name, relative to App\API (e.g. Paddle\Prices):
 > Paddle\Prices

 Endpoint of the resource, from the docs, e.g. api.paddle.com/prices (empty: none):
 > api.paddle.com/prices

 Path of the resource, relative to the base URI of the API [prices]:
 >

 Service id of the scoped HTTP client [paddle.client]:
 >

 Actions (comma-separated) [GET {path}, GET {path}/{id}]:
  [list  ] GET {path}
  [get   ] GET {path}/{id}
  [create] POST {path}
  [update] PATCH {path}/{id}
  [delete] DELETE {path}/{id}
 > list,get,update

 Key wrapping the responses, e.g. "data" (empty: none):
 > data

 URL of the API docs for this resource (empty: none):
 > https://developer.paddle.com/api-reference/prices/overview

 [OK] Written: src/API/Paddle/Prices.php
```

The endpoint is the URL the docs give, with or without `https://`; a trailing `{id}` (the URL of one item) and the
query string are ignored. It is split into the path of the class (its last segment by default: answer `v1/prices` to
take more) and the base URI of the API. The class records the endpoint as the first line of its docblock
(`Endpoint: https://api.paddle.com/prices`).

The command also writes the configuration of the scoped client, `config/packages/paddle_client.yaml` (named after the
client service; `api_forge.rest.config_dir`), unless a YAML file of that directory declares the client already. It is
yours to edit: the authentication depends on the API (`auth_bearer`, `auth_basic` or headers). The base URI and the API
key come from environment variables, the base URI defaulting to the endpoint's: another one, such as a sandbox, is a
line of `.env.local`:

```
PADDLE_BASE_URI=https://sandbox-api.paddle.com/
PADDLE_API_KEY=...
```

The key is empty by default, so that the application boots without it. Without an endpoint, the base URI is a placeholder marked TODO, filled in when the endpoint is given
later.

The class has the interfaces and traits of the chosen actions, the constructor on the client, the path, and TODOs
for what only the docs can tell: the shapes start as `array<string, mixed>`, to replace with the keys you use, and
a commented example shows where to add the API's other actions. Each answer can be given as an option instead
(`--endpoint`, `--path`, `--client`, `--actions`, `--envelope`, `--docs`); with `--no-interaction`, the defaults are taken.

In a terminal, the class name is autocompleted with the namespaces and classes already in `src/API`: type `Pa`, and
`Paddle\` is proposed (grey text: right arrow or Tab to accept, up and down for the other matches), then `Paddle\Prices`.

Run it again on an existing class to complete it. If the class records no endpoint, it is asked for (and the client
configuration shown). Then the actions the class does not have yet are listed, and those you choose are added (use
statements, interfaces, traits, `@implements` lines and the new TODO shapes); an empty answer adds none. The rest of the
file is kept as it is: your shapes, methods and comments. The envelope key is read from the class's `@implements` lines.

```
$ bin/console api-forge:make:resource Paddle\\Prices

 Prices.php exists, with: list; endpoint: https://api.paddle.com/prices.

 Actions to add (comma-separated, empty: none):
  [get   ] GET {path}/{id}
  [create] POST {path}
  [update] PATCH {path}/{id}
  [delete] DELETE {path}/{id}
 > get

 [OK] Added get to src/API/Paddle/Prices.php
```

Without interaction, give them with `--actions=get,create`, and the endpoint with `--endpoint`.

The classes live in `src/API`, namespace `App\API` (the `App\` autoloading of a Symfony project covers it), and the
client configurations in `config/packages`. All can be changed:

```yaml
# config/packages/api_forge.yaml
api_forge:
    rest:
        namespace: 'App\API'
        source_dir: 'src/API'
        config_dir: 'config/packages'
```

The generated code of the other path stays in `src/Api` (`api_forge.namespace`, `api_forge.source_dir`). On macOS
and Windows, `src/API` and `src/Api` are the same directory: if `src/Api` already exists, the class is written in it
and the command warns, since a case-sensitive server (Linux) would not find `App\API\…` there. Rename the directory,
or set `rest.source_dir` (and `rest.namespace`) to match it.

## Writing a resource

Extend `AbstractRestResource`, give the path, and use the trait of each action: `Lists`, `Reads`, `Creates`,
`Updates`, `Deletes`. Anything else the API offers is a method of your own, on top of `request()`.

```php
namespace App\API\Paddle;

use Snorky22\ApiForge\Core\Rest\AbstractRestResource;
use Snorky22\ApiForge\Core\Rest\Listable;
use Snorky22\ApiForge\Core\Rest\Lists;
use Snorky22\ApiForge\Core\Rest\Readable;
use Snorky22\ApiForge\Core\Rest\Reads;
use Snorky22\ApiForge\Core\Rest\Updatable;
use Snorky22\ApiForge\Core\Rest\Updates;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @see https://developer.paddle.com/api-reference/prices/overview
 *
 * @phpstan-type Price array{id: string, status: string, unit_price: array{amount: string, currency_code: string}, ...}
 *
 * @implements Listable<array{data: list<Price>, ...}>
 * @implements Readable<array{data: Price, ...}>
 * @implements Updatable<array{description?: string, status?: string}, array{data: Price, ...}>
 */
final class Prices extends AbstractRestResource implements Listable, Readable, Updatable
{
    use Lists;
    use Reads;
    use Updates;

    public function __construct(#[Autowire(service: 'paddle.client')] HttpClientInterface $client)
    {
        parent::__construct($client);
    }

    /**
     * Paddle archives instead of deleting.
     *
     * @return array{data: Price, ...}
     */
    public function archive(string $id): array
    {
        return $this->update($id, ['status' => 'archived']);
    }

    protected function path(): string
    {
        return 'prices';
    }
}
```

```php
$page = $prices->list(['product_id' => 'pro_01h…', 'per_page' => 50]);
$price = $prices->get('pri_01h…', ['include' => 'product']);
$prices->archive('pri_01h…');
```

- The array shapes (`@phpstan-type`, `@implements …<…>`) are optional: without them the methods return `array`.
  List only the keys you read; the trailing `...` says the API sends more.
  The shape is a declaration about the API, not checked at runtime: from level 7, PHPStan reports the methods that
  return a decoded response as such (`should return array{…} but returns array<mixed>`); up to level 6 it trusts
  the declaration.
- The `@see` link keeps the way back to the docs, for what you chose to leave out.
- When the item path is not `{path}/{id}`, override `itemPath()`. When a whole action differs (PUT instead of
  PATCH, a nested path), write the method yourself instead of using the trait.

A resource without any of the five actions (a search, an extraction) extends `AbstractResource` directly:

```php
final class Search extends AbstractResource
{
    public function search(string $q, ?int $limit = null): array
    {
        return $this->request('GET', 'search', query: ['q' => $q, 'limit' => $limit]);
    }
}
```

`request($method, $path, $parameters, $query, $json, $body, $headers)` replaces the `{name}` placeholders of the
path with `$parameters`, and handles the errors as above.

## Trying a method: `api-forge:call`

`api-forge:call` calls a method of a resource and prints the response as JSON: a real request, with the scoped client
of the resource, so its base URI and key (e.g. a sandbox's, from `.env.local`).

```bash
bin/console api-forge:call Paddle\\Prices list '{"per_page": 5}'
bin/console api-forge:call Paddle\\Prices get pri_01h…
bin/console api-forge:call            # asks: the resource (autocompleted), the method, each argument
```

- The class is relative to `App\API`, or fully qualified (the generated resources are listed too).
- The arguments are the method's, in order: JSON when they read as such (`{…}`, `[…]`, numbers, `true`, `false`,
  `null`), else text. Those not given are asked; an empty answer leaves an optional one to its default.
- An error of the API is printed with its status and its body. An iterator (`iterate()`) prints its first 20 items
  (`--max`).
- A resource whose client is not declared yet is left out, with the missing service said: the container still
  compiles (`api-forge:make:resource` on the class writes the client).


Base URI, authentication, default headers, timeouts and retries belong to a scoped client in the Symfony
configuration, not to the classes. `api-forge:make:resource` writes it (see above); by hand:

```yaml
# config/packages/paddle_client.yaml
parameters:
    env(PADDLE_BASE_URI): 'https://api.paddle.com/'
    env(PADDLE_API_KEY): ''
framework:
    http_client:
        scoped_clients:
            paddle.client:
                base_uri: '%env(PADDLE_BASE_URI)%'
                auth_bearer: '%env(PADDLE_API_KEY)%'
```

## Not covered yet

- Following the pages of a list: `list()` returns one page.
