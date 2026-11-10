<h1 class="h3 mb-1">Webhook payload <small class="text-secondary"><?= $e($api) ?></small></h1>
<p class="text-secondary">Paste a payload example and the docs page of the event. They are saved as
<code>webhook-<?= $e('' !== $values['event'] ? $values['event'] : '<event>') ?>.json</code> and <code>.docs.md</code> in <code>tests/Fixtures/<?= $e($api) ?>/</code>, then imported.</p>

<?php if ([] !== $errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if (null !== $known && 'untyped' !== $known['typed']): ?>
    <div class="alert alert-info py-2">This event is already typed (<?= $e($known['detail']) ?>). Importing again with “Replace” applies the payload and the docs page below.</div>
<?php endif ?>

<form method="post" action="/api/<?= $e($api) ?>/webhook" class="card card-body">
    <input type="hidden" name="_token" value="<?= $e($token) ?>">

    <label class="form-label" for="event">Event</label>
    <select class="form-select mb-1" id="event" name="event">
        <option value="">Read it from the payload</option>
        <?php foreach ($state['entities'] as $entity => $events): ?>
            <optgroup label="<?= $e($entity) ?>">
            <?php foreach ($events as $event): ?>
                <option value="<?= $e($event['name']) ?>" <?= $event['name'] === $values['event'] ? 'selected' : '' ?>><?= $e($event['name']) ?><?= 'untyped' === $event['typed'] ? '' : ' (typed)' ?></option>
            <?php endforeach ?>
            </optgroup>
        <?php endforeach ?>
    </select>
    <div class="form-text mb-3">The payload must be an event of this name; a new event is added when it is not in the list.</div>

    <label class="form-label" for="payload">The payload example, as JSON</label>
    <textarea class="form-control font-monospace mb-1" id="payload" name="payload" rows="14" data-af-code="json" placeholder='{"event_id": "evt_01", "event_type": "transaction.completed", "data": {"id": "txn_01"}}'><?= $e($values['payload']) ?></textarea>
    <div class="form-text mb-3">The example of the docs page of the event, or the body of a real delivery (the inbox of <code>api-forge:webhook:inbox</code> keeps them).
        When it holds an object in <code>data</code>, that object types its resource, and every event of the same entity.</div>

    <label class="form-label" for="docs">The docs page of the event, as text <span class="text-secondary">(optional, recommended)</span></label>
    <textarea class="form-control font-monospace mb-1" id="docs" name="docs" rows="6" data-af-code="text" placeholder="event_idstringrequired&#10;..."><?= $e($values['docs']) ?></textarea>
    <div class="form-text mb-3">Select the page's field list in the browser, copy, paste: it gives the optional keys, the nulls and the enums.</div>

    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="force" name="force" value="1" <?= $values['force'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="force">Replace what is described (the event's shape, or its resource object and the envelope)</label>
        <div class="form-text">Untick to only fill in what is missing: differences are then listed as notes.</div>
    </div>

    <div><button class="btn btn-primary">Save and import</button> <a class="btn btn-link" href="/api/<?= $e($api) ?>/webhooks">Back</a></div>
</form>
