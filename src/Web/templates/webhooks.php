<h1 class="h3 mb-1">Webhooks <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">The events the API sends, and their payloads. A payload example and its docs page describe an event; when the payload
holds an object (<code>data</code>), they describe the resource of that object, and so every event of the same entity.
Everything is written in <code>config/api/<?= $e($api) ?>/_webhooks.yaml</code> and the resource files.</p>

<?php if (!$state['described']): ?>
    <div class="alert alert-info">No webhook described yet. Start with a payload example: the event is added from it.
        To list all the events first, copy the events table of the docs into <code>tests/Fixtures/<?= $e($api) ?>/webhook-events.md</code> and run
        <code><?= $e($command) ?></code>.</div>
    <a class="btn btn-primary" href="/api/<?= $e($api) ?>/webhook">Add a payload</a>
    <a class="btn btn-link" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
<?php else: ?>
    <div class="card mb-3"><div class="card-body">
        <h2 class="h6">Receiving</h2>
        <dl class="row mb-0 small">
            <dt class="col-sm-3">Endpoint</dt><dd class="col-sm-9"><code>/webhook/<?= $e($api) ?></code></dd>
            <dt class="col-sm-3">Signature</dt><dd class="col-sm-9"><code><?= $e($state['signature'] ?? '—') ?></code></dd>
            <dt class="col-sm-3">Secret</dt><dd class="col-sm-9"><code><?= $e($state['secret'] ?? '—') ?></code> in <code>.env.local</code></dd>
            <dt class="col-sm-3">Event name</dt><dd class="col-sm-9"><?= null !== $state['eventName'] ? '<code>'.$e($state['eventName']).'</code>' : '<span class="badge text-bg-warning">not set</span> add a payload: it is read from it' ?></dd>
            <dt class="col-sm-3">Delivery id</dt><dd class="col-sm-9"><?= null !== $state['eventId'] ? '<code>'.$e($state['eventId']).'</code>' : '<span class="text-secondary">none: a hash of the body</span>' ?></dd>
            <?php if ('json' === $state['format']): ?>
            <dt class="col-sm-3">Envelope</dt><dd class="col-sm-9"><?= null !== $state['envelope'] ? 'the object is in <code>'.$e($state['envelope']).'</code>' : '<span class="text-secondary">set by the first payload holding an object</span>' ?></dd>
            <?php endif ?>
            <dt class="col-sm-3">Registered webhooks</dt><dd class="col-sm-9 mb-0"><?= null !== $state['registration'] ? 'read with <code>'.$e($state['registration']).'</code> by the doctor' : '<span class="text-secondary">not described: the doctor cannot check them</span>' ?></dd>
        </dl>
        <div class="mt-2"><a class="small" href="/api/<?= $e($api) ?>/file?name=_webhooks.yaml&amp;area=descriptors">Edit _webhooks.yaml</a></div>
    </div></div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Events <span class="text-secondary small"><?= $state['typed'] ?> of <?= $state['total'] ?> typed<?= $state['notes'] > 0 ? ', '.$state['notes'].' note(s)' : '' ?></span></h2>
        <div>
            <a class="btn btn-sm btn-primary" href="/api/<?= $e($api) ?>/webhook">Add a payload</a>
            <?php if ($state['notes'] > 0): ?><a class="btn btn-sm btn-outline-primary" href="/api/<?= $e($api) ?>/notes">Review the notes</a><?php endif ?>
            <a class="btn btn-sm btn-outline-secondary" href="/api/<?= $e($api) ?>/generate">Generate</a>
        </div>
    </div>
    <div class="form-text mb-2">One payload per entity is enough when it holds an object: <em>Add a payload</em> on one event of the entity types them all.</div>

    <?php foreach ($state['entities'] as $entity => $events): ?>
        <?php $typed = count(array_filter($events, static fn (array $event) => 'untyped' !== $event['typed'])); ?>
        <details class="card mb-2" <?= $typed < count($events) && $typed > 0 ? 'open' : '' ?>>
            <summary class="card-header d-flex justify-content-between align-items-center">
                <span><strong><?= $e($entity) ?></strong> <span class="text-secondary small"><?= count($events) ?> event(s)</span></span>
                <span class="badge <?= $typed === count($events) ? 'text-bg-success' : (0 === $typed ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= $typed === count($events) ? 'typed' : $typed.' of '.count($events).' typed' ?></span>
            </summary>
            <ul class="list-group list-group-flush">
            <?php foreach ($events as $event): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><code><?= $e($event['name']) ?></code>
                        <span class="badge <?= 'untyped' === $event['typed'] ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $e($event['detail']) ?></span>
                        <?php if ($event['notes'] > 0): ?><span class="badge text-bg-warning"><?= $event['notes'] ?> note(s)</span><?php endif ?>
                        <?php if (null !== $event['description']): ?><br><span class="small text-secondary"><?= $e($event['description']) ?></span><?php endif ?>
                    </span>
                    <a class="btn btn-sm <?= $event['example'] ? 'btn-outline-secondary' : ('untyped' === $event['typed'] ? 'btn-outline-primary' : 'btn-link') ?>" href="/api/<?= $e($api) ?>/webhook?event=<?= $e(rawurlencode($event['name'])) ?>"><?= $event['example'] ? 'Edit the payload' : 'Add a payload' ?></a>
                </li>
            <?php endforeach ?>
            </ul>
        </details>
    <?php endforeach ?>
    <a class="btn btn-link mt-2" href="/api/<?= $e($api) ?>">Back to <?= $e($api) ?></a>
<?php endif ?>
