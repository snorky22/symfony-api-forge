/*
 * Review notes: the kinds of the summary filter the notes, one routine at a time. Click a kind to show only its notes
 * (click it again to show all), Shift-click to hide or show it and keep the others as they are. The choice is kept per API in the browser, since Fix and Accept
 * reload the page. Without this script, every note is shown.
 */
(function () {
    'use strict';

    var summary = document.querySelector('[data-af-kinds]');
    if (!summary) {
        return;
    }
    var key = 'api-forge.notes.hidden.' + summary.getAttribute('data-af-kinds');
    var toggles = Array.prototype.slice.call(summary.querySelectorAll('[data-af-kind-toggle]'));
    var kinds = toggles.map(function (t) { return t.getAttribute('data-af-kind-toggle'); });
    var hidden = read();

    function read() {
        try {
            var stored = JSON.parse(localStorage.getItem(key) || '[]');
            return Array.isArray(stored) ? stored.filter(function (k) { return kinds.indexOf(k) >= 0; }) : [];
        } catch (e) {
            return [];
        }
    }

    function apply() {
        try { localStorage.setItem(key, JSON.stringify(hidden)); } catch (e) { /* private mode */ }
        toggles.forEach(function (toggle) {
            var off = hidden.indexOf(toggle.getAttribute('data-af-kind-toggle')) >= 0;
            toggle.setAttribute('aria-pressed', off ? 'false' : 'true');
            toggle.classList.toggle('af-kind-off', off);
        });
        var shown = 0, total = 0;
        document.querySelectorAll('[data-af-group]').forEach(function (group) {
            var notes = group.querySelectorAll('[data-af-kind]');
            var visible = 0;
            notes.forEach(function (note) {
                var off = hidden.indexOf(note.getAttribute('data-af-kind')) >= 0;
                note.classList.toggle('d-none', off);
                visible += off ? 0 : 1;
            });
            group.classList.toggle('d-none', 0 === visible);
            var count = group.querySelector('[data-af-group-count]');
            if (count) {
                count.textContent = visible === notes.length ? String(notes.length) : visible + ' of ' + notes.length;
            }
            shown += visible;
            total += notes.length;
        });
        summary.querySelector('[data-af-shown]').textContent = shown === total ? '' : ' (' + shown + ' shown)';
        summary.querySelector('[data-af-show-all]').classList.toggle('d-none', 0 === hidden.length);
        var none = document.querySelector('[data-af-none]');
        if (none) {
            none.classList.toggle('d-none', shown > 0);
        }
    }

    function only(kind) {
        hidden = kinds.filter(function (k) { return k !== kind; });
        apply();
    }

    toggles.forEach(function (toggle) {
        var kind = toggle.getAttribute('data-af-kind-toggle');
        toggle.addEventListener('click', function (event) {
            if (event.shiftKey) {
                // Shift-click: hide or show this kind, the others as they are
                var at = hidden.indexOf(kind);
                if (at >= 0) {
                    hidden.splice(at, 1);
                } else {
                    hidden.push(kind);
                }
                apply();
                return;
            }
            // Click: only this kind; on the kind already shown alone, everything again
            var alone = hidden.length === kinds.length - 1 && hidden.indexOf(kind) < 0;
            if (alone) {
                hidden = [];
                apply();
            } else {
                only(kind);
            }
        });
    });
    summary.querySelector('[data-af-show-all]').addEventListener('click', function () {
        hidden = [];
        apply();
    });

    apply();
})();
