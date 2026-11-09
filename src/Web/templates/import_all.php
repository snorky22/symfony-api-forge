<h1 class="h3 mb-3">Imported <small class="text-secondary"><?= $e($api) ?></small></h1>

<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><strong>Not imported:</strong><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if ([] !== $results): ?>
    <ul class="list-group mb-3">
    <?php foreach ($results as [$name, $result]): ?>
        <li class="list-group-item d-flex justify-content-between">
            <span><code><?= $e($name) ?></code> → <code><?= $e($result->resource) ?>.<?= $e($result->operation) ?></code> <span class="text-secondary small"><?= $e($result->endpoint) ?></span></span>
            <span class="badge <?= [] === $result->review ? 'text-bg-success' : 'text-bg-warning' ?>"><?= count($result->review) ?> note(s)</span>
        </li>
    <?php endforeach ?>
    </ul>
<?php endif ?>

<a class="btn btn-primary" href="/api/<?= $e($api) ?>/notes">Review the notes</a>
<a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
