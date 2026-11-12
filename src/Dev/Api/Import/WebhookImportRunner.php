<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;

/**
 * Imports webhook documentation (events table, payload examples) into _webhooks.yaml and the resource objects:
 * the work of api-forge:import-webhook, for the command and for the web interface.
 */
final class WebhookImportRunner
{
    public function __construct(
        private readonly WebhookImporter $importer,
        private readonly WebhookMerger $merger,
        private readonly DescriptorMerger $descriptorMerger,
        private readonly DescriptorStore $store,
        private readonly ExampleReader $examples,
        private readonly ?ObjectSources $objects = null,
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * @param ?string      $events   Markdown file with the table of events
     * @param list<string> $payloads files with example payloads (JSON, or arrays)
     * @param ?string      $event    event name of the payload, when it cannot be read from it (a single payload)
     * @param ?string      $docs     text capture of the docs page of the payload (a single payload); default:
     *                               <payload>.docs.md next to each payload, if any
     * @param bool         $force    a payload replaces the shape of its event, or the object shape of its resource
     *                               and the envelope
     *
     * @return array{notes: list<string>, files: list<string>, review: list<string>, events: list<string>} what was
     *         done, the files written, the notes left in the descriptors, the events of the payloads
     *
     * @throws \InvalidArgumentException when an input can't be read or holds no event name
     */
    public function import(string $api, ?string $events = null, array $payloads = [], ?string $event = null, ?string $docs = null, bool $force = false, ?string $dir = null, ?string $objectMode = null): array
    {
        if (null === $events && [] === $payloads) {
            throw new \InvalidArgumentException('Give the events table and/or payloads.');
        }
        if ((null !== $event || null !== $docs) && \count($payloads) > 1) {
            throw new \InvalidArgumentException('The event name and the docs page can only be given for a single payload.');
        }

        $webhooks = $this->store->loadWebhooks($api, $dir);
        $format = $this->store->loadApi($api, $dir)['format'] ?? 'json';
        $notes = $files = $imported = [];

        if (null !== $events) {
            $merged = $this->merger->mergeEvents($webhooks, $api, $this->importer->importEvents($this->read($events)), 'jsonapi' === $format);
            $webhooks = $merged['webhooks'];
            $notes = array_merge($notes, $merged['notes']);
        }

        foreach ($payloads as $file) {
            $docsFile = $docs ?? preg_replace('/\.[^.\/]+$/', '', $file).'.docs.md';
            if (null === $docs && !is_file($docsFile)) {
                $docsFile = null;
            }
            $description = $this->importer->importPayload($this->examples->json($file), $format, null !== $docsFile ? $this->read($docsFile) : null);
            if (null !== $docsFile) {
                $notes[] = \sprintf('docs page read: %s', $docsFile);
            }
            $resource = null;
            $review = $description['review'];

            // The payload holds an object of a resource: JSON:API "data" (by its type), or plain JSON "data" in an
            // envelope (by the entity of the event name)
            if (null !== $description['type']) {
                $resource = $this->resourceOfType($api, $description['type'], $dir);
            } elseif (null !== $description['data'] && null !== $name = $event ?? $description['event']) {
                $resource = $this->importer->resourceOfEvent($name);
            }
            if (null !== $resource) {
                $descriptor = $this->store->loadResource($api, $resource, $dir);
                $source = ['example' => $this->relative($file), 'path' => null !== $description['type'] ? 'data' : ($webhooks['envelope']['data'] ?? 'data')];
                $mode = $objectMode;
                if (null === $this->objects) {
                    $update = ['object' => isset($descriptor['object']) && !$force ? $descriptor['object'] : ['shape' => $description['data']], 'notes' => [], 'review' => []];
                } else {
                    $discovered = 'merge' === $mode && [] === ($descriptor['object']['sources'] ?? []) && null !== $descriptor
                        ? $this->objects->discover($this->projectDir, \dirname($file), $resource, $descriptor, $webhooks)
                        : [];
                    $update = $this->objects->update($this->projectDir, $resource, $descriptor['object'] ?? null, $source, ['shape' => (string) $description['data'], 'review' => $description['objectReview'] ?? []], $mode, $discovered, $force);
                }
                array_push($review, ...$update['review']);
                array_push($notes, ...$update['notes']);
                if ($update['object'] !== ($descriptor['object'] ?? null)) {
                    $merged = $this->descriptorMerger->mergeObject($descriptor, $resource, $description['type'], $update['object'], true);
                    $files[] = $this->store->saveResource($api, $resource, $merged['resource'], $dir);
                    if (!isset($descriptor['object']) && null === $description['type']) {
                        $review[] = \sprintf('resource "%s" derived from the event name: rename the file and this value if the API calls it otherwise', $resource);
                    }
                }
            }

            $merged = $this->merger->mergePayload($webhooks, $api, $description, $event, $resource, $review, $force);
            $webhooks = $merged['webhooks'];
            $notes = array_merge($notes, $merged['notes']);
            $imported[] = $event ?? (string) $description['event'];
        }

        $files[] = $this->store->saveWebhooks($api, $webhooks, $dir);

        $review = $webhooks['review'] ?? [];
        foreach ($webhooks['events'] as $name => $definition) {
            foreach ($definition['review'] ?? [] as $note) {
                $review[] = \sprintf('event "%s": %s', $name, $note);
            }
        }

        return ['notes' => $notes, 'files' => array_values(array_unique($files)), 'review' => $review, 'events' => $imported];
    }

    /**
     * The resource whose JSON:API type is $type, or the resource named after the type.
     */
    private function resourceOfType(string $api, string $type, ?string $dir): string
    {
        foreach (array_keys($this->store->resourceFiles($api, $dir)) as $resource) {
            if ($type === ($this->store->loadResource($api, $resource, $dir)['type'] ?? null)) {
                return $resource;
            }
        }

        return $type;
    }

    private function read(string $file): string
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            throw new \InvalidArgumentException(\sprintf('Cannot read file "%s".', $file));
        }

        return $content;
    }

    /**
     * A file as the descriptors record it: relative to the project when it is inside.
     */
    private function relative(string $file): string
    {
        $real = realpath($file) ?: $file;
        $project = '' !== $this->projectDir ? (realpath($this->projectDir) ?: $this->projectDir) : '';

        return '' !== $project && str_starts_with($real, $project.'/') ? substr($real, \strlen($project) + 1) : $real;
    }
}
