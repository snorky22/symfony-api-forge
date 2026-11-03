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

## Notes

- A development tool: the generated doctor depends only on the generated resources, but the command comes
  with the bundle, which may be enabled in the dev environment only.
- The doctors are registered by autoconfiguration (tag `api_forge.doctor`): the generated directory must
  be loaded as services, as `src/` is by default.
- A doctor that can't be built (an environment variable of a probe parameter without value) is reported as
  such.
