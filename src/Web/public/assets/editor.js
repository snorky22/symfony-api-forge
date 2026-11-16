/*
 * Ace editors for the api-forge web interface.
 *
 * Progressive enhancement: the pages work with plain textareas. This script, when Ace is loaded, replaces
 *   - [data-af-code]  the textareas of the forms (a code box, with the language of its radio buttons), and
 *   - [data-af-panel] the editor of a file (Save, Restore), which a link [data-af-open] opens in the page,
 * with an Ace editor and a toolbar. Nothing is kept in the page: saving posts the file to the server.
 */
(function () {
    'use strict';

    if (typeof ace === 'undefined') {
        return;
    }

    var script = document.currentScript;
    var token = script ? script.getAttribute('data-token') : '';
    var THEMES = {light: 'ace/theme/github', cobalt: 'ace/theme/cobalt'};
    var LABELS = {sh: 'cURL', array: 'array', json: 'JSON', php: 'PHP', markdown: 'Markdown', yaml: 'YAML', text: 'text'};
    var editors = [];
    var prefs = readPrefs();

    ace.config.set('basePath', '/assets/ace');

    function readPrefs() {
        var defaults = {theme: 'light', fontSize: 14, wrap: null, invisibles: false};
        try {
            return Object.assign(defaults, JSON.parse(localStorage.getItem('api-forge.editor') || '{}'));
        } catch (e) {
            return defaults;
        }
    }

    function writePrefs() {
        try {
            localStorage.setItem('api-forge.editor', JSON.stringify(prefs));
        } catch (e) {
            // private mode: the preferences last for the page only
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    // Chevrons of Bootstrap Icons (MIT), for the buttons of the height of the editor
    var CHEVRON_UP = '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.646 4.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1-.708.708L8 5.707l-5.646 5.647a.5.5 0 0 1-.708-.708z"/></svg>';
    var CHEVRON_DOWN = '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>';

    function iconButton(svg, title, onClick) {
        var b = el('button', 'btn btn-sm btn-outline-secondary');
        b.type = 'button';
        b.title = title;
        b.setAttribute('aria-label', title);
        b.innerHTML = svg; // a constant above
        b.addEventListener('click', onClick);
        return b;
    }

    function button(label, title, className, onClick) {
        var b = el('button', 'btn btn-sm ' + (className || 'btn-outline-secondary'), label);
        b.type = 'button';
        b.title = title || '';
        b.addEventListener('click', onClick);
        return b;
    }

    // Typing "{" closes it, as "(", "[" and quotes already do (Ace's default is to close a brace only before a closing
    // bracket, or when Enter is pressed after it)
    function pairBraces(session) {
        var mode = session.getMode();
        var cstyle = ace.require('ace/mode/behaviour/cstyle');
        if (mode && mode.$behaviour && cstyle) {
            mode.$behaviour = new cstyle.CstyleBehaviour({braces: true});
        }
    }

    // The language of an editor. "array" is a PHP array without "<?php": the PHP mode, inline.
    function setMode(editor, mode) {
        var session = editor.session;
        session.setOption('useWorker', mode === 'json' || mode === 'yaml');
        if (mode === 'array') {
            ace.config.loadModule('ace/mode/php', function (php) {
                session.setMode(new php.Mode({inline: true}));
                pairBraces(session);
            });
        } else {
            session.setMode('ace/mode/' + mode, function () {
                pairBraces(session);
            });
        }
        editor.afMode = mode;
        editors.forEach(function (e) {
            if (e.editor === editor && e.refresh) {
                e.refresh();
            }
        });
    }

    function create(textarea, options) {
        var host = el('div', 'af-ace');
        host.style.height = options.height || '';
        textarea.parentNode.insertBefore(host, textarea);
        textarea.style.display = 'none';
        textarea.removeAttribute('required');

        var editor = ace.edit(host, {
            showPrintMargin: false,
            useSoftTabs: true,
            tabSize: 4,
            navigateWithinSoftTabs: true,
            highlightActiveLine: true,
            showFoldWidgets: true,
            fadeFoldWidgets: true,
            scrollPastEnd: 0.2,
            behavioursEnabled: true,
            fontSize: prefs.fontSize,
            showInvisibles: prefs.invisibles,
            wrap: prefs.wrap !== null ? prefs.wrap : !!options.wrap,
            theme: THEMES[prefs.theme] || THEMES.light
        });
        editor.session.setValue(textarea.value, -1);
        setMode(editor, options.mode);
        ace.config.loadModule('ace/ext/whitespace', function (whitespace) {
            whitespace.detectIndentation(editor.session);
        });
        if (window.ResizeObserver) {
            new ResizeObserver(function () {
                editor.resize();
            }).observe(host);
        }
        editor.session.on('change', function () {
            textarea.value = editor.getValue();
            editor.session.clearAnnotations();
        });
        return editor;
    }

    function applyPrefs() {
        editors.forEach(function (e) {
            e.editor.setTheme(THEMES[prefs.theme] || THEMES.light);
            e.editor.setFontSize(prefs.fontSize);
            e.editor.setOption('showInvisibles', prefs.invisibles);
            if (prefs.wrap !== null) {
                e.editor.session.setUseWrapMode(prefs.wrap);
            }
            if (e.theme) {
                e.theme.value = prefs.theme;
            }
        });
    }

    // The toolbar: what helps in a code box. `extra` are the buttons of the owner (Save, Restore).
    function toolbar(editor, owner, extra) {
        var bar = el('div', 'af-toolbar');
        extra.forEach(function (b) {
            bar.appendChild(b);
        });
        var format = button('Format', 'Format the JSON (4 spaces)', 'btn-outline-secondary', function () {
            try {
                editor.setValue(JSON.stringify(JSON.parse(editor.getValue()), null, 4) + '\n', -1);
            } catch (e) {
                owner.message('danger', 'Cannot format: not valid JSON (' + e.message + '). Comments and placeholders are accepted by the importer, not by the formatter.');
            }
        });
        owner.refresh = function () {
            format.style.display = editor.afMode === 'json' ? '' : 'none';
        };
        owner.refresh();
        bar.appendChild(format);
        bar.appendChild(button('Find', 'Find and replace (Ctrl-F)', 'btn-outline-secondary', function () {
            editor.execCommand('find');
        }));
        bar.appendChild(button('Go to line', 'Go to a line (Ctrl-L)', 'btn-outline-secondary', function () {
            editor.execCommand('gotoline');
        }));
        bar.appendChild(button('Wrap', 'Wrap long lines', 'btn-outline-secondary', function () {
            prefs.wrap = !editor.session.getUseWrapMode();
            writePrefs();
            applyPrefs();
        }));
        bar.appendChild(button('¶', 'Show invisible characters (spaces, tabs)', 'btn-outline-secondary', function () {
            prefs.invisibles = !prefs.invisibles;
            writePrefs();
            applyPrefs();
        }));
        // The height of the editor, by steps of five lines
        var shorter = iconButton(CHEVRON_UP, 'Shorter editor', function () {
            resizeBy(-1);
        });
        var taller = iconButton(CHEVRON_DOWN, 'Taller editor', function () {
            resizeBy(1);
        });
        function resizeBy(direction) {
            var host = editor.container;
            var step = editor.renderer.lineHeight * 5;
            var max = Math.max(200, window.innerHeight * 0.95);
            var height = Math.min(max, Math.max(120, Math.round(host.getBoundingClientRect().height + direction * step)));
            host.style.height = height + 'px';
            editor.resize();
            shorter.disabled = height <= 120;
            taller.disabled = height >= max;
        }
        bar.appendChild(shorter);
        bar.appendChild(taller);
        bar.appendChild(button('A−', 'Smaller text', 'btn-outline-secondary', function () {
            prefs.fontSize = Math.max(9, prefs.fontSize - 1);
            writePrefs();
            applyPrefs();
        }));
        bar.appendChild(button('A+', 'Larger text', 'btn-outline-secondary', function () {
            prefs.fontSize = Math.min(28, prefs.fontSize + 1);
            writePrefs();
            applyPrefs();
        }));
        var theme = el('select', 'form-select form-select-sm');
        theme.title = 'Theme';
        [['light', 'Light'], ['cobalt', 'Cobalt']].forEach(function (t) {
            var o = el('option', '', t[1]);
            o.value = t[0];
            theme.appendChild(o);
        });
        theme.value = prefs.theme;
        theme.addEventListener('change', function () {
            prefs.theme = theme.value;
            writePrefs();
            applyPrefs();
        });
        owner.theme = theme;
        bar.appendChild(theme);
        bar.appendChild(button('Settings', 'All the options of the editor (Ctrl-,)', 'btn-outline-secondary', function () {
            ace.config.loadModule('ace/ext/settings_menu', function (menu) {
                if (!editor.showSettingsMenu) {
                    menu.init(editor);
                }
                editor.showSettingsMenu();
            });
        }));

        return bar;
    }

    function alertBox(kind, text) {
        var box = el('div', 'alert alert-' + kind + ' py-2', text);
        box.setAttribute('role', 'alert');
        return box;
    }

    // A box of a form: [data-af-code]
    function mountField(textarea) {
        var wrapper = el('div', 'af-field');
        textarea.parentNode.insertBefore(wrapper, textarea);
        var messages = el('div', 'af-messages');
        var owner = {
            message: function (kind, text) {
                messages.textContent = '';
                messages.appendChild(alertBox(kind, text));
            }
        };
        var modes = {};
        (textarea.getAttribute('data-af-modes') || '').split(',').forEach(function (pair) {
            var p = pair.split(':');
            if (p.length === 2) {
                modes[p[0]] = p[1];
            }
        });
        var radioName = textarea.getAttribute('data-af-mode-from');
        var radios = radioName ? document.querySelectorAll('input[name="' + radioName + '"]') : [];
        var mode = textarea.getAttribute('data-af-code');
        radios.forEach(function (r) {
            if (r.checked && modes[r.value]) {
                mode = modes[r.value];
            }
        });

        // As tall as its content, between the rows of the box and about half of the window
        var minimum = Math.max(150, (parseInt(textarea.rows, 10) || 6) * 22);
        var maximum = Math.max(minimum, Math.round(window.innerHeight * 0.55));
        var wanted = textarea.value.split('\n').length * 19 + 30;
        var editor = create(textarea, {mode: mode, height: Math.min(maximum, Math.max(minimum, wanted)) + 'px', wrap: mode === 'sh' || mode === 'text'});
        var bar = toolbar(editor, owner, []);
        wrapper.appendChild(bar);
        wrapper.appendChild(messages);
        wrapper.appendChild(editor.container);
        editors.push({editor: editor, theme: owner.theme, refresh: owner.refresh});
        owner.refresh();

        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                if (r.checked && modes[r.value]) {
                    setMode(editor, modes[r.value]);
                }
            });
        });

        // A docs page: the HTML of a paste keeps the nesting of the fields; it goes with the form, in a hidden field
        var htmlName = textarea.getAttribute('data-af-html');
        if (htmlName && textarea.form) {
            var hidden = textarea.form.querySelector('input[name="' + htmlName + '"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = htmlName;
                textarea.form.appendChild(hidden);
            }
            editor.container.addEventListener('paste', function (event) {
                var html = event.clipboardData ? event.clipboardData.getData('text/html') : '';
                var fields = 0;
                if (html) {
                    // Roughly what api-forge will find: an element with a name, then a type
                    var page = new DOMParser().parseFromString(html, 'text/html');
                    page.querySelectorAll('*').forEach(function (node) {
                        var first = node.firstElementChild;
                        var second = first ? first.nextElementSibling : null;
                        if (second && /^[A-Za-z_][\w.-]*$/.test(first.textContent.trim()) && /^(string|integer|number|boolean|object|array|null)\b/i.test(second.textContent.trim())) {
                            fields++;
                        }
                    });
                }
                hidden.value = fields > 0 ? html : '';
                if (fields > 0) {
                    owner.message('success', 'The paste holds the HTML of the page: about ' + fields + ' fields with their nesting. When you save, api-forge keeps it and writes the text from it.');
                } else {
                    owner.message('warning', html
                        ? 'The paste holds HTML, but no field list api-forge can read (a name, then its type): the text is used, the nesting of the fields stays unknown.'
                        : 'The paste holds text only: the nesting of the fields stays unknown. Copy from the page itself in the browser, not from an extension or a text editor.');
                }
            }, true);
        }

        // Ctrl/Cmd-S submits the form of the box, as it saves the file in the editor of a file
        if (textarea.form) {
            editor.commands.addCommand({
                name: 'submitForm',
                bindKey: {win: 'Ctrl-S', mac: 'Command-S'},
                exec: function () {
                    textarea.value = editor.getValue();
                    if (typeof textarea.form.requestSubmit === 'function') {
                        textarea.form.requestSubmit();
                    } else {
                        textarea.form.submit();
                    }
                }
            });
        }
    }

    // The editor of a file: [data-af-panel]. `close` dismisses it: the page's script removes it from the page; the
    // page of the file goes back to the API.
    function mountPanel(panel, close) {
        if (panel.afMounted) {
            return;
        }
        panel.afMounted = true;

        var textarea = panel.querySelector('[data-af-source]');
        var url = panel.getAttribute('data-url');
        var name = panel.getAttribute('data-name');
        var area = panel.getAttribute('data-area');
        var mode = panel.getAttribute('data-mode');
        var messages = panel.querySelector('[data-af-messages]');
        var status = panel.querySelector('[data-af-status]');
        var saved = textarea.value;

        // The fields are not submitted: the page posts the file itself. In a page that is a form (the notes),
        // they would be sent with it.
        panel.querySelectorAll('input, textarea').forEach(function (input) {
            input.removeAttribute('name');
        });
        var noJs = panel.querySelector('[data-af-nojs]');
        if (noJs) {
            noJs.style.display = 'none';
        }
        var form = panel.querySelector('form[data-af-form]');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
            });
        }

        var owner = {
            message: function (kind, text) {
                messages.textContent = '';
                messages.appendChild(alertBox(kind, text));
            }
        };
        var editor = create(textarea, {mode: mode, height: '', wrap: mode === 'markdown' || mode === 'sh'});
        // The format shown: the file's own, or the other one (a PHP array viewed as JSON folds its objects); a change
        // made in the other view keeps the file unsaved
        var view = mode;
        var changedInOtherView = false;

        function dirty() {
            return changedInOtherView || editor.getValue() !== saved;
        }

        // The content in another format, by the server (the same reader as the importer)
        function convert(content, to) {
            var body = new URLSearchParams({_token: token, content: content, to: to});
            return fetch(url.replace(/\/file$/, '/convert'), {method: 'POST', headers: {Accept: 'application/json'}, body: body})
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data.ok) {
                        throw new Error((data.errors || ['Not converted.']).join(' '));
                    }
                    return data;
                });
        }

        function updateStatus() {
            var pos = editor.getCursorPosition();
            status.textContent = (dirty() ? '● unsaved · ' : '') + 'Ln ' + (pos.row + 1) + ', Col ' + (pos.column + 1)
                + ' · ' + editor.session.getLength() + ' lines · ' + (LABELS[mode] || mode) + (view !== mode ? ', viewed as ' + LABELS[view] : '');
            panel.afDirty = dirty();
        }

        function annotate(text) {
            var found = /line (\d+)/i.exec(text);
            if (found) {
                var row = Math.max(0, parseInt(found[1], 10) - 1);
                editor.session.setAnnotations([{row: row, column: 0, text: text, type: 'error'}]);
                editor.gotoLine(row + 1, 0, true);
            }
        }

        // Resolves to true when the file was saved; viewed in the other format, it is written back in its own
        function save() {
            var shown = editor.getValue();
            var content = view === mode ? Promise.resolve(shown) : convert(shown, mode).then(function (data) {
                return data.content;
            });
            return content.then(function (text) {
                var body = new URLSearchParams({_token: token, name: name, area: area, content: text});
                return fetch(url, {method: 'POST', headers: {Accept: 'application/json'}, body: body});
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return {ok: response.ok && data.ok, data: data};
                    });
                })
                .then(function (result) {
                    messages.textContent = '';
                    if (!result.ok) {
                        (result.data.errors || ['Not saved.']).forEach(function (error) {
                            messages.appendChild(alertBox('danger', error));
                            annotate(error);
                        });
                        return false;
                    }
                    saved = editor.getValue();
                    changedInOtherView = false;
                    messages.appendChild(alertBox('success', (result.data.message || 'Saved.') + (view !== mode ? ' (written as ' + LABELS[mode] + ')' : '')));
                    (result.data.warnings || []).forEach(function (warning) {
                        messages.appendChild(alertBox('warning', warning));
                    });
                    updateStatus();
                    return true;
                })
                .catch(function (error) {
                    owner.message('danger', 'Not saved: ' + error.message);
                    return false;
                });
        }

        // Saves, then imports the example again on the server (api-forge:import --force, and the variants of the
        // example after it): the examples are the source of the descriptors
        function saveAndImport() {
            save().then(function (ok) {
                if (!ok) {
                    return null;
                }
                var body = new URLSearchParams({_token: token, name: importTarget, force: '1', family: '1'});

                return fetch(url.replace(/\/file$/, '/import'), {method: 'POST', headers: {Accept: 'application/json'}, body: body})
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return {ok: response.ok && data.ok, data: data};
                        });
                    })
                    .then(function (result) {
                        if (!result.ok) {
                            (result.data.errors || ['Not imported.']).forEach(function (error) {
                                messages.appendChild(alertBox('danger', 'Saved, but not imported: ' + error));
                            });
                            return;
                        }
                        var review = 0;
                        result.data.results.forEach(function (r) {
                            review += r.review;
                            messages.appendChild(alertBox(r.review > 0 ? 'warning' : 'success', 'Imported ' + r.name + ' → ' + r.operation + ' (' + r.endpoint + '): ' + r.review + ' note(s) to review.'));
                        });
                        // A list of notes on this page is from before the import
                        if (document.querySelector('[data-af-notes]')) {
                            var stale = alertBox('info', 'The notes listed on this page are from before the import. ');
                            var reload = button('Reload the notes', 'Reload the page: the editor closes', 'btn-outline-primary ms-2', function () {
                                window.location.reload();
                            });
                            stale.appendChild(reload);
                            messages.appendChild(stale);
                        }
                        var next = el('p', 'mb-1');
                        var link = el('a', '', review > 0 ? 'Review the notes' : 'Generate the code');
                        link.href = url.replace(/\/file$/, review > 0 ? '/notes' : '/generate');
                        next.appendChild(link);
                        messages.appendChild(next);
                    });
            }).catch(function (error) {
                owner.message('danger', 'Not imported: ' + error.message);
            });
        }

        function restore() {
            if (dirty() && !window.confirm('Discard your changes and reload the file from disk?')) {
                return;
            }
            fetch(url + '?' + new URLSearchParams({name: name, area: area}), {headers: {Accept: 'application/json'}})
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (view !== mode) {
                        view = mode;
                        setMode(editor, mode);
                        toggleLabel();
                    }
                    editor.setValue(data.content, -1);
                    saved = data.content;
                    changedInOtherView = false;
                    editor.session.clearAnnotations();
                    messages.textContent = '';
                    messages.appendChild(alertBox('info', 'Restored from disk.'));
                    updateStatus();
                })
                .catch(function (error) {
                    owner.message('danger', 'Cannot reload: ' + error.message);
                });
        }

        var closeButton = el('button', 'btn-close ms-2');
        closeButton.type = 'button';
        closeButton.title = 'Close the editor';
        closeButton.setAttribute('aria-label', 'Close');
        closeButton.addEventListener('click', function () {
            if (dirty() && !window.confirm('Close the editor and discard your changes?')) {
                return;
            }
            if (close) {
                close();
            } else {
                window.location.href = url.replace(/\/file$/, '');
            }
        });
        var group = el('span', 'd-flex align-items-center');
        status.parentNode.replaceChild(group, status);
        group.appendChild(status);
        group.appendChild(closeButton);

        var importTarget = panel.getAttribute('data-import');
        var saveButton = button('Save', 'Save the file (Ctrl-S)', importTarget ? 'btn-outline-primary' : 'btn-primary', save);
        var restoreButton = button('Restore', 'Discard the changes: reload the file from disk', 'btn-outline-danger', restore);
        var buttons = [saveButton];
        if (importTarget) {
            buttons.push(button('Save & import', 'Save, then import ' + importTarget + ' again on the server', 'btn-primary', saveAndImport));
        }
        buttons.push(restoreButton);

        // A PHP array or JSON: shown in the other format and back (JSON folds its objects in the gutter)
        var toggle = null;
        function toggleLabel() {
            if (toggle) {
                toggle.textContent = view === 'json' ? 'View as PHP array' : 'View as JSON';
            }
        }
        if (mode === 'array' || mode === 'json') {
            var ownText = null;
            toggle = button('', 'Show the content in the other format; Save writes the file in its own format', 'btn-outline-warning', function () {
                var to = view === 'json' ? 'array' : 'json';
                var wasDirty = editor.getValue() !== saved;
                // Back to the file's own format with nothing changed: its text as it was, comments and layout included
                if (to === mode && !wasDirty && !changedInOtherView && ownText !== null) {
                    view = to;
                    editor.setValue(ownText, -1);
                    setMode(editor, to);
                    saved = ownText;
                    toggleLabel();
                    messages.textContent = '';
                    updateStatus();
                    return;
                }
                if (view === mode) {
                    ownText = wasDirty ? null : editor.getValue();
                }
                convert(editor.getValue(), to).then(function (data) {
                    changedInOtherView = changedInOtherView || wasDirty;
                    view = to;
                    editor.setValue(data.content, -1);
                    setMode(editor, to);
                    saved = data.content;
                    toggleLabel();
                    messages.textContent = '';
                    (data.warnings || []).forEach(function (warning) {
                        messages.appendChild(alertBox('warning', warning));
                    });
                    updateStatus();
                }).catch(function (error) {
                    owner.message('danger', 'Cannot show it as ' + LABELS[to] + ': ' + error.message);
                });
            });
            toggleLabel();
            buttons.push(toggle);
        }
        var bar = toolbar(editor, owner, buttons);
        editor.container.parentNode.insertBefore(bar, editor.container);
        editors.push({editor: editor, theme: owner.theme, refresh: owner.refresh});
        owner.refresh();

        editor.commands.addCommand({name: 'save', bindKey: {win: 'Ctrl-S', mac: 'Command-S'}, exec: save});
        editor.commands.addCommand({
            name: 'settings',
            bindKey: {win: 'Ctrl-,', mac: 'Command-,'},
            exec: function () {
                ace.config.loadModule('ace/ext/settings_menu', function (menu) {
                    if (!editor.showSettingsMenu) {
                        menu.init(editor);
                    }
                    editor.showSettingsMenu();
                });
            }
        });
        editor.session.on('change', updateStatus);
        editor.selection.on('changeCursor', updateStatus);
        updateStatus();

        var line = parseInt(panel.getAttribute('data-line'), 10) || 0;
        if (line > 0) {
            editor.gotoLine(line, 0, true);
            editor.selection.selectLine();
        }
        panel.afEditor = editor;
    }

    // A link [data-af-open]: the editor of the file opens under it, in the page; the link closes it again
    function open(link, event) {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
            return;
        }
        event.preventDefault();
        if (link.afPanel) {
            if (!link.afPanel.afDirty || window.confirm('Close the editor and discard your changes?')) {
                link.afPanel.afClose();
            }
            return;
        }

        var wrapper = el('div', 'mt-2');
        var host = link.closest('li') || link.closest('.card') || link.parentNode;
        host.appendChild(wrapper);
        wrapper.textContent = 'Loading…';
        wrapper.afClose = function () {
            wrapper.remove();
            link.afPanel = null;
        };
        link.afPanel = wrapper;
        fetch(link.href + (link.href.indexOf('?') >= 0 ? '&' : '?') + 'partial=1', {headers: {Accept: 'text/html'}})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                wrapper.innerHTML = html;
                var panel = wrapper.querySelector('[data-af-panel]');
                mountPanel(panel, wrapper.afClose);
                Object.defineProperty(wrapper, 'afDirty', {get: function () { return panel.afDirty; }});
            })
            .catch(function (error) {
                wrapper.textContent = 'Cannot open the file: ' + error.message;
                link.afPanel = null;
            });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-af-open]') : null;
        if (link) {
            open(link, event);
        }
    });

    window.addEventListener('beforeunload', function (event) {
        var unsaved = document.querySelector('[data-af-panel]') && Array.prototype.some.call(document.querySelectorAll('[data-af-panel]'), function (p) {
            return p.afDirty;
        });
        if (unsaved) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    document.querySelectorAll('textarea[data-af-code]').forEach(mountField);
    document.querySelectorAll('[data-af-panel]').forEach(mountPanel);
})();
