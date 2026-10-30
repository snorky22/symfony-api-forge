<?php

namespace Snorky22\ApiForge\Dev\Api\Import;

/**
 * Merges an imported endpoint into existing descriptors.
 *
 * Existing values always win, so hand-made fixes survive re-imports; an import only fills in
 * what is missing. Importing another example of the same operation (same method and path)
 * adds its query parameters; a different endpoint under an existing operation name is a
 * conflict, unless $force is set, which replaces the operation.
 */
final class DescriptorMerger
{
    private const RESOURCE_KEYS = ['resource', 'type', 'object', 'review', 'operations'];
    private const OPERATION_KEYS = ['method', 'path', 'parameters', 'query', 'headers', 'body', 'response', 'review'];

    /**
     * @param ?array<string, mixed> $api      content of _api.yaml, null if it does not exist yet
     * @param ?array<string, mixed> $resource content of the resource file, null if it does not exist yet
     *
     * @return array{api: array<string, mixed>, resource: array<string, mixed>, notes: list<string>}
     */
    public function merge(?array $api, ?array $resource, ImportedEndpoint $endpoint, bool $force = false): array
    {
        $notes = [];
        $operation = $endpoint->operation;

        if (null === $api) {
            $api = $endpoint->api;
        } else {
            foreach (['format', 'auth'] as $key) {
                if (!isset($api[$key]) && isset($endpoint->api[$key])) {
                    $api[$key] = $endpoint->api[$key];
                } elseif (isset($endpoint->api[$key]) && $api[$key] !== $endpoint->api[$key]) {
                    $notes[] = \sprintf('API "%s" differs from _api.yaml, kept the existing one', $key);
                }
            }
            // Headers that differ from the API-wide ones become operation-specific.
            $operationHeaders = array_diff_assoc($endpoint->api['headers'] ?? [], $api['headers'] ?? []);
            if ([] !== $operationHeaders) {
                $operation['headers'] = $operationHeaders;
            }
        }

        $resource ??= ['resource' => $endpoint->resource, 'operations' => []];
        $resource = $this->mergeType($resource, $endpoint->type, $notes);
        if (null !== $endpoint->object) {
            $resource = $this->mergeResourceObject($resource, $endpoint->object, false, $notes);
        }

        $name = $endpoint->operationName;
        $existing = $resource['operations'][$name] ?? null;

        if (null === $existing || $force) {
            $notes[] = \sprintf('operation "%s" %s', $name, null === $existing ? 'added' : 'replaced');
        } elseif ($existing['method'] === $operation['method'] && $existing['path'] === $operation['path']) {
            $operation = $this->mergeOperation($existing, $operation);
            $notes[] = \sprintf('operation "%s" merged with the existing one', $name);
        } else {
            throw new \InvalidArgumentException(\sprintf(
                'Operation "%s" already exists for %s %s; this request is %s %s. Choose another name with --operation, or replace it with --force.',
                $name, $existing['method'], $existing['path'], $operation['method'], $operation['path'],
            ));
        }

        $resource['operations'][$name] = $this->sortKeys($operation, self::OPERATION_KEYS);

        return ['api' => $api, 'resource' => $resource, 'notes' => $notes];
    }

    /**
     * Merges a standalone resource object example into a resource descriptor.
     *
     * @param ?array<string, mixed>                        $resource content of the resource file, null if it does not exist yet
     * @param array{shape: string, review?: list<string>} $object
     *
     * @return array{resource: array<string, mixed>, notes: list<string>}
     */
    public function mergeObject(?array $resource, string $name, ?string $type, array $object, bool $force = false): array
    {
        $notes = [];
        $resource ??= ['resource' => $name, 'operations' => []];
        $resource = $this->mergeType($resource, $type, $notes);

        return ['resource' => $this->mergeResourceObject($resource, $object, $force, $notes), 'notes' => $notes];
    }

    /**
     * @param list<string> $notes
     */
    private function mergeType(array $resource, ?string $type, array &$notes): array
    {
        if (null === $type) {
            return $resource;
        }
        if (isset($resource['type']) && $resource['type'] !== $type) {
            $notes[] = \sprintf('JSON:API type "%s" differs from the resource type "%s"', $type, $resource['type']);

            return $resource;
        }

        return $this->sortKeys(['type' => $type] + $resource, self::RESOURCE_KEYS);
    }

    /**
     * @param list<string> $notes
     */
    private function mergeResourceObject(array $resource, array $object, bool $force, array &$notes): array
    {
        if (isset($resource['object']) && !$force) {
            return $resource;
        }
        $notes[] = \sprintf('resource object %s', isset($resource['object']) ? 'replaced' : 'added');

        return $this->sortKeys(['object' => $object] + $resource, self::RESOURCE_KEYS);
    }

    private function mergeOperation(array $existing, array $new): array
    {
        $merged = $existing;
        foreach (['parameters', 'query', 'headers'] as $key) {
            if (isset($new[$key])) {
                $merged[$key] = ($existing[$key] ?? []) + $new[$key];
            }
        }
        foreach (['body', 'response'] as $key) {
            if (!isset($existing[$key]) && isset($new[$key])) {
                $merged[$key] = $new[$key];
            }
        }

        $isMissingResponse = static fn (string $note) => str_starts_with($note, 'no response example');
        $existingReview = $existing['review'] ?? [];
        $newReview = $new['review'] ?? [];
        // A real response example in the new import settles the missing one; a missing one does not matter if known.
        if (isset($new['response']) && [] === array_filter($newReview, $isMissingResponse)) {
            $existingReview = array_filter($existingReview, static fn (string $note) => !$isMissingResponse($note));
        } elseif (isset($existing['response'])) {
            $newReview = array_filter($newReview, static fn (string $note) => !$isMissingResponse($note));
        }
        $review = array_values(array_unique(array_merge($existingReview, $newReview)));
        unset($merged['review']);
        if ([] !== $review) {
            $merged['review'] = $review;
        }

        return $merged;
    }

    /**
     * @param list<string> $order
     */
    private function sortKeys(array $data, array $order): array
    {
        return array_filter(array_merge(array_fill_keys($order, null), $data), static fn ($value) => null !== $value);
    }
}
