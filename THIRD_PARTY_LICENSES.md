# Third-party licenses

The package is MIT (see `LICENSE`). Its web interface (`api-forge:serve`, `src/Web/public/assets/`) ships these files, which
are used under their own licenses; each license text is kept next to the files it covers.

| Component | Files | License | License text |
|---|---|---|---|
| [Bootstrap](https://getbootstrap.com/) 5.3.3 | `src/Web/public/assets/bootstrap.min.css` | MIT, Copyright (c) 2011-2024 The Bootstrap Authors | `src/Web/public/assets/LICENSE-bootstrap.txt` (and the header of the file) |
| [Ace](https://ace.c9.io/) 1.43.6 | `src/Web/public/assets/ace/` (`ace.js`, the modes JSON, PHP, Markdown, shell and YAML, the themes GitHub and Cobalt, the extensions search box, settings menu and whitespace, the JSON and YAML workers) | BSD-3-Clause, Copyright (c) 2010, Ajax.org B.V. | `src/Web/public/assets/ace/LICENSE-ace.txt` |
| [Bootstrap Icons](https://icons.getbootstrap.com/) | the two chevrons of the height buttons, inline in `src/Web/public/assets/editor.js` | MIT, Copyright (c) 2019-2024 The Bootstrap Authors | the Bootstrap license above |

Both licenses allow redistribution, with or without changes, provided the copyright notice and the license text are kept,
as they are. Remove the files and the web interface falls back to plain text areas with the Bootstrap look missing:
nothing else depends on them.
