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
| 2. Add examples | operation, resource, optional variant; paste the request (cURL, or an array), the response (JSON or array), the docs page as text | writes `tests/Fixtures/<api>/<operation>-<resource>.…` with the right names, then imports it |
| 2b. Add a type file | name, JSON or array | writes `<name>-type.json` / `.array`, to point to from the examples |
| 2c. Edit an example | the file in a text area | saves it, then **Import again** (with `--force` if you tick it) |
| 3. Review the notes | the notes the importer left, with what to do about each | deletes the notes you tick from the descriptors |
| 4. Generate | a button | writes the generated code, lists the files changed |
| 5. Check | "I put the API key in `.env.local`", a button | runs `api-forge:doctor` and shows its report |

The dashboard of an API shows the five steps as done / next / to do, from the state of the files (the same
information as `api-forge:guide`). Webhooks are not in the interface yet: use the commands of
[Adding webhooks](workflow.md#adding-webhooks).

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
| **Save**, **Restore** | Save posts the file to the server, which checks it (JSON, curl, array, YAML) and answers in the editor, with the line of an error marked in the margin. Restore discards your changes and reloads the file from disk. Ctrl/Cmd-S saves. The **×** of the panel closes the editor (it asks first if there are unsaved changes; on the page of a file it goes back to the API). A page with unsaved changes asks before it is left |
| Languages | JSON (with live syntax check), PHP arrays (also without `<?php`), YAML, Markdown, cURL (shell), text |
| Themes | Light and Cobalt, remembered in the browser |
| Options | line numbers, folding, undo/redo (Ctrl-Z), auto-indent with indentation detection, find and replace (Ctrl-F), go to line (Ctrl-L), soft wrap, invisible characters, text size, **Format** for JSON, and every other Ace option in **Settings** (Ctrl-,) |

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
