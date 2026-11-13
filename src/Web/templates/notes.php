<h1 class="h3 mb-1">Review the notes <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">What the importer had to guess, or could not align. Most notes have a <strong>Fix</strong> form: the page writes the fix in your
current structure (the JSON or the array of the example) and imports it again. Tick the notes you accept and delete them (they are removed from the descriptors).</p>

<?php if ([] !== ($unreadable ?? [])): ?>
    <div class="alert alert-danger" id="unreadable">
        <strong>An example file can't be read</strong> (edited outside the interface?): it is left out of the imports until it is fixed.
        <ul class="mb-0 mt-1">
        <?php foreach ($unreadable as $file => $error): ?>
            <li><a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=examples" data-af-open><code><?= $e($file) ?></code></a>: <?= $e($error) ?></li>
        <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>
<?php if (null !== $message): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif ?>
<?php if ([] !== $fixErrors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($fixErrors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>
<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><strong>The descriptors are invalid:</strong><ul class="mb-0"><?php foreach (array_slice($errors, 0, 8) as $error): ?><li><?= $e(str_replace($projectDir.'/', '', $error)) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if ([] === $notes && [] === $errors): ?>
    <div class="alert alert-success">No note left.</div>
    <a class="btn btn-primary" href="/api/<?= $e($api) ?>/generate">Generate the code</a>
<?php elseif ([] !== $notes): ?>
<?php
    // By where they apply (file and part), in the order of the notes; and how many of each kind
    $groups = [];
    $kinds = [];
    foreach ($notes as $i => $note) {
        $groups[$note['location']['group']][$i] = $note;
        $kinds[$note['kind']['label']] ??= ['count' => 0, 'colour' => $note['kind']['colour'], 'asks' => $note['kind']['asks'], 'slug' => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($note['kind']['label'])), '-')];
        $kinds[$note['kind']['label']]['count']++;
    }
?>
<div class="card card-body mb-3 py-2" data-af-kinds="<?= $e($api) ?>">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="small text-secondary"><?= count($notes) ?> note(s) in <?= count($groups) ?> place(s), by kind<span data-af-shown></span>:</span>
        <button type="button" class="btn btn-sm btn-link py-0 d-none" data-af-show-all>Show all</button>
    </div>
    <div class="d-flex flex-wrap gap-2">
    <?php foreach ($kinds as $label => $kind): ?>
        <button type="button" class="btn btn-sm btn-<?= $e($kind['colour']) ?> af-kind" data-af-kind-toggle="<?= $e($kind['slug']) ?>" aria-pressed="true"
                title="<?= $e(ucfirst($kind['asks'])) ?>. Click: only these notes (again: all). Shift-click: hide or show them.">
            <span class="badge text-bg-light me-1"><?= $kind['count'] ?></span><?= $e($label) ?>
        </button>
    <?php endforeach ?>
    </div>
    <div class="small text-secondary mt-2">Click a kind to see only its notes, one routine at a time (click it again to see all); Shift-click to hide or show a kind. Each note has its <strong>Fix</strong> when the page can do it, or <strong>Accept</strong> when nothing needs to change.</div>
</div>

<div data-af-notes>
    <?php foreach ($groups as $group => $members): ?>
    <?php $first = reset($members)['location']; ?>
    <section data-af-group>
    <h2 class="h6 mt-4 mb-2 d-flex align-items-center gap-2">
        <code><?= $e('' !== $first['file'] ? $first['file'] : 'the API') ?></code>
        <?php if (null !== $first['part']): ?><span class="text-secondary">›</span> <span><?= $e(explode(' ', $first['part'], 2)[0]) ?> <code><?= $e(explode(' ', $first['part'], 2)[1] ?? '') ?></code></span><?php endif ?>
        <span class="badge rounded-pill text-bg-light border" data-af-group-count data-total="<?= count($members) ?>"><?= count($members) ?></span>
    </h2>
    <div class="accordion af-accordion mb-3">
    <?php foreach ($members as $i => $note): ?>
        <?php $isDraft = null !== $draft && null !== $note['fix'] && [] !== array_filter($note['fix']['keys'], static fn (array $k) => $k['key'] === $draft['key'] && $k['file'] === $draft['file']); ?>
        <details class="accordion-item" id="note-<?= $i ?>" data-af-kind="<?= $e($kinds[$note['kind']['label']]['slug']) ?>"<?= $isDraft ? ' open' : '' ?>>
            <summary class="accordion-button py-2">
                <span class="badge text-bg-<?= $e($note['kind']['colour']) ?> me-2 flex-shrink-0"><?= $e($note['kind']['label']) ?></span>
                <?php if (null !== $note['part']): ?><span class="badge text-bg-light border me-2 flex-shrink-0" title="The part of the API this note is about<?= 'object' === $note['part'] ? ': the object of the resource, shared by its responses and webhook payloads' : '' ?>"><?= $e(ucfirst($note['part'])) ?></span><?php endif ?>
                <span><?= $highlight($note['guide']['title']) ?></span>
            </summary>
            <div class="accordion-body">
                <?php if (null !== $note['object']): $object = $note['object']; ?>
                    <div class="alert <?= $object['described'] ? 'alert-light border' : 'alert-warning' ?> d-flex justify-content-between align-items-center py-2 mb-3">
                        <div class="small">
                            <strong>The <?= $e($object['label']) ?> object</strong>
                            <?php if (!$object['described']): ?>
                                is not described yet<?= [] !== $object['payloads'] ? ': its file can be made from '.$e($object['payloads'][0]) : ': add a payload example that holds it (Webhooks page)' ?>.
                            <?php elseif (count($object['sources']) > 1): ?>
                                is described once, merged from <?= count($object['sources']) ?> examples: <?= $e(implode(', ', $object['sources'])) ?>; generated into <code><?= $e($object['resource']) ?>.yaml</code>.
                            <?php elseif ($object['exists']): ?>
                                is described once, in <code><?= $e($object['file']) ?></code><?= [] !== $object['pointing'] ? ', which '.$e(implode(', ', $object['pointing'])).' point'.(1 === count($object['pointing']) ? 's' : '').' to' : '' ?>; generated into <code><?= $e($object['resource']) ?>.yaml</code>.
                            <?php else: ?>
                                is described once, in <code><?= $e($object['resource']) ?>.yaml</code>, from <?= $e(implode(', ', $object['payloads']) ?: 'an example') ?>.
                                Give it a file of its own to edit it as a PHP array.
                            <?php endif ?>
                        </div>
                        <?php if ($object['exists'] || [] !== $object['payloads']): ?>
                        <form method="post" action="/api/<?= $e($api) ?>/object" class="ms-3 flex-shrink-0">
                            <input type="hidden" name="_token" value="<?= $e($token) ?>">
                            <input type="hidden" name="resource" value="<?= $e($object['resource']) ?>">
                            <button class="btn btn-sm btn-outline-primary" title="<?= $e($object['exists'] ? 'Opens '.$object['file'] : 'Creates '.$object['file'].' from the object of '.$object['payloads'][0].', points the payload to it, and opens it') ?>"><?= $object['exists'] ? 'Edit the '.$e($object['label']).' object' : 'Create '.$e($object['file']).' and edit it' ?></button>
                        </form>
                        <?php endif ?>
                    </div>
                <?php endif ?>
                <?php if ([] !== $note['guide']['found']): ?>
                    <h3 class="h6 text-secondary mb-1">What api-forge found</h3>
                    <ul class="mb-3"><?php foreach ($note['guide']['found'] as $line): ?><li><?= $highlight($line) ?></li><?php endforeach ?></ul>
                <?php endif ?>
                <?php if (null !== $note['guide']['impact']): ?>
                    <h3 class="h6 text-secondary mb-1">What it means for the code</h3>
                    <p class="mb-3"><?= $highlight($note['guide']['impact']) ?></p>
                <?php endif ?>
                <h3 class="h6 text-secondary mb-1">What you can do</h3>
                <ul class="mb-2"><?php foreach ($note['guide']['options'] as $line): ?><li><?= $highlight($line) ?></li><?php endforeach ?></ul>
                <?php if (null !== $note['choice']): ?>
                    <div class="d-flex gap-2 mt-2">
                    <?php foreach (['merge' => ['Merge both', 'btn-primary', 'Describe the object from its examples and this one'], 'replace' => ['Replace with this one', 'btn-outline-primary', 'Describe the object from this example only']] as $mode => [$label, $class, $title]): ?>
                        <form method="post" action="/api/<?= $e($api) ?>/object-choice">
                            <input type="hidden" name="_token" value="<?= $e($token) ?>">
                            <input type="hidden" name="example" value="<?= $e($note['choice']['example']) ?>">
                            <input type="hidden" name="mode" value="<?= $e($mode) ?>">
                            <button class="btn btn-sm <?= $class ?>" title="<?= $e($title) ?>"><?= $e($label) ?></button>
                        </form>
                    <?php endforeach ?>
                    </div>
                <?php endif ?>

            <?php if (null !== $note['fix'] && 'add' === $note['fix']['kind']):
                $add = $note['fix']['keys'][0];
                $uid = $i.'_add';
                $mine = $isDraft && 'add' === $draft['choice'] ? $draft : null;
                $addName = $mine['name'] ?? strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '_', $add['key']));
                $addFormat = $mine['format'] ?? 'array';
                $addContent = $mine['content'] ?? $typeContent($addName, $addFormat) ?? "[\n    'name' => 'value',\n]";
            ?>
                <div class="card card-body mt-3">
                    <div class="small mb-2"><strong>Describe <code><?= $e($add['key']) ?></code> and add it to the current structure</strong> (<?= $e($role($add['file'])) ?>, <code><?= $e($add['file']) ?></code>)</div>
                    <form method="post" action="/api/<?= $e($api) ?>/fix">
                        <input type="hidden" name="_token" value="<?= $e($token) ?>">
                        <input type="hidden" name="choice" value="add">
                        <input type="hidden" name="key" value="<?= $e($add['key']) ?>">
                        <input type="hidden" name="after" value="<?= $e($add['after']) ?>">
                        <input type="hidden" name="file" value="<?= $e($add['file']) ?>">
                        <?php if ($add['inList']): ?>
                        <div class="mb-2 small">
                            <div class="form-check"><input class="form-check-input" type="radio" name="placement" id="pl-item-<?= $uid ?>" value="item"<?= 'same' !== ($mine['placement'] ?? '') ? ' checked' : '' ?>><label class="form-check-label" for="pl-item-<?= $uid ?>">in a <strong>new item</strong> of the list, <strong>instead of</strong> <code><?= $e($add['after']) ?></code>: another way to give an item (the docs list alternatives, e.g. a price id <em>or</em> a price)</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="placement" id="pl-same-<?= $uid ?>" value="same"<?= 'same' === ($mine['placement'] ?? '') ? ' checked' : '' ?>><label class="form-check-label" for="pl-same-<?= $uid ?>">next to <code><?= $e($add['after']) ?></code>, in the same item: one more field of the item</label></div>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="placement" value="<?= ($add['before'] ?? false) ? 'before' : 'same' ?>">
                        <div class="small text-secondary mb-2">added <?= ($add['before'] ?? false) ? 'before' : 'next to' ?> <code><?= $e($add['after']) ?></code>, as in the docs</div>
                        <?php endif ?>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-auto"><label class="form-label mb-0 small" for="add-name-<?= $uid ?>">Type file</label></div>
                            <div class="col-auto"><input class="form-control form-control-sm" id="add-name-<?= $uid ?>" name="new_name" value="<?= $e($addName) ?>"></div>
                            <div class="col-auto">
                                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="new_format[<?= $uid ?>]" id="af-array-<?= $uid ?>" value="array"<?= 'json' !== $addFormat ? ' checked' : '' ?>><label class="form-check-label small" for="af-array-<?= $uid ?>">PHP array</label></div>
                                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="new_format[<?= $uid ?>]" id="af-json-<?= $uid ?>" value="json"<?= 'json' === $addFormat ? ' checked' : '' ?>><label class="form-check-label small" for="af-json-<?= $uid ?>">JSON</label></div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="<?= [] !== $add['skeleton'] ? 'col-lg-7' : 'col-12' ?>">
                                <textarea class="form-control font-monospace mb-1" name="new_content" rows="6" data-af-code="<?= 'json' === $addFormat ? 'json' : 'array' ?>" data-af-mode-from="new_format[<?= $uid ?>]" data-af-modes="json:json,array:array"><?= $e($addContent) ?></textarea>
                                <div class="form-text mb-2">An example value for each field, as the API sends it: a text in quotes, a number, <code>true</code>, a nested array for an object.</div>
                                <button class="btn btn-sm btn-primary">Save the type, add <?= $e($add['key']) ?> and import</button>
                                <span class="small text-secondary ms-2">or Ctrl/Cmd-S in the box: writes the type file, adds <code><?= $e($add['key']) ?></code> to <?= $e($role($add['file'])) ?> (pointing to the type file), then imports again</span>
                            </div>
                            <?php if ([] !== $add['skeleton']): ?>
                            <div class="col-lg-5 small">
                                <div class="text-secondary mb-1">The fields the docs list after <code><?= $e($add['key']) ?></code>, in the page's order (the fields of an <em>object</em> come right after it: put them inside)</div>
                                <ul class="list-unstyled font-monospace mb-0 ms-1 overflow-auto border rounded p-2 bg-body" style="max-height: 22rem"><?php foreach ($add['skeleton'] as $field): ?><li><?= $e($field['name']) ?> <span class="text-secondary">— <?= $e($field['type']) ?></span></li><?php endforeach ?></ul>
                            </div>
                            <?php endif ?>
                        </div>
                    </form>
                </div>
            <?php elseif (null !== $note['fix']): ?>
                <div class="mt-3">
                <?php foreach ($note['fix']['keys'] as $j => $key): ?>
                    <form method="post" action="/api/<?= $e($api) ?>/fix" class="row g-2 align-items-center mb-2">
                        <input type="hidden" name="_token" value="<?= $e($token) ?>">
                        <input type="hidden" name="kind" value="<?= $e($note['fix']['kind']) ?>">
                        <input type="hidden" name="key" value="<?= $e($key['key']) ?>">
                        <input type="hidden" name="file" value="<?= $e((string) $key['file']) ?>">
                        <?php if ('free' === $note['fix']['kind']): ?>
                            <input type="hidden" name="choice" value="freeform">
                            <div class="col-auto"><?php if (null !== $key['file']): ?><span class="badge text-bg-light border me-1">in <?= $e($role($key['file'])) ?></span><?php endif ?><code><?= $e($key['path']) ?></code></div>
                            <?php if (null === $key['file']): ?>
                                <div class="col-auto small text-secondary">not found in an example: edit the examples below.</div>
                            <?php else: ?>
                                <div class="col-auto"><button class="btn btn-sm btn-primary">Make it free-form</button></div>
                                <div class="col-auto small text-secondary">replaces its value with <code>{}</code> in <?= $e($role($key['file'])) ?>, <code><?= $e($key['file']) ?></code>, then imports it again</div>
                            <?php endif ?>
                        <?php else: ?>
                        <div class="col-auto"><?php if (null !== $key['file']): ?><span class="badge text-bg-light border me-1">in <?= $e($role($key['file'])) ?></span><?php endif ?><code><?= $e($key['path']) ?></code> is</div>
                        <?php if (null === $key['file']): ?>
                            <div class="col-auto small text-secondary">not found in an example (it comes from a type file or an array): edit the examples below.</div>
                        <?php else: ?>
                        <div class="col-auto">
                            <select class="form-select form-select-sm" name="choice" aria-label="What <?= $e($key['key']) ?> is">
                                <option value="">choose…</option>
                                <?php if ([] !== $typeFiles): ?>
                                <optgroup label="One of your type files">
                                    <?php foreach ($typeFiles as $typeFile): ?><option value="type:<?= $e($typeFile) ?>"><?= $e('list' === $note['fix']['kind'] ? 'a list of '.$typeFile : $typeFile) ?></option><?php endforeach ?>
                                </optgroup>
                                <?php endif ?>
                                <optgroup label="Something simple">
                                    <option value="string">text</option>
                                    <option value="datetime">a date-time (text, e.g. 2026-01-01T00:00:00Z)</option>
                                    <option value="date">a date (text, e.g. 2026-01-01)</option>
                                    <option value="int">a whole number</option>
                                    <option value="float">a decimal number</option>
                                    <option value="bool">yes / no</option>
                                    <option value="object">a free-form object</option>
                                    <option value="list-string">a list of text</option>
                                    <option value="list-int">a list of numbers</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-primary">Fix</button></div>
                        <div class="col-auto small text-secondary">writes it in <?= $e($role($key['file'])) ?>, <code><?= $e($key['file']) ?></code>, then imports it again</div>
                        <?php endif ?>
                        <?php endif ?>
                    </form>
                    <?php if ('free' !== $note['fix']['kind'] && null !== $key['file']):
                        $uid = $i.'_'.$j;
                        $fields = $key['fields'] ?? [];
                        $mine = null !== $draft && 'new' === $draft['choice'] && $draft['key'] === $key['key'] && $draft['file'] === $key['file'] ? $draft : null;
                        $newName = $mine['name'] ?? strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '_', $key['key']));
                        $newFormat = $mine['format'] ?? 'array';
                        $newContent = $mine['content'] ?? $typeContent($newName, $newFormat) ?? "[\n    'name' => 'value',\n]";
                    ?>
                    <details class="mb-2"<?= null !== $mine ? ' open' : '' ?>>
                        <summary class="small">Or describe <code><?= $e($key['key']) ?></code> in a new type file (JSON or PHP array)</summary>
                        <form method="post" action="/api/<?= $e($api) ?>/fix" class="card card-body mt-2">
                            <input type="hidden" name="_token" value="<?= $e($token) ?>">
                            <input type="hidden" name="kind" value="<?= $e($note['fix']['kind']) ?>">
                            <input type="hidden" name="key" value="<?= $e($key['key']) ?>">
                            <input type="hidden" name="file" value="<?= $e((string) $key['file']) ?>">
                            <input type="hidden" name="choice" value="new">
                            <div class="row g-2 align-items-center mb-2">
                                <div class="col-auto"><label class="form-label mb-0 small" for="new-name-<?= $uid ?>">Name</label></div>
                                <div class="col-auto"><input class="form-control form-control-sm" id="new-name-<?= $uid ?>" name="new_name" value="<?= $e($newName) ?>"></div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="new_format[<?= $uid ?>]" id="nf-array-<?= $uid ?>" value="array"<?= 'json' !== $newFormat ? ' checked' : '' ?>><label class="form-check-label small" for="nf-array-<?= $uid ?>">PHP array</label></div>
                                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="new_format[<?= $uid ?>]" id="nf-json-<?= $uid ?>" value="json"<?= 'json' === $newFormat ? ' checked' : '' ?>><label class="form-check-label small" for="nf-json-<?= $uid ?>">JSON</label></div>
                                </div>
                                <div class="col-auto small text-secondary">saved as <code>&lt;name&gt;.array</code> or <code>&lt;name&gt;-type.json</code></div>
                            </div>
                            <textarea class="form-control font-monospace mb-1" name="new_content" rows="6" data-af-code="<?= 'json' === $newFormat ? 'json' : 'array' ?>" data-af-mode-from="new_format[<?= $uid ?>]" data-af-modes="json:json,array:array"><?= $e($newContent) ?></textarea>
                            <div class="form-text mb-2"><?= 'list' === $note['fix']['kind'] ? 'One item of the list (not the list itself)' : 'The object' ?>: an example value for each field, as the API sends it (a text in quotes, a number, <code>true</code>, a nested array for an object).</div>
                            <?php if ([] !== $fields): ?>
                            <div class="small text-secondary mb-2">The docs describe these fields inside <code><?= $e($key['key']) ?></code>: <span class="font-monospace"><?= $e(implode(', ', $fields)) ?></span></div>
                            <?php endif ?>
                            <div><button class="btn btn-sm btn-primary">Save the type, use it here and import</button> <span class="small text-secondary">(or Ctrl/Cmd-S in the box)</span>
                                <span class="small text-secondary ms-2">writes the type file, puts its name in <?= $e($role($key['file'])) ?> (<code><?= $e($key['file']) ?></code>) in place of the empty value, then imports again</span></div>
                        </form>
                    </details>
                    <?php endif ?>
                <?php endforeach ?>
                </div>
            <?php endif ?>

            <div class="d-flex align-items-center gap-3 mt-3">
                <form method="post" action="/api/<?= $e($api) ?>/notes">
                    <input type="hidden" name="_token" value="<?= $e($token) ?>">
                    <input type="hidden" name="notes[]" value="<?= $e($note['note']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Nothing to change: delete this note">Accept</button>
                </form>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="notes[]" id="n<?= $i ?>" value="<?= $e($note['note']) ?>" form="notes-delete">
                    <label class="form-check-label small text-secondary" for="n<?= $i ?>">select, to accept several at once (button at the bottom)</label>
                </div>
            </div>

            <?php if ([] !== $note['examples']): ?>
                <div class="small text-secondary mt-3">Open to edit:</div>
                <ul class="list-group list-group-flush small">
                <?php foreach ($note['examples'] as $link): ?>
                    <li class="list-group-item px-0 py-1"><a href="<?= $e($link['url']) ?>" data-af-open><code><?= $e($link['label']) ?></code></a>
                        <span class="text-secondary"><?= $e($link['role']) ?></span></li>
                <?php endforeach ?>
                </ul>
            <?php endif ?>
            <?php if (null !== $note['descriptor']): ?>
                <details class="small mt-2">
                    <summary class="text-secondary">Advanced: the descriptor</summary>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item px-0 py-1"><a href="<?= $e($note['descriptor']['url']) ?>" data-af-open><code><?= $e($note['descriptor']['label']) ?></code></a>
                            <span class="text-secondary">written by the importer: the next import of the example replaces what you change here.</span></li>
                    </ul>
                </details>
            <?php endif ?>
                <details class="small mt-1"><summary class="text-secondary">Raw note</summary><span class="text-secondary"><?= $e($note['shown']) ?></span></details>
            </div>
        </details>
    <?php endforeach ?>
    </div>
    </section>
    <?php endforeach ?>
    <p class="text-secondary d-none" data-af-none>All the kinds are hidden: click one above, or <em>Show all</em>.</p>
    <form id="notes-delete" method="post" action="/api/<?= $e($api) ?>/notes">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <button class="btn btn-primary">Accept the selected notes</button>
        <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
    </form>
</div>
<?php endif ?>
<script src="/assets/notes.js"></script>
