<?php

namespace App\Dev\Api\Import;

use function Symfony\Component\String\u;

/**
 * Merges imported webhook documentation into _webhooks.yaml.
 *
 * As for the other descriptors, existing values always win: imports only fill in what is missing.
 */
final class WebhookMerger
{
    public const DEFAULT_SIGNATURE_NOTE = 'signature: default scheme (HMAC-SHA256 of the raw body, hex-encoded, in the X-Signature header), check it against the API docs';
    public const MISSING_EVENT_NAME_NOTE = 'event_name: set where the event name is, "path" in the payload or "header"';
    private const KEYS = ['signature', 'event_name', 'event_id', 'meta', 'events', 'review'];
    private const EVENT_KEYS = ['resource', 'shape', 'description', 'review'];

    /**
     * @param ?array<string, mixed>                                         $webhooks content of _webhooks.yaml, null if it does not exist yet
     * @param array<string, array{resource?: string, description?: string}> $events
     *
     * @return array{webhooks: array<string, mixed>, notes: list<string>}
     */
    public function mergeEvents(?array $webhooks, string $apiName, array $events): array
    {
        $webhooks ??= $this->skeleton($apiName);
        $added = 0;
        foreach ($events as $name => $event) {
            $existing = $webhooks['events'][$name] ?? [];
            $webhooks['events'][$name] = $this->sortKeys($existing + $event, self::EVENT_KEYS);
            $added += [] === $existing ? 1 : 0;
        }

        $notes = [\sprintf('%d event(s) added, %d already known', $added, \count($events) - $added)];
        if ($added > 0) {
            $webhooks['review'] = $this->addNote($webhooks['review'] ?? [], 'events: resources were derived from the object names of the events doc');
        }

        return ['webhooks' => $this->sortKeys($webhooks, self::KEYS), 'notes' => $notes];
    }

    /**
     * @param ?array<string, mixed>  $webhooks content of _webhooks.yaml, null if it does not exist yet
     * @param array<string, ?string> $payload  as described by WebhookImporter::importPayload()
     * @param ?string                $event    event name; read from the payload when null
     * @param ?string                $resource resource of the payload data (JSON:API)
     * @param list<string>           $review   notes about the event
     *
     * @return array{webhooks: array<string, mixed>, notes: list<string>}
     */
    public function mergePayload(?array $webhooks, string $apiName, array $payload, ?string $event, ?string $resource, array $review = []): array
    {
        $event ??= $payload['event'] ?? throw new \InvalidArgumentException('The event name was not found in the payload: give it with --event.');
        $webhooks ??= $this->skeleton($apiName);
        $notes = [];

        if (!isset($webhooks['event_name']) && null !== $payload['event_path']) {
            $webhooks['event_name'] = ['path' => $payload['event_path']];
            $webhooks['review'] = array_values(array_diff($webhooks['review'] ?? [], [self::MISSING_EVENT_NAME_NOTE]));
            $notes[] = \sprintf('event name read from "%s"', $payload['event_path']);
        }
        if (!isset($webhooks['meta']) && null !== $payload['meta']) {
            $webhooks['meta'] = ['shape' => $payload['meta']];
            $webhooks['review'] = $this->addNote($webhooks['review'] ?? [], 'meta: the shape comes from one example, it may lack optional keys');
        }

        $existing = $webhooks['events'][$event] ?? null;
        $new = array_filter(['resource' => $resource, 'shape' => null === $resource ? $payload['shape'] : null], static fn ($value) => null !== $value);
        $merged = ($existing ?? []) + $new;
        foreach ($review as $note) {
            $merged['review'] = $this->addNote($merged['review'] ?? [], $note);
        }
        $webhooks['events'][$event] = $this->sortKeys($merged, self::EVENT_KEYS);
        $notes[] = \sprintf('event "%s" %s', $event, null === $existing ? 'added' : 'updated');

        return ['webhooks' => $this->sortKeys($webhooks, self::KEYS), 'notes' => $notes];
    }

    private function skeleton(string $apiName): array
    {
        return [
            'signature' => [
                'header' => 'X-Signature',
                'algorithm' => 'sha256',
                'encoding' => 'hex',
                'env' => u($apiName)->snake()->upper()->toString().'_WEBHOOK_SECRET',
            ],
            'events' => [],
            'review' => [self::DEFAULT_SIGNATURE_NOTE, self::MISSING_EVENT_NAME_NOTE],
        ];
    }

    /**
     * @param list<string> $review
     *
     * @return list<string>
     */
    private function addNote(array $review, string $note): array
    {
        return \in_array($note, $review, true) ? $review : [...$review, $note];
    }

    /**
     * @param list<string> $order
     */
    private function sortKeys(array $data, array $order): array
    {
        return array_filter(array_merge(array_fill_keys($order, null), $data), static fn ($value) => null !== $value);
    }
}
