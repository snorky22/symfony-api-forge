<h1 class="h3 mb-1">Add an example <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">Paste what the docs give you. The server names the files after the operation and the resource
(<code>list-prices.curl</code>, <code>list-prices.json</code>…), then imports them.
See <em>Which file for what the docs give you</em> in docs/workflow.md.</p>

<?php if (!$described): ?>
    <div class="alert alert-info">The API is not described yet. The first request must then hold the full URL (the importer reads the base URI from it), or <a href="/new">describe the API</a> first.</div>
<?php endif ?>
<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul>
    <?php if ([] !== $saved): ?>
        <div class="mt-2">Saved files:</div>
        <ul class="list-group list-group-flush">
        <?php foreach ($saved as $file): ?>
            <li class="list-group-item bg-transparent px-0 py-1"><a href="/api/<?= $e($api) ?>/file?name=<?= $e(rawurlencode($file)) ?>&amp;area=examples" data-af-open><code><?= $e($file) ?></code></a></li>
        <?php endforeach ?>
        </ul>
    <?php endif ?>
    </div>
<?php endif ?>

<form method="post" action="/api/<?= $e($api) ?>/example" class="card card-body">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="operation">Operation</label>
            <input class="form-control" id="operation" name="operation" list="operations" value="<?= $e($values['operation']) ?>" placeholder="list" required>
            <datalist id="operations"><?php foreach ($operations as $operation): ?><option value="<?= $e($operation) ?>"><?php endforeach ?></datalist>
            <div class="form-text">Or the action of the docs: <code>refund</code>, <code>cancel</code>.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="resource">Resource</label>
            <input class="form-control" id="resource" name="resource" value="<?= $e($values['resource']) ?>" placeholder="prices" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="variant">Variant <span class="text-secondary">(optional)</span></label>
            <input class="form-control" id="variant" name="variant" value="<?= $e($values['variant']) ?>" placeholder="include">
            <div class="form-text">A second example of the same operation.</div>
        </div>
    </div>

    <hr>
    <div class="mb-2">
        <span class="form-label d-block">Request</span>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="request_format" id="rf-curl" value="curl"<?= 'curl' === $values['request_format'] ? ' checked' : '' ?>><label class="form-check-label" for="rf-curl">a cURL sample</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="request_format" id="rf-array" value="array"<?= 'array' === $values['request_format'] ? ' checked' : '' ?>><label class="form-check-label" for="rf-array">an array, when the docs describe it in words</label></div>
    </div>
    <textarea class="form-control font-monospace mb-1" name="request" rows="5" data-af-code="sh" data-af-mode-from="request_format" data-af-modes="curl:sh,array:array" placeholder="curl https://api.example.com/prices -H 'Authorization: Bearer YOUR_KEY'
or
['request' => 'GET /prices', 'query' => ['include' => 'product']]"><?= $e($values['request']) ?></textarea>
    <div class="form-text mb-3">An array has <code>request</code> (<code>'METHOD /path'</code>), and optionally <code>query</code>, <code>headers</code>, <code>body</code>, <code>response</code>.</div>

    <label class="form-label" for="body">Request body, as JSON <span class="text-secondary">(a POST, PUT or PATCH; optional)</span></label>
    <textarea class="form-control font-monospace mb-1" id="body" name="body" rows="6" data-af-code="json" placeholder='{"name": "Analytics", "tax_category": "standard"}'><?= $e($values['body']) ?></textarea>
    <div class="form-text mb-3">Saved as <code>&lt;operation&gt;-&lt;resource&gt;.body.json</code>, next to the cURL sample. Leave it empty if the sample has <code>-d</code>.</div>

    <div class="mb-2">
        <span class="form-label d-block">Response <span class="text-secondary">(for a cURL sample; optional)</span></span>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="response_format" id="rsp-json" value="json"<?= 'json' === $values['response_format'] ? ' checked' : '' ?>><label class="form-check-label" for="rsp-json">JSON</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="response_format" id="rsp-array" value="array"<?= 'array' === $values['response_format'] ? ' checked' : '' ?>><label class="form-check-label" for="rsp-array">an array</label></div>
    </div>
    <textarea class="form-control font-monospace mb-3" name="response" rows="8" data-af-code="json" data-af-mode-from="response_format" data-af-modes="json:json,array:array" placeholder='{"data": [{"id": "pri_01"}]}'><?= $e($values['response']) ?></textarea>

    <label class="form-label" for="docs">The docs page of the response, as text <span class="text-secondary">(optional)</span></label>
    <textarea class="form-control font-monospace mb-1" id="docs" name="docs" rows="5" data-af-code="text" placeholder="idstringrequired&#10;..."><?= $e($values['docs']) ?></textarea>
    <div class="form-text mb-3">The page's field list, copied: it gives the optional keys, the nulls and the enums.</div>

    <label class="form-label" for="body_docs">The docs page of the request body, as text <span class="text-secondary">(optional)</span></label>
    <textarea class="form-control font-monospace mb-1" id="body_docs" name="body_docs" rows="4" data-af-code="text" placeholder="namestringrequired&#10;..."><?= $e($values['body_docs']) ?></textarea>
    <div class="form-text mb-3">The parameters of the body, copied: it tells which keys are required (the others become optional).</div>

    <div class="form-check"><input class="form-check-input" type="checkbox" name="overwrite" id="overwrite" value="1"<?= '' !== $values['overwrite'] ? ' checked' : '' ?>><label class="form-check-label" for="overwrite">Replace the files if they exist</label></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="force" id="force" value="1"<?= '' !== $values['force'] ? ' checked' : '' ?>><label class="form-check-label" for="force">Replace the operation if the descriptors have it (<code>--force</code>)</label></div>

    <div><button class="btn btn-primary">Save and import</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Cancel</a></div>
</form>
