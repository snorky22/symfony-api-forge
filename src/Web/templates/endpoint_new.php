<h1 class="h3 mb-1">New endpoint <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">One screen per part: what it is (method and path), its request, its response, then the import. Each screen saves its files,
so you can stop and continue the endpoint later from the dashboard.</p>

<?= $partial('endpoint_steps') ?>

<?php if (!$described): ?>
    <div class="alert alert-info">The API has no base URI yet: write the full URL below (<code>https://api.example.com/products</code>), or <a href="/new">describe the API</a> first.</div>
<?php endif ?>
<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if (null !== $exists): ?>
    <div class="alert alert-warning">
        <strong>This endpoint exists already</strong> (<code><?= $e($exists) ?>.curl</code>). You can edit it, or replace it with the method and path below.
        <div class="mt-2">
            <a class="btn btn-sm btn-primary" href="/api/<?= $e($api) ?>/endpoint?base=<?= $e($exists) ?>&amp;step=request">Edit the existing endpoint</a>
            <form method="post" action="/api/<?= $e($api) ?>/endpoint" class="d-inline">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="method" value="<?= $e($values['method']) ?>">
                <input type="hidden" name="path" value="<?= $e($values['path']) ?>">
                <input type="hidden" name="variant" value="<?= $e($values['variant']) ?>">
                <input type="hidden" name="replace" value="1">
                <button class="btn btn-sm btn-outline-danger">Replace it</button>
            </form>
        </div>
        <div class="small mt-2">Replacing rewrites its request (<code><?= $e($exists) ?>.curl</code>); its body, response and docs pages are kept and shown on the next screens, to change or to keep.</div>
    </div>
<?php endif ?>

<form method="post" action="/api/<?= $e($api) ?>/endpoint" class="card card-body">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <div class="mb-3">
        <span class="form-label d-block">Method</span>
        <?php foreach ($methods as $method): ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="method" id="m-<?= $e($method) ?>" value="<?= $e($method) ?>"<?= $method === $values['method'] ? ' checked' : '' ?>><label class="form-check-label font-monospace" for="m-<?= $e($method) ?>"><?= $e($method) ?></label></div>
        <?php endforeach ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="path">Path</label>
        <input class="form-control font-monospace" id="path" name="path" value="<?= $e($values['path']) ?>" placeholder="/products/{id}" required>
        <div class="form-text">As in the docs: <code>/products</code>, <code>/products/{id}</code>, <code>/subscriptions/{id}/cancel</code>. The operation (<code>list</code>, <code>get</code>, <code>create</code>…) and the resource come from the method and the path.</div>
    </div>
    <details class="mb-3">
        <summary class="text-secondary small">Advanced: a second example of the same endpoint</summary>
        <label class="form-label mt-2" for="variant">Variant</label>
        <input class="form-control" id="variant" name="variant" value="<?= $e($values['variant']) ?>" placeholder="include">
        <div class="form-text">Adds a suffix to the names (<code>list-prices-include.curl</code>): the second example adds its query parameters to the endpoint.</div>
    </details>
    <div><button class="btn btn-primary">Continue</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Cancel</a></div>
</form>
