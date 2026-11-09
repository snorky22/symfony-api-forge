<h1 class="h3 mb-1">Check with the doctor <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">Calls the API's probe with your key and checks the credentials, the response against the descriptors, and (for APIs with webhooks)
the webhook chain. Manual part: put the API key in <code>.env.local</code>, under the variable of the API's authentication.</p>

<?php if (null === $result): ?>
    <form method="post" action="/api/<?= $e($api) ?>/doctor">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="key" required><label class="form-check-label" for="key">I put the API key in <code>.env.local</code></label></div>
        <button class="btn btn-primary">Run the doctor</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Back</a>
    </form>
<?php else: ?>
    <div class="alert <?= 0 === $result['code'] ? 'alert-success' : 'alert-warning' ?>"><?= 0 === $result['code'] ? 'The doctor found no problem.' : 'The doctor found problems (exit code '.$e($result['code']).').' ?></div>
    <pre class="bg-dark text-light p-3 rounded small"><?= $e($result['output']) ?></pre>
    <a class="btn btn-outline-primary" href="/api/<?= $e($api) ?>/doctor">Run it again</a>
    <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
<?php endif ?>
