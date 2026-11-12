<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

use Snorky22\ApiForge\Dev\Api\Docs\DocsAlignment;
use Snorky22\ApiForge\Dev\Api\Docs\DocsAnnotator;
use Snorky22\ApiForge\Dev\Api\Docs\DocsCapture;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;
use Symfony\Component\String\Inflector\EnglishInflector;

use function Symfony\Component\String\u;

/**
 * Reads webhook documentation: the table of event types, and example payloads.
 */
final class WebhookImporter
{
    /** Where APIs usually put the event name, by order of preference. */
    private const EVENT_NAME_PATHS = ['meta.event_name', 'event_name', 'event_type', 'event', 'type'];

    public function __construct(
        private readonly LenientJsonDecoder $decoder,
        private readonly ArrayShapeGenerator $shapeGenerator,
        private readonly DocsCapture $docsCapture = new DocsCapture(),
        private readonly DocsAnnotator $docsAnnotator = new DocsAnnotator(),
    ) {
    }

    /**
     * Reads a Markdown table of events, as found in API docs:
     *
     *     | Event Name      | Data Sent                       | Description                 |
     *     | `order_created` | [Order object](https://...)     | Occurs when a new order ... |
     *
     * The resource is derived from the object name ("Subscription invoice object" gives "subscription-invoices").
     *
     * @return array<string, array{resource?: string, description?: string}> by event name
     */
    public function importEvents(string $markdown): array
    {
        $events = [];
        foreach (explode("\n", $markdown) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map(fn (string $cell) => $this->plainText($cell), explode('|', trim($line, '|')));
            if (!preg_match('/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/i', $cells[0]) || !preg_match('/[._:-]/', $cells[0])) {
                continue; // header, separator or not an event row
            }

            $event = [];
            if ('' !== $resource = $this->resourceName($cells[1] ?? '')) {
                $event['resource'] = $resource;
            }
            if ('' !== ($cells[2] ?? '')) {
                $event['description'] = $cells[2];
            }
            $events[$cells[0]] = $event;
        }

        return $events;
    }

    /**
     * Describes an example payload.
     *
     * @param string  $format API format, "json" or "jsonapi"
     * @param ?string $docs   text capture of the docs page of the payload (DocsCapture): optional fields, nulls, enums
     *
     * @return array{event: ?string, event_path: ?string, type: ?string, meta: ?string, data: ?string, envelope: ?string, shape: ?string, review: list<string>, objectReview: list<string>}
     *                event name and its location, JSON:API type, shapes of "meta" and "data" (JSON:API); in plain
     *                JSON, of the whole payload, and when "data" is an object, of it and of the other members
     *                ("envelope"); what the docs page could not settle
     */
    public function importPayload(string $json, string $format, ?string $docs = null): array
    {
        try {
            $payload = $this->decoder->decode($json, false);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The payload example is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (!$payload instanceof \stdClass) {
            throw new \InvalidArgumentException('The payload example is not a JSON object.');
        }

        $result = ['event' => null, 'event_path' => null, 'type' => null, 'meta' => null, 'data' => null, 'envelope' => null, 'shape' => null, 'review' => [], 'objectReview' => []];
        $hints = [];
        if (null !== $docs) {
            $alignment = $this->docsAnnotator->annotate($this->docsCapture->parse($docs), [$payload]);
            $hints = $alignment->hints;
            $result['review'] = $alignment->notes;
            if ([] === $hints) {
                $result['review'][] = 'docs: no field of the documentation capture matches the example, nothing applied';
            }
        }
        foreach (self::EVENT_NAME_PATHS as $path) {
            $value = $payload;
            foreach (explode('.', $path) as $key) {
                $value = $value instanceof \stdClass ? ($value->{$key} ?? null) : null;
            }
            if (\is_string($value) && '' !== $value) {
                $result['event'] = $value;
                $result['event_path'] = $path;
                break;
            }
        }

        $data = $payload->data ?? null;
        if ('jsonapi' === $format && $data instanceof \stdClass && \is_string($data->type ?? null)) {
            $result['type'] = $data->type;
            $result['data'] = $this->shapeGenerator->generate($data, false, DocsAlignment::under('data', $hints));
            $result['meta'] = isset($payload->meta) ? $this->shapeGenerator->generate($payload->meta, false, DocsAlignment::under('meta', $hints)) : null;
            ShapeReview::add($result['data'], 'object', $result['objectReview']);
        } else {
            $result['shape'] = $this->shapeGenerator->generate($payload, false, $hints);
            if (!$data instanceof \stdClass) {
                // The keys the example leaves unknown, named (a payload without an object is the shape of its event)
                ShapeReview::add($result['shape'], 'payload', $result['review']);
            }
            if ($data instanceof \stdClass) {
                // An object of a resource, in an envelope (event id, name, date…)
                $envelope = clone $payload;
                unset($envelope->data);
                $result['data'] = $this->shapeGenerator->generate($data, false, DocsAlignment::under('data', $hints));
                $result['envelope'] = $this->shapeGenerator->generate($envelope, false, DocsAlignment::outside('data', $hints));
                ShapeReview::add($result['data'], 'object', $result['objectReview']);
            }
        }

        return $result;
    }

    private function plainText(string $markdown): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $markdown); // [text](url) → text

        return trim(str_replace('`', '', $text));
    }

    /**
     * The resource of the object an event carries, from the entity of its name: "transaction.completed" gives
     * "transactions", "payment_method.saved" gives "payment-methods". Null when the name has no entity.
     */
    public function resourceOfEvent(string $event): ?string
    {
        if (!preg_match('/^([a-z][a-z0-9_]*)[.:]/i', $event, $match)) {
            return null;
        }
        $words = explode('_', strtolower($match[1]));
        $words[] = (new EnglishInflector())->pluralize(array_pop($words))[0];

        return implode('-', $words);
    }

    private function resourceName(string $objectName): string
    {
        $name = trim(preg_replace('/\s+object$/i', '', trim($objectName)));
        if ('' === $name) {
            return '';
        }

        $words = explode('-', u($name)->snake()->replace('_', '-')->toString());
        $words[] = (new EnglishInflector())->pluralize(array_pop($words))[0];

        return implode('-', $words);
    }
}
