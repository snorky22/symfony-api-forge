<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

use Snorky22\ApiForge\Dev\Api\Docs\DocsAlignment;
use Snorky22\ApiForge\Dev\Api\Docs\DocsAnnotator;
use Snorky22\ApiForge\Dev\Api\Docs\DocsCapture;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

/**
 * The object of a resource, described from all the examples that hold it (its sources): a webhook payload ("data"),
 * a response (the objects of "data[]"), each with the docs page next to it. One description, merged: a key missing
 * from some examples is optional, a value null in one and set in another is nullable.
 *
 * A source is recorded in the resource descriptor as {example: <file, relative to the project>, path: data|data[]}.
 */
final class ObjectSources
{
    public function __construct(
        private readonly ExampleReader $examples,
        private readonly LenientJsonDecoder $decoder = new LenientJsonDecoder(),
        private readonly ArrayShapeGenerator $shapeGenerator = new ArrayShapeGenerator(),
        private readonly DocsCapture $docsCapture = new DocsCapture(),
        private readonly DocsAnnotator $docsAnnotator = new DocsAnnotator(),
    ) {
    }

    /**
     * @param list<array{example: string, path: string}> $sources
     *
     * @return array{shape: string, review: list<string>} the object shape, and its notes
     *
     * @throws \InvalidArgumentException when a source can't be read, or holds no object where it says
     */
    public function describe(string $projectDir, array $sources): array
    {
        $items = [];
        $hints = [];
        foreach ($sources as $source) {
            $file = self::path($projectDir, $source['example']);
            $document = $this->decoder->decode($this->examples->json($file), false);
            $found = self::at($document, $source['path']);
            if ([] === $found) {
                throw new \InvalidArgumentException(\sprintf('%s holds no object in "%s" anymore: import it again, or remove it from the sources of the object.', $source['example'], $source['path']));
            }
            array_push($items, ...$found);

            $docs = preg_replace('/\.[^.\/]+$/', '', $file).'.docs.md';
            if (is_file($docs)) {
                // The first source that documents a field wins
                $hints += DocsAlignment::under($source['path'], $this->docsAnnotator->annotate($this->docsCapture->parse((string) file_get_contents($docs)), [$document])->hints);
            }
        }

        $shape = $this->shapeGenerator->generateMerged($items, false, $hints);

        $review = [];
        ShapeReview::add($shape, 'object', $review);
        ValueReview::add($items, 'object', $review);

        return ['shape' => $shape, 'review' => $review];
    }

    /**
     * The object of a resource after the import of an example that holds it.
     *
     * - no object yet: the example describes it, and is its first source;
     * - the example is a source already: the object is described again from all its sources;
     * - another example: "keep" the object (the default; a note says when the example differs), "merge" the example
     *   with the sources (found by convention when none is recorded: $discovered), or "replace" them by it.
     *
     * @param ?array{shape: string, sources?: list<array{example: string, path: string}>, review?: list<string>} $object
     * @param array{example: string, path: string}                                                            $source
     * @param array{shape: string, review?: list<string>}                                                     $described the object as the example alone describes it
     * @param list<array{example: string, path: string}>                                                      $discovered
     *
     * @param bool $force the import replaces what it describes: a new example replaces the object, unless $mode says
     *                    otherwise (a source is described again with the others)
     *
     * @return array{object: array<string, mixed>, notes: list<string>, review: list<string>} the object, what was done,
     *         a note for the operation or the event when the example is not used
     *
     * @throws \InvalidArgumentException an unknown mode, nothing to merge with, or a source that can't be read
     */
    public function update(string $projectDir, string $resource, ?array $object, array $source, array $described, ?string $mode = null, array $discovered = [], bool $force = false): array
    {
        if (null !== $mode && !\in_array($mode, ['keep', 'merge', 'replace'], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown object mode "%s": keep, merge or replace.', $mode));
        }
        $single = static fn (array $sources) => self::ordered(['shape' => $described['shape'], 'sources' => $sources, 'review' => $described['review'] ?? []]);
        if (null === $object) {
            return ['object' => $single([$source]), 'notes' => [\sprintf('object of "%s" set from %s', $resource, $source['example'])], 'review' => []];
        }

        $sources = $object['sources'] ?? [];
        // An example that is a source already is described again with the others, unless a mode says otherwise
        if (\in_array($source, $sources, true) && 'replace' !== $mode) {
            if ('keep' === $mode) {
                return ['object' => $object, 'notes' => [], 'review' => []];
            }
            $object = 1 === \count($sources) ? $single($sources) : ['sources' => $sources] + $this->describe($projectDir, $sources);

            return ['object' => self::ordered($object), 'notes' => [\sprintf('object of "%s" described again from its %s', $resource, 1 === \count($sources) ? 'example' : \count($sources).' examples')], 'review' => []];
        }

        // Another example: kept by default; --force alone replaces (as for the other descriptors)
        switch ($mode ?? ($force ? 'replace' : 'keep')) {
            case 'replace':
                return ['object' => $single([$source]), 'notes' => [\sprintf('object of "%s" replaced, from %s', $resource, $source['example'])], 'review' => []];
            case 'merge':
                $base = [] !== $sources ? $sources : array_values(array_filter($discovered, static fn (array $found) => $found !== $source));
                if ([] === $base) {
                    throw new \InvalidArgumentException(\sprintf('The "%s" object has no example to merge with (none recorded, none found): replace it, or keep it.', $resource));
                }
                $merged = [...$base, $source];

                return ['object' => self::ordered(['sources' => $merged] + $this->describe($projectDir, $merged)), 'notes' => [\sprintf('object of "%s" merged from %d examples: %s', $resource, \count($merged), implode(', ', array_column($merged, 'example')))], 'review' => []];
            default:
                return [
                    'object' => $object,
                    'notes' => [],
                    'review' => $object['shape'] !== $described['shape'] ? [\sprintf('the objects of %s differ from the "%s" object already described (from %s): the object is kept', $source['example'], $resource, [] !== $sources ? implode(', ', array_column($sources, 'example')) : 'another example')] : [],
                ];
        }
    }

    /**
     * The examples that hold objects of a resource, by the conventions of the examples folder: the webhook payloads of
     * its events, and the responses of its operations that hold its objects.
     *
     * @param array<string, mixed>  $resource the resource descriptor
     * @param ?array<string, mixed> $webhooks the webhooks descriptor
     *
     * @return list<array{example: string, path: string}>
     */
    public function discover(string $projectDir, string $directory, string $name, array $resource, ?array $webhooks): array
    {
        $found = [];
        $relative = static fn (string $file) => str_starts_with($file, rtrim($projectDir, '/').'/') ? substr($file, \strlen(rtrim($projectDir, '/')) + 1) : $file;
        $key = $webhooks['envelope']['data'] ?? 'data';
        foreach ($webhooks['events'] ?? [] as $event => $definition) {
            if ($name === ($definition['resource'] ?? null) && is_file($file = $directory.'/webhook-'.$event.'.json')) {
                $found[] = ['example' => $relative($file), 'path' => $key];
            }
        }
        foreach ($resource['operations'] ?? [] as $operation => $definition) {
            if (!isset($definition['response']['data'])) {
                continue;
            }
            $path = 'list' === $definition['response']['data'] ? 'data[]' : 'data';
            foreach (['json' => $path, 'php' => 'response.'.$path] as $extension => $at) {
                if (is_file($file = $directory.'/'.$operation.'-'.$name.'.'.$extension)) {
                    $found[] = ['example' => $relative($file), 'path' => $at];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private static function ordered(array $object): array
    {
        return array_filter(['shape' => $object['shape'] ?? null, 'sources' => $object['sources'] ?? null, 'review' => $object['review'] ?? null], static fn ($value) => null !== $value && [] !== $value);
    }

    /**
     * Where the objects are in a document: "data" (one object) or "data[]" (the objects of a list).
     *
     * @return list<\stdClass>
     */
    private static function at(mixed $document, string $path): array
    {
        $list = str_ends_with($path, '[]');
        $value = $document;
        foreach (explode('.', rtrim($path, '[]')) as $key) {
            $value = $value instanceof \stdClass ? ($value->{$key} ?? null) : null;
        }
        if ($list) {
            return \is_array($value) ? array_values(array_filter($value, static fn ($item) => $item instanceof \stdClass)) : [];
        }

        return $value instanceof \stdClass ? [$value] : [];
    }

    private static function path(string $projectDir, string $file): string
    {
        return str_starts_with($file, '/') ? $file : rtrim($projectDir, '/').'/'.$file;
    }
}
