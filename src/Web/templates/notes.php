<h1 class="h3 mb-1">Review the notes <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">What the importer had to guess, or could not align. Fix the <em>example</em> the note points to and import it again;
delete the notes you accept (they are removed from the descriptors).</p>

<?php if (null !== $message): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif ?>
<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><strong>The descriptors are invalid:</strong><ul class="mb-0"><?php foreach (array_slice($errors, 0, 8) as $error): ?><li><?= $e(str_replace($projectDir.'/', '', $error)) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if ([] === $notes && [] === $errors): ?>
    <div class="alert alert-success">No note left.</div>
    <a class="btn btn-primary" href="/api/<?= $e($api) ?>/generate">Generate the code</a>
<?php elseif ([] !== $notes): ?>
<form method="post" action="/api/<?= $e($api) ?>/notes">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <ul class="list-group mb-3">
    <?php foreach ($notes as $i => $note): ?>
        <li class="list-group-item">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="notes[]" id="n<?= $i ?>" value="<?= $e($note['note']) ?>">
                <label class="form-check-label" for="n<?= $i ?>"><?= $e($note['shown']) ?></label>
            </div>
            <?php if (null !== $note['advice']): ?><div class="small text-secondary ms-4 mt-1"><?= $e($note['advice']) ?></div><?php endif ?>
            <?php if ([] !== $note['links']): ?>
                <div class="small ms-4 mt-1">Edit:
                <?php foreach ($note['links'] as $link): ?><a class="me-2" href="<?= $e($link['url']) ?>" data-af-open><code><?= $e($link['label']) ?></code></a><?php endforeach ?>
                </div>
            <?php endif ?>
        </li>
    <?php endforeach ?>
    </ul>
    <button class="btn btn-primary">Delete the checked notes</button>
    <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
</form>
<?php endif ?>
