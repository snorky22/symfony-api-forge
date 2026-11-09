# Changelog

All notable changes are documented in this file. The project follows [Semantic Versioning](https://semver.org);
until 1.0, minor versions may contain breaking changes.

## Unreleased

- Web editor: two chevron buttons make the editor shorter or taller by five lines (120 px to 95% of the window)

- Web interface, editing a done step: every button of the first four steps stays enabled (**Edit** the description of the
  API, which keeps what the form does not hold; **New endpoint** and an **Edit** link per endpoint; **Review**; **Generate
  again**). A step is done only if the previous ones are, so editing one makes the next ones to do again, and the doctor
  is to do again when a descriptor or the generated code is newer than its last run. An endpoint that exists can be
  edited or replaced from **New endpoint** instead of being refused

- Review notes that say what to do. The docs annotation groups its notes by cause: an object the docs call free-form and
  the example fills is one note (not one per key), the fields the docs describe inside an object the example shows
  empty are one note, a field the docs have and the example lacks says where it sits in the docs ("between "a" and
  "b"") and its values, a field the example has and the docs lack says to complete the pasted text or remove it. In the
  web interface each note is a sentence naming the file to change, with the files of the part concerned (body or
  response, with the docs page at the line of the note) and their role, and a **Make it free-form** fix
  (`JsonText`, an edit of the example that keeps its layout)

- Web interface: the doctor step of the dashboard is done (green) when the last run found no problem and no descriptor changed
  since; it says when it ran, or that it found problems; the last run is kept in `var/api-forge/web/doctor-<api>.json`

- Web interface, **New endpoint**: step 2 is a wizard of four screens (method and path; request: query parameters, body and its
  docs page; response and its docs page; import), each one writing its files, which are the only state: the dashboard lists
  the endpoints (method, path, "no response yet", "not imported") and continues one where it was left. The operation and the
  resource come from the method and the path; a variant names a second example of the same endpoint. "Add an example" stays
  as an advanced option

- Request bodies: `<operation>-<resource>.body.json` (or `.body.array`) next to the `.curl` is the body of the request, as
  `<operation>-<resource>.json` is its response; `<operation>-<resource>.body.docs.md` is the docs page of the body, whose
  `required` marks make the other keys optional (without it a note asks to mark them); a body given twice (`-d` and the
  file) is refused. The web form has a box for each. Documented in `docs/workflow.md` (the files, and which JSON is which)

- Web interface, step 2 starts from the examples already in `tests/Fixtures/<api>/`: the ones not imported are flagged and
  imported together (`POST /api/<api>/import-all`), and an invalid descriptor shows an "Import <example> again" button that
  makes it again from its example; the blank form is only for a new example

- Web interface, review notes: a **Fix** form for each key a `mixed` / `list<mixed>` note lists (what is it: one of the type
  files, text, a number, yes/no, a free-form object, a list); the page writes the value in the example, imports it again
  with its variants and shows the notes again. The examples come first in a note, the descriptor is under "Advanced".
  Descriptor errors are said the plain way (`DescriptorErrors`), with a hint when a shape holds a file name; the checkboxes
  of the notes no longer wrap the other forms

- Web interface: nothing to run in a terminal. The generate and doctor pages say which command their button runs
  (`php bin/console api-forge:doctor <api>`) and which variables the doctor reads (key, host); the doctor page has no
  checkbox anymore; the editor of an example has **Save & import** (saves, imports the example again with `--force` and
  its variants after it, links to the notes or to generate); `POST /api/<api>/import` answers JSON

- Web editor: typing `{` closes it (Ace only does so before a closing bracket, or on Enter), as `[`, `(` and quotes do

- Web interface: an Ace editor wherever a file is to be edited (`docs/web.md`): the edit links of the dashboard, the notes
  (descriptor, examples, the docs page at the line of the note) and the results open the file in the page, with Save and
  Restore, error lines marked, Ctrl-S, JSON format, find, go to line, wrap, invisible characters, text size, all the
  options of Ace, light and Cobalt themes; the code boxes of the forms are editors too, in the language of their radio
  buttons. Descriptors can be edited (YAML checked, the loader's findings as warnings). Progressive enhancement: plain
  text areas without JavaScript. Ace 1.43.6 (BSD-3) shipped, 1.2 MB
- `api-forge:serve`: a local web interface of the workflow (`docs/web.md`), on PHP's built-in server (127.0.0.1 only,
  not in prod, no framework, Bootstrap 5.3 shipped): a form for each step: describe the API, paste the examples of the
  docs (written with the right names, checked, then imported), type files, edit and import again, review and delete
  the notes, generate, run the doctor. Stateless: the state is read from the project's files. Host and token checks;
  it writes only `tests/Fixtures/<api>/` and `config/api/<api>/`
- `ImportRunner` and `GenerateRunner`: the work of `api-forge:import` and `api-forge:generate` as classes, shared with the
  web interface; `Guide::progress()` (the state of an API as data); `DescriptorStore::removeReviewNotes()`

- `docs/workflow.md` rewritten as a step-by-step workflow: the files and what api-forge reads from their names, which file for
  what the docs give, giving the request (curl or array: `request`, `query`, `headers`, `body`), the response, type files and
  the docs page as text, importing again, and a worked example (Paddle prices)

- The documentation page as text: `<operation>-<resource>.docs.md` next to an example (or `api-forge:import --docs`) is the
  copy of the field list of the docs page. `DocsCapture` reads it (fields, `| null`, `required`, formats, defaults, `Values`
  of an enum, unless the page cuts the list), `DocsAnnotator` aligns it with the structure of the example (the copy is
  flat; names counted differently by the page and the example are not applied and reported, what only the page
  describes is set aside) and `ArrayShapeGenerator::generate()` uses it: optional keys, nullable values, enum literals, the
  type of what the example leaves `mixed`; a documented field found in the example under another parent is reported. Plain JSON responses only

- The review notes about `mixed` and `list<mixed>` list the keys concerned as paths (`data[].trial_period`, up to 10)

- Array examples accept the extension `.array`: the bare array literal, without `<?php return`
- The review note of a response or request body that is not JSON says why (`LenientJsonDecoder::explain()`): the
  line of a single-quoted string, or the JSON error

- Examples can point to other data files, as API documentation points to types it describes elsewhere: a value
  that is a relative file name with a data extension (`"duration-type.json"`, `'money-type.php'`) is replaced by
  the content of that file, in JSON examples, array examples (request body and response), objects and webhook
  payloads. Resolved from the directory of the file that holds it, nested, cycles refused, a missing file is an
  error, a leading backslash keeps a string as it is; documents without such a value are untouched;
  `<type>-type.json|php` files are left out of the examples `api-forge:guide` proposes to import.
  `ExampleFormat::json()` is replaced by `document()` and `operation()` takes the resolver

- Documentation examples written as PHP arrays (`<operation>-<resource>.php`), for what the docs describe in prose
  only: `request` (`METHOD path` or URL), `query`, `headers`, `body` and `response`. `api-forge:import` turns
  them into the curl command and JSON response it reads; `import-object` and `import-webhook --payload` accept
  an array as the JSON document; `api-forge:guide` lists them with the examples to import. The file is read as
  data, never run (`PhpLiteral`: arrays, strings, numbers, true/false/null; comments allowed; anything else
  is refused with its line). `ExampleFormat` (tag `api_forge.example_format`) adds other formats of
  documentation examples; `ExampleReader` reads them

- `api-forge:yaml-to-php`: a YAML file (a descriptor) as a PHP array literal, multi-line strings as nowdocs;
  the code is checked (tokens of a literal only, then evaluated and compared with the parsed YAML, keys,
  order and types) before it is printed or written; `--output` writes a file that returns the array and
  never overwrites without `--force`; comments, which PHP data can't carry, are listed on stderr

- Hosts chosen at runtime: `environments` (other hosts of the API, e.g. a sandbox) and `base_uri_env` in
  `_api.yaml`; the generated client uses the environment variable, `base_uri` by default; the importer
  accepts the URLs of every listed host and suggests this setup for a first import from a sandbox host;
  the doctor tells the host in use

- `api-forge:guide`: the workflow, the naming of the documentation examples (`tests/Fixtures/<api>/`,
  `<operation>-<resource>.curl` / `.json`…, documented in `docs/workflow.md`), and the state of an API with
  its next step and the commands to copy (examples not imported yet, generation, doctor)

- Generated doctors: `Generated/<Namespace>Doctor.php`, regenerated with the services, checks the
  environment variables of the API and calls its probe (`doctor.probe` in `_api.yaml`, a harmless `GET`,
  guessed by the importer, validated by the loader), diagnosing unreachable hosts, rejected credentials,
  wrong base URIs, rate limits and provider errors; `api-forge:doctor` runs them with the webhook checks
- Webhook delivery inbox (development, `api_forge.webhook_inbox`): every delivery to `/webhook/{type}` is
  recorded with its origin (provider, simulated, ping) and outcome (consumed, queued then updated by the
  Messenger worker, rejected with the reason, failed); `api-forge:webhook:inbox` lists and shows them,
  `api-forge:webhook:replay` delivers one again; the doctor reports the last deliveries;
  `AbstractSignedRequestParser::describe()`
- Doctor, webhooks: event coverage (the events the application's consumer handles); `registration` in
  `_webhooks.yaml` tells the generated doctor how to read the webhooks registered at the provider, checked
  against the endpoint path and the handled events, and pinged; `ApiDiagnosis` runs the whole diagnosis of
  an API (for api-forge:doctor and bundles built on it); `api-forge:doctor --no-ping`
- Doctor contract check: the probe's response is compared with its shape in the descriptor (missing and
  new fields, types, enum values), with `ShapeChecker`, a runtime checker of values against array shapes.
  After upgrading, regenerate the services: the generated `probe()` now returns the response (a doctor
  generated before doesn't match `AbstractApiDoctor`; change its `probe(): void` to `probe(): array` with a
  `return` if the kernel can't boot to regenerate)

- `api-forge:webhook:send`: simulated webhook deliveries, signed with the routing secret, in-process or
  over HTTP, with values replaced in the payload (`--set`); no tunnel nor real event needed
- `WebhookTestTrait::sendWebhook()`: signed deliveries in functional tests
- `AbstractSignedRequestParser::deliveryHeaders()`: the headers of a delivery, as the API sends them
- `api-forge:webhook:check`: checks the webhook chain (routes, parser, secret, consumer, Messenger) and,
  with `--ping`, a public URL (tunnel); `WebhookDiagnostics`, `WebhookSender::ping()`,
  `AbstractSignedRequestParser::pingPayload()`; checks are `Dev\Doctor\Check` objects
- Descriptors: `body.id` (JSON:API) puts the resource id, the last path parameter, in the request
  document of actions like `POST orders/{id}/refund`; the importer sets it when the example document has
  an id. PATCH and PUT keep doing it by default
- `api-forge:webhook:send --url` only reports success for the endpoint's 202: another 2xx means the URL
  reaches another page (path missing)

- Example: Lemon Squeezy subscriptions, subscription invoices and license keys (object shapes reviewed
  against the attribute docs), which types the payloads of 13 more webhook events; webhook `meta` shape
  completed (`test_mode`, `webhook_id`, `custom_data`); signing scheme checked against the docs

## 0.1.0 - 2026-10-30

First release.

- `api-forge:import`: curl commands and example responses → descriptors
- `api-forge:import-object`: resource object examples → object shapes, several examples merged into one
- `api-forge:import-webhook`: events table and payload examples → `_webhooks.yaml`
- `api-forge:generate`: strict validation of the descriptors, generation of the resource services, entry
  point, webhook request parser, event constants and payload types, base consumer, and configuration
  (scoped HTTP client, webhook routing); `--check` and `--strict` for CI
- `api-forge:json-to-php`: JSON example → PHP array literal and PHPDoc array shape
- Runtime classes (`Snorky22\ApiForge\Core`): JSON and JSON:API resources, pagination, `ApiException`,
  signed webhook request parser, event consumer
- Example: Lemon Squeezy customers, orders, checkouts and webhooks, in `examples/lemonsqueezy/`
