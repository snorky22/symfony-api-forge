<h1 class="h3 mb-1">Check with the doctor <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">Calls the API's probe with your key and checks the credentials, the response against the descriptors, and (for APIs with webhooks)
the webhook chain.</p>

<?php if (null === $result): ?>
    <div class="card card-body mb-3">
        <p class="mb-2">The button runs this command for you; you do not need a terminal:</p>
        <pre class="bg-body-secondary p-2 rounded mb-2"><code><?= $e($command) ?></code></pre>
        <?php if (null !== $keyEnv): ?>
            <p class="mb-0 small">It reads the API key from <code><?= $e($keyEnv) ?></code><?php if (null !== $hostEnv): ?> and the host (sandbox or live) from <code><?= $e($hostEnv) ?></code><?php endif ?>:
            set them in <code>.env.local</code> first. If one is empty, the doctor says so.</p>
        <?php else: ?>
            <p class="mb-0 small">The API has no authentication in its descriptor.</p>
        <?php endif ?>
    </div>
    <form method="post" action="/api/<?= $e($api) ?>/doctor">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <button class="btn btn-primary">Run the doctor</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>">Back</a>
    </form>
<?php else: ?>
    <div class="alert <?= 0 === $result['code'] ? 'alert-success' : 'alert-warning' ?>"><?= 0 === $result['code'] ? 'The doctor found no problem.' : 'The doctor found problems (exit code '.$e($result['code']).').' ?></div>
    <?php if (null !== $hint): ?><div class="alert alert-info"><strong>Why:</strong> <?= $e($hint) ?></div><?php endif ?>
    <pre class="bg-dark text-light p-3 rounded small"><?= $e($result['output']) ?></pre>
    <a class="btn btn-outline-primary" href="/api/<?= $e($api) ?>/doctor">Run it again</a>
    <a class="btn btn-outline-secondary" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
<?php endif ?>
