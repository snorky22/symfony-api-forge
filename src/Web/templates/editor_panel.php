<div class="af-panel card mb-3" data-af-panel
     data-url="/api/<?= $e($api) ?>/file" data-area="<?= $e($area) ?>" data-name="<?= $e($name) ?>"
     data-mode="<?= $e($mode) ?>" data-line="<?= $e($line) ?>" data-import="<?= $e($importTarget ?? '') ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><code><?= $e('descriptors' === $area ? 'config/api/'.$api : 'tests/Fixtures/'.$api) ?>/<?= $e($name) ?></code></span>
        <span class="small text-secondary" data-af-status></span>
    </div>
    <div class="card-body p-2">
        <div data-af-messages>
            <?php if (null !== $saved): ?><div class="alert alert-success py-2"><?= $e($saved) ?></div><?php endif ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger py-2"><?= $e($error) ?></div><?php endforeach ?>
        </div>
        <?php // A fragment is inserted by the page's script, maybe inside another form (the notes): no form tag then ?>
        <<?= !empty($fragment) ? 'div' : 'form method="post" action="/api/'.$e($api).'/file"' ?> data-af-form>
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="name" value="<?= $e($name) ?>">
            <input type="hidden" name="area" value="<?= $e($area) ?>">
            <textarea class="form-control font-monospace" name="content" rows="18" data-af-source><?= $e($content) ?></textarea>
            <div class="mt-2" data-af-nojs><button class="btn btn-primary btn-sm">Save</button></div>
        </<?= !empty($fragment) ? 'div' : 'form' ?>>
    </div>
</div>
