<h1 class="h3 mb-3">Imported <small class="text-secondary"><code><?= $e($event) ?></code></small></h1>

<div class="card mb-3"><div class="card-body">
    <h2 class="h6">What changed</h2>
    <ul class="mb-0"><?php foreach ($done as $line): ?><li><?= $e($line) ?></li><?php endforeach ?></ul>
</div></div>

<div class="row mb-3">
    <div class="col-md-6">
        <h2 class="h6">Examples written</h2>
        <ul class="list-group">
        <?php foreach ($written as $file): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"><code><?= $e($file) ?></code>
                <a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=examples" data-af-open>Edit</a></li>
        <?php endforeach ?>
        </ul>
    </div>
    <div class="col-md-6">
        <h2 class="h6">Descriptors</h2>
        <ul class="list-group">
        <?php foreach ($descriptors as $file): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"><code><?= $e($file) ?></code>
                <a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=descriptors" data-af-open>Edit</a></li>
        <?php endforeach ?>
        </ul>
    </div>
</div>

<?php if ([] !== $eventNotes): ?>
    <div class="alert alert-warning">
        <strong><?= count($eventNotes) ?> note(s) on <code><?= $e($event) ?></code></strong>: what the example and its docs page could not settle.
        <ul class="mb-0 mt-1"><?php foreach ($eventNotes as $note): ?><li><?= $e($note) ?></li><?php endforeach ?></ul>
    </div>
<?php else: ?>
    <div class="alert alert-success">No note on <code><?= $e($event) ?></code>.</div>
<?php endif ?>
<?php if ($otherNotes > 0): ?><p class="text-secondary small"><?= $otherNotes ?> other webhook note(s) in <code>_webhooks.yaml</code>.</p><?php endif ?>

<p><?= $state['typed'] ?> of <?= $state['total'] ?> events typed.</p>
<a class="btn btn-primary" href="/api/<?= $e($api) ?>/notes">Review the notes</a>
<a class="btn btn-outline-primary" href="/api/<?= $e($api) ?>/generate">Generate</a>
<a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>/webhooks">Back to the webhooks</a>
