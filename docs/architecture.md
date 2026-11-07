# Architecture

## Two phases: lenient import, strict generation

API documentation is incomplete and sometimes inconsistent, so the pipeline is split in two:

1. **Import (lenient)**: curl commands and JSON examples become *descriptors* (YAML). The importer
   guesses what it must (path parameters, operation names, types) and lists every guess under a
   `review` key, to be checked by hand.
2. **Generation (strict)**: descriptors are validated, then turned into PHP. Nothing is guessed: an
   invalid descriptor stops the generation with an error pointing to the file and operation.

The descriptors are the source of truth, and the place for manual fixes. Generated code is never
edited: it is regenerated from the descriptors, so fixes survive regeneration.

### Why regenerate rather than scaffold once

Examples from the docs contain quirks that no generator can resolve alone. For instance, Lemon Squeezy
returns empty objects as `[]`, and `preview` is a boolean in a request but an object in the response.
Such fixes are made once in a descriptor, and every regeneration keeps them. Re-importing keeps them
too: existing descriptor values always win over imported ones.

### Why group by resource type

One class is generated per resource (`orders`, `customers`), not per URL path. Nested routes such as
`orders/{id}/order-items` become methods of the parent resource (`Orders::orderItems($id)`), instead of
a second class for the same resource. For JSON:API APIs, the resource type is read from the bodies
(`"type": "customers"`).

Webhooks follow the same principle: the events and payload examples of the docs are imported into
`_webhooks.yaml`, from which the request parser, the event constants, the payload types and a base
consumer are generated. The application only writes the consumer methods of the events it handles.

## The package

```
src/                                     namespace Snorky22\ApiForge
├── ApiForgeBundle.php                   registers the commands, "api_forge" configuration
├── Core/                                Runtime, used by the generated code
│   ├── AbstractResource.php                 request(), error handling
│   ├── AbstractJsonApiResource.php          JSON:API documents, pagination
│   ├── ApiException.php
│   └── Webhook/
│       ├── AbstractSignedRequestParser.php  HMAC signature, event name and id
│       └── AbstractWebhookConsumer.php      calls the method of each event
├── Command/                             Console commands (see commands.md)
├── DependencyInjection/                 WebhookSenderPass: webhook routing → WebhookSender
├── Test/WebhookTestTrait.php            signed webhook deliveries in functional tests
└── Dev/                                 Development tools, not used at runtime
    ├── LenientJsonDecoder.php               JSON from docs: comments, {...}, trailing commas
    ├── ArrayShapeGenerator.php              JSON example → PHPDoc array shape
    ├── PhpArrayDumper.php                   data → readable, checked PHP array literal (yaml-to-php)
    ├── PhpLiteral.php                       reads PHP data written by hand without running code
    ├── Doctor/                              AbstractApiDoctor (base of the generated doctors), Check,
    │                                        ShapeChecker (values against array shapes)
    ├── Webhook/                             DeliveryInbox and DeliveryRecorder (deliveries received),
    │                                        WebhookSender (simulated deliveries, signed like the API),
    │                                        WebhookDiagnostics (chain check, ping)
    └── Api/
        ├── Example/                         ExampleReader and ExampleFormat (PhpArrayExample): documentation
        │                                    examples other than curl and JSON → curl command + JSON response
        ├── Curl/                            curl command parser
        ├── Import/                          importers (endpoints, objects, webhooks) and merge rules
        ├── Descriptor/                      YAML storage, schema, strict loader, shape validator
        ├── Generator/                       descriptor → PHP and YAML
        └── Naming.php                       descriptor names → PHP names
examples/lemonsqueezy/                   An application's files for Lemon Squeezy (see below)
tests/                                   Tests, test application (tests/App/TestKernel.php, tests/console)
```

The generated code depends on `Core` only, not on the bundle: the bundle is a development tool.

## In an application

With the default configuration (see the README), an application has:

```
config/
├── api/<api>/                   Descriptors (source of truth)
├── packages/api_<api>.yaml      Generated: scoped HTTP client, webhook routing
├── packages/messenger.yaml      Webhook events routed to the "async" transport (application's choice)
└── routes/webhook.yaml          /webhook/{type} route (recipe of symfony/webhook)
src/
├── Api/<Namespace>/Generated/   Generated, never edit
│   ├── <Namespace>Api.php           entry point, one property per resource
│   ├── Resource/*.php               one service per resource
│   └── Webhook/                     request parser, event constants and payload types, base consumer
└── Webhook/                     Webhook consumers, hand-written
```

`examples/lemonsqueezy/` contains these files for Lemon Squeezy.

## Data types

Responses are returned as PHP arrays (decoded JSON), typed with PHPDoc array shapes understood by
PHPStan and PhpStorm. Each resource class declares the shape of its object once
(`@phpstan-type Customer array{...}`), and its methods refer to it. DTOs could be generated later
from the same shapes.

## Dependencies

| Package | Use |
|---|---|
| `symfony/http-client` | HTTP requests, scoped clients (base URI, auth, headers) |
| `symfony/config` | Descriptor schema and validation |
| `symfony/yaml` | Descriptor files |
| `symfony/var-exporter` | PHP array literals (`api-forge:json-to-php`, `api-forge:yaml-to-php`) |
| `symfony/filesystem`, `symfony/finder` | Writing generated files, finding stale ones |
| `phpstan/phpdoc-parser` | Validating the PHPDoc shapes of descriptors |
| `symfony/webhook`, `symfony/remote-event` | Webhook endpoint, request parsers, consumers |
| `symfony/messenger` | Asynchronous processing of webhook events (required by Webhook) |
