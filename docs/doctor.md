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
| Webhook chain | `_webhooks.yaml` | Routes, parser, secret, consumer, asynchronous transport; with `--ping`, a public URL (see [Webhooks](webhooks.md#checking-the-chain)) |

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

## Notes

- A development tool: the generated doctor depends only on the generated resources, but the command comes
  with the bundle, which may be enabled in the dev environment only.
- The doctors are registered by autoconfiguration (tag `api_forge.doctor`): the generated directory must
  be loaded as services, as `src/` is by default.
- A doctor that can't be built (an environment variable of a probe parameter without value) is reported as
  such.
