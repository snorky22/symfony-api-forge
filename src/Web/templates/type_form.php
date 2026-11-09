<h1 class="h3 mb-1">Add a type file <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">The docs often describe a type once and point to it ("see the Duration object"). Save it here, then use its file name
(<code>duration-type.json</code>) as the value of the field in an example. See docs/workflow.md, <em>Pointing to other data files</em>.</p>

<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<form method="post" action="/api/<?= $e($api) ?>/type" class="card card-body">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label" for="name">Name of the type</label>
            <input class="form-control" id="name" name="name" value="<?= $e($values['name']) ?>" placeholder="duration" required>
            <div class="form-text">Saved as <code>&lt;name&gt;-type.json</code> (or <code>.array</code>).</div>
        </div>
        <div class="col-md-8">
            <span class="form-label d-block">Format</span>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="format" id="f-json" value="json"<?= 'json' === $values['format'] ? ' checked' : '' ?>><label class="form-check-label" for="f-json">JSON</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="format" id="f-array" value="array"<?= 'array' === $values['format'] ? ' checked' : '' ?>><label class="form-check-label" for="f-array">an array</label></div>
        </div>
    </div>
    <textarea class="form-control font-monospace mb-3" name="content" rows="8" data-af-code="json" data-af-mode-from="format" data-af-modes="json:json,array:array" placeholder='{"interval": "month", "frequency": 1}' required><?= $e($values['content']) ?></textarea>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="overwrite" id="overwrite" value="1"><label class="form-check-label" for="overwrite">Replace the file if it exists</label></div>
    <div><button class="btn btn-primary">Save</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Cancel</a></div>
</form>
