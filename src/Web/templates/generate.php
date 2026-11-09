<h1 class="h3 mb-1">Generate the code <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">Writes the services, the doctor and the HTTP client configuration from the descriptors. Generated files are never edited by hand:
fix the examples and generate again.</p>

<?php if (null === $result): ?>
    <?php if ([] !== $progress['errors']): ?>
        <div class="alert alert-danger"><strong>The descriptors are invalid:</strong><ul class="mb-0"><?php foreach (array_slice($progress['errors'], 0, 8) as $error): ?><li><?= $e(str_replace($projectDir.'/', '', $error)) ?></li><?php endforeach ?></ul></div>
    <?php elseif ([] !== $progress['review']): ?>
        <div class="alert alert-warning"><?= count($progress['review']) ?> review note(s) left: you can generate anyway, <a href="/api/<?= $e($api) ?>/notes">or review them</a>.</div>
    <?php endif ?>
    <p><?= $progress['operations'] ?> operation(s); <?= count($progress['stale']) ?> generated file(s) out of date.</p>
    <form method="post" action="/api/<?= $e($api) ?>/generate">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <button class="btn btn-primary">Generate</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Back</a>
    </form>
<?php elseif (!$result->isValid()): ?>
    <div class="alert alert-danger"><strong>The descriptors are invalid:</strong><ul class="mb-0"><?php foreach ($result->errors as $error): ?><li><?= $e(str_replace($projectDir.'/', '', $error)) ?></li><?php endforeach ?></ul></div>
    <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back</a>
<?php else: ?>
    <?php if ([] !== $result->review): ?><div class="alert alert-warning"><?= count($result->review) ?> review note(s) left in the descriptors.</div><?php endif ?>
    <?php if ($result->isUpToDate()): ?>
        <div class="alert alert-success">The <?= $result->files ?> generated files are up to date.</div>
    <?php else: ?>
        <div class="alert alert-success"><?= count($result->changes) ?> file(s) changed.</div>
        <table class="table table-sm"><thead><tr><th>Change</th><th>File</th></tr></thead><tbody>
        <?php foreach ($result->changes as [$change, $file]): ?><tr><td><?= $e($change) ?></td><td><code><?= $e($file) ?></code></td></tr><?php endforeach ?>
        </tbody></table>
    <?php endif ?>
    <a class="btn btn-primary" href="/api/<?= $e($api) ?>/doctor">Check with the doctor</a>
    <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
<?php endif ?>
