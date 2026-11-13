# The web interface

A local web interface for the [workflow](workflow.md): a form for each step, in the browser. Each form checks what
it gets, does the work of the step (writes the examples with the right names, imports, generates, runs the doctor)
and returns the form of the next step. It is made for the moments when the command line and the file names get in
the way.

```bash
bin/console api-forge:serve              # http://127.0.0.1:8765/
bin/console api-forge:serve --port=9000
```

It starts PHP's built-in web server (`php -S`): nothing to install, no web server to configure, no framework
involved. Stop it with Ctrl-C. Everything is read from and written to your project's files, so it works alongside
the command line: do a step in the browser and the next one in the terminal, or the other way round.

## The steps

| Step | The form | What it does |
|---|---|---|
| 1. Describe the API | name, base URI (and sandbox), the variable choosing the host, format, authentication, headers | writes `config/api/<api>/_api.yaml` |
| 2. New endpoint | four screens: **Endpoint** (method and path: `POST` `/products`), **Request** (query parameters, and for POST, PUT and PATCH the body as JSON and its docs page), **Response** (the example as JSON, and its docs page), **Import** (the files it has, and a button) | each screen writes its files in `tests/Fixtures/<api>/` (`create-products.curl`, `.body.json`, `.json`, `.docs.md`…): the operation and the resource are worked out from the method and the path, and the endpoint can be left and continued from the dashboard; the last screen imports it |
| 2a. Add an example (advanced) | operation, resource, optional variant; paste the request (cURL, or an array), the body, the response (JSON or array), the docs pages | for what the screens do not cover (an array example, a variant): writes the files with the right names, then imports |
| 2b. Add a type file | name, JSON or array | writes `<name>-type.json` / `.array`, to point to from the examples |
| 2c. Edit an example | the file in a text area | saves it, then **Import again** (with `--force` if you tick it) |
| 3. Review the notes | the notes the importer left, grouped by where they apply (the descriptor, and its operation or event), with a summary of their kinds at the top, as toggles (click a kind to see only its notes, again to see all; Shift-click to hide or show a kind and keep the others; *Show all* to reset; remembered per API in the browser, `assets/notes.js`); each note is an accordion item, one task at a time: its title is the issue in a line, with a badge of its kind (*Example value missing*, *Type unknown*, *Counted differently*, *Not in the docs*, *Guess to check*…); opened, it says **what api-forge found**, **what it means for the code** (what the generated type or method will do), and **what you can do** (a list of choices), then the Fix form, Accept, the files to open, and the raw note folded; for a field the example leaves unknown (`mixed`, an empty list) a **Fix** form per key: choose what it is (one of your type files, text, a date-time, a date, a whole or decimal number, yes/no, a free-form object, a list; a date is text in JSON: it is typed `string`, with an example date in the example) | each note is said as a sentence (what it means, which file to change, how), with the files that hold the part it is about (the body example and its docs page for a body note, the response example and its docs page for a response note) and their role; **Fix** writes a value for the key in the example (the first `null` or `[]` of that key, the rest of the file untouched), makes an object **free-form** (`{}`) when the docs describe none of its fields, imports the example again (and its variants) and shows the notes again; deleting removes the notes you tick from the descriptors |
| 4. Generate | a button | writes the generated code, lists the files changed |
| 5. Check | a button (the page shows the command, and the variables to set in `.env.local`) | runs `api-forge:doctor` and shows its report; the dashboard remembers the last run (`var/api-forge/web/doctor-<api>.json`): the step is **done** (green) when it found no problem and no descriptor changed since, otherwise it asks to run it again |

You never need a terminal: each button runs on the server the command it names (the generate and doctor pages print it,
e.g. `php bin/console api-forge:generate paddle`). The only manual task is setting the API key in `.env.local`, which
the doctor checks and reports on.

Descriptors are the importer's output: the notes offer the examples first, and the descriptor under **Advanced**, with a
reminder that the next import of the example replaces what you change there.

Step 2 starts from what is already in `tests/Fixtures/<api>/`: examples not imported yet are listed with an **Import them**
button, and a descriptor that is invalid (an edit gone wrong) comes with an **Import <example> again** button, which makes
it again from its example. The blank "Add an example" form is for a new example only.

Every button of the first four steps keeps working once the step is done, to edit it: **Edit** the description of the API
(step 1: saving changes `_api.yaml`, and keeps what the form does not hold), **New endpoint** or **Edit** next to an
endpoint of the list (step 2), **Review** (step 3), **Generate again** (step 4). A step is done only if the steps before it
are: editing one makes the next ones to do again ("to do again after step 2"), and the doctor is to do again when a
descriptor or the generated code is newer than its last run. A **New endpoint** whose method and path name an existing
endpoint offers to **edit** it or **replace** it (its request is rewritten; its body, response and docs pages are kept and
shown on the next screens, to change or to keep).

The dashboard of an API shows the five steps as done / next / to do, from the state of the files (the same
information as `api-forge:guide`), then a **Webhooks** card.

## Webhooks

| Screen | What you do | What it does |
|---|---|---|
| **Webhooks** (from the card of the dashboard) | read | how deliveries are received (endpoint, signature, secret, where the event name and the delivery id are, the envelope of plain JSON payloads, whether the doctor can read the registered webhooks), and the events by entity (`transaction`, `subscription`…): typed by their own shape or by the object of their resource, or not yet, with their notes |
| **Webhook payload** (*Add a payload*, or *Edit the payload* next to an event) | choose the event (or let it be read from the payload), paste the payload example as JSON and the docs page of the event as text; *Replace what is described* is ticked | checks the JSON and that the payload is an event of that name, writes `webhook-<event>.json` and `.docs.md`, imports them (`api-forge:import-webhook --force`), and shows what changed: the object of the resource set, the envelope, the other events of the entity typed with it, and the notes left on the event |
| **Review the notes** | as for the endpoints | the notes of an event come with its payload and its docs page; **Fix** writes the value in the payload and imports it again |

Under the Fix form of an unknown key, **Or describe … in a new type file** opens a small editor: a name (proposed
from the key), the format (a PHP array, or JSON), and the content (pre-filled with the fields the docs page lists,
when the note names them). **Save the type, use it here and import** writes `<name>.array` (or `<name>-type.json`),
puts its name in the example in place of the empty value, and imports again; the file is then offered by the Fix
forms of the other notes (the same object elsewhere: one description).

**Accept** remembers the note (an `accepted` list next to its `review` list in the descriptor): an import that writes
the same note again (a Fix, Save & import, Replace) does not show it again; a note written differently (the example
changed) is shown. The page also says when an example file can't be read (edited outside the interface: invalid JSON
or PHP, with the error), and the editor and the new type file refuse a file used as one item of a list
(`["payments.array"]`) that holds a list.

A note that an example's objects differ from the object already described has two buttons: **Merge both** (the object
is described from its examples and this one) and **Replace with this one**; *Accept* keeps the object as it is. A note
about the object of a resource (the notes of an event whose payload holds it, or the notes naming the resource) shows
where the object is described (merged from several examples, they are listed): once, in the resource descriptor, from the payload or from a file of its own. Its
button **Create `<object>-object.array` and edit it** gives the object a file of its own, a PHP array made from the object
of the payload, which then points to it (`"data": "transaction-object.array"`: only that value of the payload changes),
and opens its editor; once the file exists, the button is **Edit the … object**. In the editor, **Save & import** imports
again every payload and example that points to the file (also through another file: a type inside the object), and so
do the Fix forms, which write in that file with the PHP syntax (`'key' => 'text'`; an array cannot say "any object": use
a type file, or write the fields). A webhook payload opened in the editor has **Save & import** too.

A payload holding an object (`data`) types its resource, so one payload and its docs page per entity type all its events:
the Paddle `transaction.completed` payload types the ten `transaction.*` events. Then **Generate** and the doctor, as for
the endpoints. The events table of the docs is imported from the command line for now
(`api-forge:import-webhook <api> --events=tests/Fixtures/<api>/webhook-events.md`).

What the forms take over from the workflow page:

- **File names.** You give the operation and the resource; the server writes `list-prices.curl`,
  `list-prices.json`, `list-prices.docs.md`. If the request turns out to be another operation than the name says
  (`get`, not `list`), the result page says so.
- **Pasted content is checked before anything is written**: the curl command is parsed, JSON that is not JSON gets
  its line (`line 2: a single-quoted string`), an array that holds code is refused with its line.
- **Nothing is replaced silently**: an example that exists and differs needs "Replace the files"; an operation of the
  descriptors needs "Replace the operation".

## The editor

Wherever the interface asks you to fix something, there is a link that opens the file in an editor, in the same page:
the dashboard (examples and descriptors), the review notes (the descriptor of the note, and the examples of its
resource, the docs page at the line the note gives), the result of an import, an import that failed after saving. The
code boxes of the forms (the request, the response, the docs page, a type) are editors too, with the language of their
radio buttons (cURL, JSON or array).

The editor is [Ace](https://ace.c9.io/) (BSD-3, shipped in `src/Web/public/assets/ace/`: about 1.2 MB, loaded on the
pages that have an editor only):

| | |
|---|---|
| **Save**, **Save & import**, **Restore** | **Save & import** (for an example, or the response or docs page next to it) saves, then imports the example again on the server (`--force`, then its variants such as `list-prices-include.php`, which add their query parameters) and links to the next step. Save posts the file to the server, which checks it (JSON, curl, array, YAML) and answers in the editor, with the line of an error marked in the margin. Restore discards your changes and reloads the file from disk. Ctrl/Cmd-S saves. The **×** of the panel closes the editor (it asks first if there are unsaved changes; on the page of a file it goes back to the API). A page with unsaved changes asks before it is left |
| Languages | JSON (with live syntax check), PHP arrays (also without `<?php`), YAML, Markdown, cURL (shell), text |
| Themes | Light and Cobalt, remembered in the browser |
| Options | line numbers, folding, undo/redo (Ctrl-Z), auto-indent with indentation detection, automatic closing of `{`, `[`, `(` and quotes (typing the closing one goes over it, Enter between braces opens a block), find and replace (Ctrl-F), go to line (Ctrl-L), soft wrap, invisible characters, text size, the height of the editor (a code box of a form starts as tall as its content, between its rows and about half of the window; the chevron buttons make it shorter or taller by five lines, from 120 px to 95% of the window; the bottom edge can be dragged too), **Format** for JSON, and every other Ace option in **Settings** (Ctrl-,) |

Without JavaScript, or if Ace does not load, the pages work with plain text areas and the edit links open a page with
a text area and a Save button. Descriptors are saved as soon as they are valid YAML; if the loader then finds them
invalid, the editor says so, as a warning. The editor opens only the examples and descriptors of the API (names are
checked, as above); generated code is never edited.

## Safety

A development tool that writes files, so:

- it listens on `127.0.0.1` only and answers only to `127.0.0.1:<port>` and `localhost:<port>` (a page of another site
  cannot reach it through DNS tricks);
- every form carries a token made for the run of the server (a page of another site cannot submit it);
- it refuses to start in the `prod` environment;
- it writes only the examples of an API (`tests/Fixtures/<api>/`) and its descriptors (`config/api/<api>/`); file names
  come from the server or from the files already in that folder, never from a path;
- the API keys are never shown: they stay in `.env.local`, and the doctor runs as a console command.

## How it is built

No framework, no new dependency: plain PHP templates, the services of api-forge built by hand
(`Snorky22\ApiForge\Web\WebServices`), and Bootstrap 5.3 (MIT) and Ace 1.43 (BSD-3) shipped in
`src/Web/public/assets/`, so it works offline. `editor.js` (about 400 lines, no build step) mounts Ace on the text areas
marked `data-af-code` and on the panels opened by the `data-af-open` links.
`api-forge:serve` passes its settings to the server as JSON in an environment variable; `Web\WebApp::handle()` turns a
`Request` into a `Response`, which is what the tests call. The import and the generation are the classes the commands
use (`ImportRunner`, `GenerateRunner`).
