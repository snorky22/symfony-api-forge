<h1 class="h3 mb-3">APIs</h1>
<p class="text-secondary">Each API goes through five steps: describe it, add the examples of its documentation, review what the
importer guessed, generate the code, check it. The state is read from your project's files: you can stop here and
continue with the command line, or the other way round.</p>

<?php if ([] === $apis): ?>
    <div class="alert alert-info">No API yet. Start with the first step.</div>
<?php endif ?>

<div class="row g-3 mb-4">
<?php foreach ($apis as $name => $steps): ?>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h5"><a href="/api/<?= $e($name) ?>"><?= $e($name) ?></a></h2>
                <ul class="list-unstyled small mb-0">
                <?php foreach ($steps as $step): ?>
                    <li>
                        <span class="badge <?= $step['done'] ? 'text-bg-success' : ($step['current'] ? 'text-bg-warning' : 'text-bg-light border') ?>"><?= $step['done'] ? 'done' : ($step['current'] ? 'next' : 'later') ?></span>
                        <?= $e($step['title']) ?>
                    </li>
                <?php endforeach ?>
                </ul>
            </div>
        </div>
    </div>
<?php endforeach ?>
</div>

<a class="btn btn-primary" href="/new">Describe a new API</a>
