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
<div data-af-notes>
    <ul class="list-group mb-3">
    <?php foreach ($notes as $i => $note): ?>
        <li class="list-group-item">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="notes[]" id="n<?= $i ?>" value="<?= $e($note['note']) ?>" form="notes-delete">
                <label class="form-check-label" for="n<?= $i ?>"><?= $e($note['shown']) ?></label>
            </div>
            <?php if (null !== $note['explained']): ?><div class="ms-4 mt-1"><?= $e($note['explained']) ?></div>
            <?php elseif (null !== $note['advice']): ?><div class="small text-secondary ms-4 mt-1"><?= $e($note['advice']) ?></div><?php endif ?>
            <form method="post" action="/api/<?= $e($api) ?>/notes" class="ms-4 mt-2">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="notes[]" value="<?= $e($note['note']) ?>">
                <button class="btn btn-sm btn-outline-success" title="Nothing to change: delete this note">Accept</button>
            </form>

            <?php if (null !== $note['fix']): ?>
                <div class="ms-4 mt-2">
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

            <?php if ([] !== $note['examples']): ?>
                <div class="small text-secondary ms-4 mt-2">Open to edit:</div>
                <ul class="list-group list-group-flush small ms-4">
                <?php foreach ($note['examples'] as $link): ?>
                    <li class="list-group-item px-0 py-1"><a href="<?= $e($link['url']) ?>" data-af-open><code><?= $e($link['label']) ?></code></a>
                        <span class="text-secondary"><?= $e($link['role']) ?></span></li>
                <?php endforeach ?>
                </ul>
            <?php endif ?>
            <?php if (null !== $note['descriptor']): ?>
                <details class="small ms-4 mt-1">
                    <summary class="text-secondary">Advanced: the descriptor</summary>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item px-0 py-1"><a href="<?= $e($note['descriptor']['url']) ?>" data-af-open><code><?= $e($note['descriptor']['label']) ?></code></a>
                            <span class="text-secondary">written by the importer: the next import of the example replaces what you change here.</span></li>
                    </ul>
                </details>
            <?php endif ?>
        </li>
    <?php endforeach ?>
    </ul>
    <form id="notes-delete" method="post" action="/api/<?= $e($api) ?>/notes">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <button class="btn btn-primary">Delete the checked notes</button>
        <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
    </form>
</div>
<?php endif ?>
