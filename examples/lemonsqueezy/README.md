# Example: Lemon Squeezy

The files an application gets for the [Lemon Squeezy API](https://docs.lemonsqueezy.com/api), laid out as
in a Symfony project:

| Path | Content |
|---|---|
| `config/api/lemonsqueezy/` | Descriptors, imported from the documentation examples of `tests/Fixtures/`, then reviewed |
| `config/packages/api_lemonsqueezy.yaml` | Generated: scoped HTTP client, webhook routing |
| `src/Api/LemonSqueezy/Generated/` | Generated: customers, orders, checkouts, subscriptions, subscription invoices and license keys resources, entry point, webhook classes |
| `src/Webhook/LemonSqueezyWebhookConsumer.php` | Hand-written webhook consumer |

The descriptors were created with:

```bash
F=tests/Fixtures
bin/console api-forge:import-object lemonsqueezy customers $F/customer-object.json
bin/console api-forge:import lemonsqueezy $F/curl-list-customers-1   # and the other curl-* files
bin/console api-forge:import lemonsqueezy $F/curl-example-1 --response=$F/json-example.json
bin/console api-forge:import-webhook lemonsqueezy --events="$F/Docs Event Types • Lemon Squeezy.md" \
    --payload=$F/order-created-payload.json
bin/console api-forge:import-object lemonsqueezy orders $F/json-example.json $F/order-object.json \
    $F/order-created-payload.json --force
bin/console api-forge:import-object lemonsqueezy subscriptions $F/subscription-object.json
bin/console api-forge:import-object lemonsqueezy subscription-invoices $F/subscription-invoice-object.json
bin/console api-forge:import-object lemonsqueezy license-keys $F/license-key-object.json
```

then `namespace: LemonSqueezy` was set in `_api.yaml`. During the review, the values that are `null` in the
examples (`mixed`) and the documented enums (statuses, `billing_reason`, `card_brand`…) were typed from the
attribute docs, and the webhook `meta` shape was completed with `test_mode`, `webhook_id` and `custom_data`. The tests of the bundle run this example
(`tests/Example/`), and check that the generated code is up to date.
