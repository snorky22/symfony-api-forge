<h1 class="h3 mb-3"><?= $e($api) ?></h1>

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
<?php if ([] !== $problems): ?>
    <div class="alert alert-danger" id="problems">
        <strong>A descriptor is invalid:</strong>
        <ul class="mb-0 mt-1">
        <?php foreach ($problems as $problem): ?>
            <li class="mb-2"><?= $e($problem['error']) ?>
                <?php foreach ($problem['examples'] as $example): ?>
                    <form method="post" action="/api/<?= $e($api) ?>/import" class="mt-1">
                        <input type="hidden" name="_token" value="<?= $e($token) ?>">
                        <input type="hidden" name="name" value="<?= $e($example) ?>">
                        <input type="hidden" name="force" value="1">
                        <input type="hidden" name="family" value="1">
                        <button class="btn btn-sm btn-danger">Import <?= $e($example) ?> again</button>
                        <span class="small">replaces the descriptor from the example</span>
                    </form>
                <?php endforeach ?>
            </li>
        <?php endforeach ?>
        </ul>
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

<div class="card mb-4" id="webhooks"><div class="card-body d-flex justify-content-between align-items-center">
    <div>
        <h2 class="h6 mb-1">Webhooks
            <?php if ($webhooks['described']): ?>
                <span class="badge <?= $webhooks['typed'] === $webhooks['total'] ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $webhooks['typed'] ?> of <?= $webhooks['total'] ?> events typed</span>
                <?php if ($webhooks['notes'] > 0): ?><span class="badge text-bg-warning"><?= $webhooks['notes'] ?> note(s)</span><?php endif ?>
            <?php else: ?><span class="badge text-bg-secondary">none</span><?php endif ?>
        </h2>
        <span class="small text-secondary"><?= $webhooks['described'] ? 'received on /webhook/'.$e($api).($webhooks['secret'] ? ', secret in '.$e($webhooks['secret']) : '') : 'if the API sends events: add a payload example and its docs page' ?></span>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="/api/<?= $e($api) ?>/webhooks"><?= $webhooks['described'] ? 'Webhooks' : 'Add webhooks' ?></a>
</div></div>

<?php if ([] !== $endpoints): ?>
<h2 class="h5 mb-2">Endpoints</h2>
<ul class="list-group mb-4">
<?php foreach ($endpoints as $endpoint): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span><code><?= $e($endpoint['method']) ?></code> <?= $e($endpoint['path']) ?>
            <?php if (!$endpoint['response']): ?><span class="badge text-bg-warning">no response yet</span><?php endif ?>
            <?php if (false === $endpoint['imported']): ?><span class="badge text-bg-warning">not imported</span><?php endif ?>
            <span class="text-secondary small"><?= $e($endpoint['base']) ?></span></span>
        <span>
            <a href="/api/<?= $e($api) ?>/endpoint?base=<?= $e($endpoint['base']) ?>&amp;step=request">Edit</a>
            <?php if (!$endpoint['response'] || false === $endpoint['imported']): ?>
                · <a href="/api/<?= $e($api) ?>/endpoint?base=<?= $e($endpoint['base']) ?>&amp;step=<?= $endpoint['response'] ? 'import' : 'response' ?>">Continue</a>
            <?php endif ?>
        </span>
    </li>
<?php endforeach ?>
</ul>
<?php endif ?>

<div class="d-flex justify-content-between align-items-center mb-2" id="examples">
    <h2 class="h5 mb-0">Files <span class="text-secondary small"><code>tests/Fixtures/<?= $e($api) ?>/</code></span></h2>
    <div><a class="btn btn-sm btn-primary" href="/api/<?= $e($api) ?>/endpoint">New endpoint</a>
        <a class="btn btn-sm btn-outline-primary" href="/api/<?= $e($api) ?>/type">Add a type file</a>
        <a class="btn btn-sm btn-link" href="/api/<?= $e($api) ?>/example">Add an example (advanced)</a></div>
</div>
<?php if ([] !== $notImported): ?>
    <form method="post" action="/api/<?= $e($api) ?>/import-all" class="alert alert-warning d-flex justify-content-between align-items-center py-2">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <span><?= count($notImported) ?> example(s) not imported yet: <?= $e(implode(', ', $notImported)) ?>.</span>
        <button class="btn btn-sm btn-primary">Import them</button>
    </form>
<?php endif ?>
<?php if ([] === $files): ?>
    <p class="text-secondary">No example yet.</p>
<?php else: ?>
    <ul class="list-group">
    <?php foreach ($files as $file): ?>
        <li class="list-group-item d-flex justify-content-between">
            <span><code><?= $e($file) ?></code><?php if (in_array($file, $notImported, true)): ?> <span class="badge text-bg-warning">not imported</span><?php endif ?></span>
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
