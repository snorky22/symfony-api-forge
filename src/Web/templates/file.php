<h1 class="h3 mb-3"><code><?= $e($name) ?></code> <small class="text-secondary"><?= $e($api) ?></small></h1>

<?= $partial('editor_panel') ?>

<?php if ($isExample): ?>
<form method="post" action="/api/<?= $e($api) ?>/import" class="card card-body mb-3">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <input type="hidden" name="name" value="<?= $e($name) ?>">
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="force" id="force" value="1" checked><label class="form-check-label" for="force">Replace the operation in the descriptors (<code>--force</code>)</label></div>
    <div class="form-text mb-2">Imports this example again, with its response and docs page if they sit next to it. Save first.</div>
    <div><button class="btn btn-outline-primary">Import again</button></div>
</form>
<?php endif ?>

<a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
