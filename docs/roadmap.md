# Roadmap

## Done

- `app:json-to-php`: JSON example → PHP array and PHPDoc shape
- Curl parser and `app:api:import`, `app:api:import-object`: documentation → descriptors
- Strict descriptor validation, runtime layer, `app:api:generate`
- Webhooks: `app:api:import-webhook`, signed request parser, typed event consumers, asynchronous
  processing with Messenger
- Lemon Squeezy: customers, orders, checkouts, and the 17 webhook events (from the examples in
  `tests/Fixtures/`)

## Next

- Lemon Squeezy: import the subscription, subscription invoice, license key and affiliate objects, to
  type the payloads of their webhook events.
- Check the webhook signing scheme against the Lemon Squeezy docs (currently the importer's default).

## Known limitations

- Pagination (`iterate()`) is only generated for JSON:API `list` operations.
- To-one relationships are typed `int|string|null`, even for `create` where `null` makes no sense.
- Query parameter examples appear as strings in the PHPDoc, e.g. `"1"` for an `int` parameter.
- Re-importing rewrites the descriptor file: YAML comments are lost.
- When an import merges into an existing operation, its review notes are added again, even those
  deleted before.
- JSON:API response envelopes are typed generically (`meta?: array<string, mixed>`), not with the
  API's own `meta` (e.g. Lemon Squeezy's `meta.page`).
- The webhook signing scheme cannot be imported: it is written as a default, to check by hand.
- The webhook `meta` shape comes from the examples: optional keys absent from them are missing.
- The events table import only recognizes event names containing a separator (`order_created`,
  `order.created`), to tell them from header rows.
- Webhook payloads are checked against the object shape by plain comparison: any difference is
  reported, even when the object shape already covers the payload.
