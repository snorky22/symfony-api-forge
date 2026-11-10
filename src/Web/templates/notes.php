<h1 class="h3 mb-1">Review the notes <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">What the importer had to guess, or could not align. Most notes have a <strong>Fix</strong> form: the page writes the fix in your
example and imports it again. Tick the notes you accept and delete them (they are removed from the descriptors).</p>

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
        <details class="accordion-item" id="note-<?= $i ?>" data-af-kind="<?= $e($kinds[$note['kind']['label']]['slug']) ?>">
            <summary class="accordion-button py-2">
                <span class="badge text-bg-<?= $e($note['kind']['colour']) ?> me-2 flex-shrink-0"><?= $e($note['kind']['label']) ?></span>
                <span><?= $highlight($note['guide']['title']) ?></span>
            </summary>
            <div class="accordion-body">
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

            <?php if (null !== $note['fix']): ?>
                <div class="mt-3">
                <?php foreach ($note['fix']['keys'] as $j => $key): ?>
                    <form method="post" action="/api/<?= $e($api) ?>/fix" class="row g-2 align-items-center mb-2">
                        <input type="hidden" name="_token" value="<?= $e($token) ?>">
                        <input type="hidden" name="kind" value="<?= $e($note['fix']['kind']) ?>">
                        <input type="hidden" name="key" value="<?= $e($key['key']) ?>">
                        <input type="hidden" name="file" value="<?= $e((string) $key['file']) ?>">
                        <?php if ('free' === $note['fix']['kind']): ?>
                            <input type="hidden" name="choice" value="freeform">
                            <div class="col-auto"><code><?= $e($key['path']) ?></code></div>
                            <?php if (null === $key['file']): ?>
                                <div class="col-auto small text-secondary">not found in an example: edit the examples below.</div>
                            <?php else: ?>
                                <div class="col-auto"><button class="btn btn-sm btn-primary">Make it free-form</button></div>
                                <div class="col-auto small text-secondary">replaces its value with <code>{}</code> in <code><?= $e($key['file']) ?></code>, then imports it again</div>
                            <?php endif ?>
                        <?php else: ?>
                        <div class="col-auto"><code><?= $e($key['path']) ?></code> is</div>
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
                                    <option value="int">a number</option>
                                    <option value="bool">yes / no</option>
                                    <option value="object">a free-form object</option>
                                    <option value="list-string">a list of text</option>
                                    <option value="list-int">a list of numbers</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-primary">Fix</button></div>
                        <div class="col-auto small text-secondary">in <code><?= $e($key['file']) ?></code>, then imports it again</div>
                        <?php endif ?>
                        <?php endif ?>
                    </form>
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
