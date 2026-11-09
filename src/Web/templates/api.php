<h1 class="h3 mb-3"><?= $e($api) ?></h1>

<?php if ([] !== $progress['errors']): ?>
    <div class="alert alert-danger">
        <strong>The descriptors are invalid:</strong>
        <ul class="mb-0"><?php foreach (array_slice($progress['errors'], 0, 8) as $error): ?><li><?= $e(str_replace($projectDir.'/', '', $error)) ?></li><?php endforeach ?></ul>
    </div>
<?php endif ?>

<ol class="list-group list-group-numbered mb-4">
<?php foreach ($steps as $step): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center <?= $step['current'] ? 'list-group-item-warning' : '' ?>">
        <div class="ms-2 me-auto">
            <div class="fw-semibold"><?= $e(preg_replace('/^\d+\. /', '', $step['title'])) ?>
                <span class="badge <?= $step['done'] ? 'text-bg-success' : ($step['current'] ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= $step['done'] ? 'done' : ($step['current'] ? 'next' : 'to do') ?></span>
            </div>
            <span class="small text-secondary"><?= $e($step['detail']) ?></span>
        </div>
        <a class="btn btn-sm <?= $step['current'] ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= $e('/new' === $step['url'] ? '/api/'.$api : $step['url']) ?>"><?= $e($step['action']) ?></a>
    </li>
<?php endforeach ?>
</ol>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h5 mb-0">Examples <span class="text-secondary small"><code>tests/Fixtures/<?= $e($api) ?>/</code></span></h2>
    <div><a class="btn btn-sm btn-outline-primary" href="/api/<?= $e($api) ?>/example">Add an example</a>
        <a class="btn btn-sm btn-outline-primary" href="/api/<?= $e($api) ?>/type">Add a type file</a></div>
</div>
<?php if ([] === $files): ?>
    <p class="text-secondary">No example yet.</p>
<?php else: ?>
    <ul class="list-group">
    <?php foreach ($files as $file): ?>
        <li class="list-group-item d-flex justify-content-between">
            <code><?= $e($file) ?></code>
            <a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=examples" data-af-open>Edit</a>
        </li>
    <?php endforeach ?>
    </ul>
<?php endif ?>

<h2 class="h5 mt-4 mb-2">Descriptors <span class="text-secondary small"><code>config/api/<?= $e($api) ?>/</code></span></h2>
<?php if ([] === $descriptors): ?>
    <p class="text-secondary">None yet.</p>
<?php else: ?>
    <ul class="list-group">
    <?php foreach ($descriptors as $file): ?>
        <li class="list-group-item d-flex justify-content-between">
            <code><?= $e($file) ?></code>
            <a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=descriptors" data-af-open>Edit</a>
        </li>
    <?php endforeach ?>
    </ul>
    <div class="form-text">Written by the importer: improving the examples and importing again is usually better than editing a descriptor.</div>
<?php endif ?>
