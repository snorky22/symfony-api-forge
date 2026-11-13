<h1 class="h3 mb-1"><?= $edit ? 'Edit the description of '.$e($values['name']) : 'Describe an API' ?></h1>
<p class="text-secondary">Step 1. What the importer would otherwise guess from the first request: where the API is, how it
authenticates. <?php if ($edit): ?>Saving changes <code>config/api/<?= $e($values['name']) ?>/_api.yaml</code>: the generated code and the doctor are then to do again.<?php else: ?>You can still change it in <code>config/api/&lt;name&gt;/_api.yaml</code>.<?php endif ?></p>

<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<form method="post" action="<?= $edit ? '/api/'.$e($values['name']).'/describe' : '/new' ?>" class="card card-body">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" value="<?= $e($values['name']) ?>" placeholder="e.g. shop" required<?= $edit ? ' readonly' : '' ?>>
            <div class="form-text">Lowercase; the folder of its descriptors and examples.</div>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="base_uri">Base URI (live)</label>
            <input class="form-control" id="base_uri" name="base_uri" value="<?= $e($values['base_uri']) ?>" placeholder="e.g. https://api.example.com/" required>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="sandbox_uri">Sandbox base URI <span class="text-secondary">(optional)</span></label>
            <input class="form-control" id="sandbox_uri" name="sandbox_uri" value="<?= $e($values['sandbox_uri']) ?>" placeholder="e.g. https://sandbox-api.example.com/">
            <div class="form-text">Same paths, another host: the generated client picks one from an environment variable.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="base_uri_env">Variable choosing the host</label>
            <input class="form-control" id="base_uri_env" name="base_uri_env" value="<?= $e($values['base_uri_env']) ?>" placeholder="e.g. SHOP_BASE_URI">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="format">Format</label>
            <select class="form-select" id="format" name="format">
                <option value="json"<?= 'json' === $values['format'] ? ' selected' : '' ?>>JSON</option>
                <option value="jsonapi"<?= 'jsonapi' === $values['format'] ? ' selected' : '' ?>>JSON:API</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="auth_type">Authentication</label>
            <select class="form-select" id="auth_type" name="auth_type">
                <?php foreach (['bearer' => 'Bearer token', 'basic' => 'Basic', 'header' => 'Another header'] as $type => $label): ?>
                    <option value="<?= $e($type) ?>"<?= $type === $values['auth_type'] ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="auth_env">Variable of the key</label>
            <input class="form-control" id="auth_env" name="auth_env" value="<?= $e($values['auth_env']) ?>" placeholder="e.g. SHOP_API_KEY">
            <div class="form-text">Its value goes in <code>.env.local</code>, never here.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="auth_header">Header <span class="text-secondary">(other header only)</span></label>
            <input class="form-control" id="auth_header" name="auth_header" value="<?= $e($values['auth_header']) ?>" placeholder="e.g. X-Api-Key">
        </div>
        <div class="col-md-8">
            <label class="form-label" for="headers">Headers sent with every request <span class="text-secondary">(optional)</span></label>
            <textarea class="form-control font-monospace" id="headers" name="headers" rows="2" placeholder="e.g. Shop-Version: 2"><?= $e($values['headers']) ?></textarea>
            <div class="form-text">One <code>Name: value</code> per line.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="namespace">PHP namespace <span class="text-secondary">(optional)</span></label>
            <input class="form-control" id="namespace" name="namespace" value="<?= $e($values['namespace']) ?>" placeholder="e.g. Shop">
            <div class="form-text">Derived from the name when empty.</div>
        </div>
    </div>
    <div class="mt-3"><button class="btn btn-primary"><?= $edit ? 'Save' : 'Create the API' ?></button> <a class="btn btn-link" href="<?= $edit ? '/api/'.$e($values['name']) : '/' ?>">Cancel</a></div>
</form>
