<h1 class="h3 mb-1">Import <small class="text-secondary"><code><?= $e($method) ?></code> <?= $e($url) ?></small></h1>

<?= $partial('endpoint_steps') ?>

<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if ([] !== $files): ?>
<ul class="list-group mb-3">
<?php foreach ($files as $file): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span><span class="badge <?= $file['present'] ? 'text-bg-success' : 'text-bg-light border' ?>"><?= $file['present'] ? 'saved' : 'none' ?></span>
            <code><?= $e($file['name']) ?></code> <span class="text-secondary small"><?= $e($file['role']) ?></span></span>
        <?php if ($file['present']): ?><a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file['name'])) ?>&amp;area=examples" data-af-open>Edit</a><?php endif ?>
    </li>
<?php endforeach ?>
</ul>

<form method="post" action="/api/<?= $e($api) ?>/endpoint" class="mb-3">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <input type="hidden" name="base" value="<?= $e($base) ?>">
    <input type="hidden" name="step" value="import">
    <button class="btn btn-primary">Import the endpoint</button>
    <a class="btn btn-link" href="/api/<?= $e($api) ?>/endpoint?base=<?= $e($base) ?>&amp;step=response">Back</a>
    <div class="form-text">Writes the descriptor from these files, and adds the variants of the endpoint after it. The notes it leaves are fixed on the next page.</div>
</form>
<?php endif ?>
