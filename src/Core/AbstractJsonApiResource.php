<?php

namespace Snorky22\ApiForge\Core;

/**
 * Base class of the generated resources of JSON:API APIs (https://jsonapi.org).
 */
abstract class AbstractJsonApiResource extends AbstractResource
{
    /** JSON:API type of the resource, e.g. "customers". */
    protected const TYPE = '';

    /**
     * Builds a JSON:API request document for this resource.
     *
     * @param array<string, mixed>                            $attributes
     * @param array<string, int|string|list<int|string>|null> $relationships     related resource ids by relationship name, null to clear a to-one relationship
     * @param array<string, string>                           $relationshipTypes related resource type by relationship name, "tags[]" for a to-many relationship
     *
     * @return array{data: array<string, mixed>}
     */
    protected function document(array $attributes, array $relationships = [], array $relationshipTypes = [], int|string|null $id = null): array
    {
        $data = ['type' => static::TYPE];
        if (null !== $id) {
            $data['id'] = (string) $id;
        }
        if ([] !== $attributes) {
            $data['attributes'] = $attributes;
        }

        foreach ($relationships as $name => $ids) {
            $type = $relationshipTypes[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown relationship "%s" for "%s", expected one of: "%s".', $name, static::TYPE, implode('", "', array_keys($relationshipTypes))));
            $toMany = str_ends_with($type, '[]');
            $type = $toMany ? substr($type, 0, -2) : $type;

            $data['relationships'][$name]['data'] = match (true) {
                $toMany => array_map(static fn (int|string $id) => ['type' => $type, 'id' => (string) $id], (array) $ids),
                null === $ids => null,
                default => ['type' => $type, 'id' => (string) $ids],
            };
        }

        return ['data' => $data];
    }

    /**
     * Iterates over the resource objects of a collection, following the "next" pagination links.
     *
     * @param array<string, int|string> $parameters
     * @param array<string, mixed>      $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginate(string $path, array $parameters = [], array $query = []): \Generator
    {
        $document = $this->request('GET', $path, $parameters, $query);
        $visited = [];

        while (true) {
            foreach ($document['data'] ?? [] as $object) {
                yield $object;
            }

            $next = $document['links']['next'] ?? null;
            if (!\is_string($next) || '' === $next || isset($visited[$next])) {
                return;
            }
            $visited[$next] = true;
            $document = $this->request('GET', $next);
        }
    }
}
