<ul class="nav nav-pills mb-3">
<?php foreach (['new' => '1. Endpoint', 'request' => '2. Request', 'response' => '3. Response', 'import' => '4. Import'] as $key => $label): ?>
    <li class="nav-item">
    <?php if (null === $base && 'new' !== $key): ?>
        <span class="nav-link disabled"><?= $e($label) ?></span>
    <?php elseif ('new' === $key): ?>
        <a class="nav-link <?= 'new' === $step ? 'active' : '' ?>" href="/api/<?= $e($api) ?>/endpoint"><?= $e($label) ?></a>
    <?php else: ?>
        <a class="nav-link <?= $key === $step ? 'active' : '' ?>" href="/api/<?= $e($api) ?>/endpoint?base=<?= $e($base) ?>&amp;step=<?= $e($key) ?>"><?= $e($label) ?></a>
    <?php endif ?>
    </li>
<?php endforeach ?>
</ul>
