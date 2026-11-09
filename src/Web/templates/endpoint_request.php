<h1 class="h3 mb-1">Request <small class="text-secondary"><code><?= $e($method) ?></code> <?= $e($url) ?></small></h1>
<p class="text-secondary">Files: <code><?= $e($base) ?>.curl</code><?php if ($hasBody): ?>, <code><?= $e($base) ?>.body.json</code><?php endif ?>.
<a href="/api/<?= $e($api) ?>/file?name=<?= $e($base) ?>.curl&amp;area=examples" data-af-open>Edit the cURL command</a></p>

<?= $partial('endpoint_steps') ?>

<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<form method="post" action="/api/<?= $e($api) ?>/endpoint" class="card card-body">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <input type="hidden" name="base" value="<?= $e($base) ?>">
    <input type="hidden" name="step" value="request">

    <label class="form-label" for="query">Query parameters <span class="text-secondary">(optional)</span></label>
    <textarea class="form-control font-monospace mb-1" id="query" name="query" rows="3" data-af-code="text" placeholder="include=product&#10;status=active"><?= $e($values['query']) ?></textarea>
    <div class="form-text mb-3">One <code>name=value</code> per line: an example value of each parameter the docs list. Values are typed from them; the ones the docs do not require stay optional.</div>

    <?php if ($hasBody): ?>
        <label class="form-label" for="body">Body, as JSON</label>
        <textarea class="form-control font-monospace mb-1" id="body" name="body" rows="8" data-af-code="json" placeholder='{"name": "Analytics", "tax_category": "standard"}'><?= $e($values['body']) ?></textarea>
        <div class="form-text mb-3">What you send. Saved as <code><?= $e($base) ?>.body.json</code>.</div>

        <label class="form-label" for="body_docs">The docs page of the body, as text <span class="text-secondary">(optional)</span></label>
        <textarea class="form-control font-monospace mb-1" id="body_docs" name="body_docs" rows="4" data-af-code="text" placeholder="namestringrequired&#10;..."><?= $e($values['body_docs']) ?></textarea>
        <div class="form-text mb-3">The parameters of the body, copied: it tells which keys are required, the others become optional.</div>
    <?php endif ?>

    <div><button class="btn btn-primary">Save and continue</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Cancel</a></div>
</form>
