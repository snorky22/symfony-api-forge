<h1 class="h3 mb-3">Imported <small class="text-secondary"><?= $e($api) ?></small></h1>

<?php if (null !== $named && $named !== $result->operation.'-'.$result->resource): ?>
    <div class="alert alert-warning">The files are named <code><?= $e($named) ?></code>, but the request is the <strong><?= $e($result->operation) ?></strong> operation of
    <strong><?= $e($result->resource) ?></strong>. api-forge reads the request, not the name; rename the files to keep the convention.</div>
<?php endif ?>

<div class="card mb-3"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-3">Operation</dt><dd class="col-sm-9"><code><?= $e($result->resource) ?>.<?= $e($result->operation) ?></code></dd>
        <dt class="col-sm-3">Endpoint</dt><dd class="col-sm-9 mb-0"><code><?= $e($result->endpoint) ?></code></dd>
    </dl>
    <?php if ([] !== $result->notes): ?><ul class="mt-2 mb-0"><?php foreach ($result->notes as $note): ?><li><?= $e($note) ?></li><?php endforeach ?></ul><?php endif ?>
</div></div>

<div class="mb-3">
    <div class="mb-3">
        <h2 class="h6">Examples written</h2>
        <ul class="list-group">
        <?php foreach ($files as $file): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"><code><?= $e($file) ?></code>
                <a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=examples" data-af-open>Edit</a></li>
        <?php endforeach ?>
        </ul>
    </div>
    <div>
        <h2 class="h6">Descriptors</h2>
        <ul class="list-group">
        <?php foreach ($descriptors as $file): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"><code><?= $e($file) ?></code>
                <a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=descriptors" data-af-open>Edit</a></li>
        <?php endforeach ?>
        </ul>
    </div>
</div>

<?php if ([] !== $result->review): ?>
    <div class="alert alert-warning">
        <strong><?= count($result->review) ?> note(s) to review</strong> (the importer guessed):
        <ul class="mb-0"><?php foreach ($result->review as $note): ?><li><?= $e($note) ?></li><?php endforeach ?></ul>
    </div>
<?php endif ?>

<a class="btn btn-primary" href="/api/<?= $e($api) ?>/notes">Review the notes</a>
<a class="btn btn-outline-primary" href="/api/<?= $e($api) ?>/example">Add another example</a>
<a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
