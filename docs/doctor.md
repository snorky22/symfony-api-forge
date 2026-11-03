# Doctor

Each API gets a doctor, generated with its services (`Generated/<Namespace>Doctor.php`) and regenerated
with them: it follows the descriptors, nothing to write or maintain.

```bash
bin/console api-forge:doctor                    # every API
bin/console api-forge:doctor lemonsqueezy --ping=https://abcd.ngrok-free.app/webhook/lemonsqueezy
```

```
lemonsqueezy
------------

 API
 ✔ LEMONSQUEEZY_API_KEY (credentials) is set.
 ✔ LEMONSQUEEZY_WEBHOOK_SECRET (webhook signing secret) is set.
 ✘ Probe customers.list: the API rejects the credentials (HTTP 401): check LEMONSQUEEZY_API_KEY.

 Webhooks
 ✔ Endpoint: http://localhost/webhook/lemonsqueezy (path /webhook/lemonsqueezy)
 ✔ Request parser routed, signing secret set.
 ✔ Consumer registered (#[AsRemoteEventConsumer('lemonsqueezy')]).
 ✔ Events consumed during the request (no asynchronous transport).
```

## What it checks

| Check | From | Diagnoses |
|---|---|---|
| Environment variables | `auth.env`, `signature.env` | Empty credentials or webhook secret, as Symfony resolves them (`.env` files, defaults) |
| Probe | `doctor.probe` | The API answers and accepts the credentials, with the latency; or: unreachable host (DNS, TLS, timeout), rejected credentials (401), missing permissions (403), wrong base URI or version (404), rate limit (429), provider error (5xx) |
| Contract | the probe's response shape | The response differs from the descriptor: a required field missing (error: code reading it fails), a new field, another type, a value outside a documented enum (warnings) — the API changed since the descriptors were written |
| Webhook chain | `_webhooks.yaml` | Routes, parser, secret, consumer and the events it handles, the last deliveries from the provider (inbox: when, which event, rejected or failed and why), asynchronous transport; with `--ping`, a public URL (see [Webhooks](webhooks.md#checking-the-chain)) |
| Registered webhooks | `registration` in `_webhooks.yaml` | The webhooks registered at the provider: one must send to the endpoint path, with the events the consumer handles; each is pinged (`--no-ping` to skip) |

## The probe

The probe is a harmless `GET` operation of the API, declared in `_api.yaml`:

```yaml
doctor:
    probe: customers.list                         # resource.operation, a GET
    parameters:                                   # values of its path parameters, if any
        id: '%env(SHOP_CUSTOMER_ID)%'             # %env()% and %parameters% are injected
```

`api-forge:import` guesses it: the first `GET` without path parameter nor required query parameter becomes
the probe, with a review note to check that it is harmless and cheap. Without probe, the doctor only checks
the environment variables. `api-forge:generate` refuses a probe that is not a `GET`, not an operation of
the API, or misses path parameters.

## Contract check

After a successful probe, the doctor compares the response with its shape in the descriptor: the resource
object for a JSON:API operation (each item of a list, up to 5), the response shape otherwise. Differences
are listed with their path:

```
 ! The response of stores.get differs from the descriptor: the API may have changed. Update the shape
   (api-forge:import-object with a real response), then api-forge:generate.
 !   data.relationships.affiliates: new field (an object), not in the descriptor
 ✘   data.attributes.email: missing (required by the descriptor)
```

Fix the descriptor (mark a field optional with `?`, add the new ones, widen a type), regenerate: the doctor
and the PHPDoc types of the generated code follow. Types the checker doesn't know (aliases, classes) are not
checked; `array{…, ...}` allows extra keys.

## Webhooks

The doctor knows which events the application's consumer handles: the methods of the generated base
consumer it overrides (`The consumer handles 12 of the 17 events: …`).

With a `registration` hint in `_webhooks.yaml`, it also reads the webhooks registered at the provider,
through a list operation of the API:

```yaml
registration:
    operation: webhooks.list                      # a GET operation of the API
    query:
        'filter[store_id]': '%env(SHOP_STORE_ID)%'   # %env()% and %parameters% are injected
    url: attributes.url                           # dot paths in each item
    events: attributes.events                     # optional: the subscribed events
    # items: data                                 # the list in the response: "data" for JSON:API
    # id: id
```

and checks them against the application:

```
 Registered webhooks
 ! Webhook 1 (https://72e1.ngrok-free.app) does not send to the endpoint path /webhook/lemonsqueezy: its deliveries never reach the application.
 ✔ Webhook 2 (https://abcd.ngrok-free.app/webhook/lemonsqueezy) sends to the endpoint.
 ! Webhook 2 (…) does not send order_refunded: the consumer handles them, they never arrive.
 ✔ https://abcd.ngrok-free.app/webhook/lemonsqueezy reaches the endpoint, signed with the same secret (HTTP 202).
```

`ApiDiagnosis` runs the whole diagnosis of an API (sections API, Webhooks, Registered webhooks); bundles
built on api-forge can reuse it, with events whose absence is an error (`requiredEvents`).

## Notes

- A development tool: the generated doctor depends only on the generated resources, but the command comes
  with the bundle, which may be enabled in the dev environment only.
- The doctors are registered by autoconfiguration (tag `api_forge.doctor`): the generated directory must
  be loaded as services, as `src/` is by default.
- A doctor that can't be built (an environment variable of a probe parameter without value) is reported as
  such.
