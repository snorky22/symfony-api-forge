<?php

namespace Snorky22\ApiForge\Dev\Api\Descriptor;

use Snorky22\ApiForge\Dev\Api\Naming;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the descriptors of an API and validates them strictly, for code generation.
 *
 * All errors are collected and reported together, each with its file and location.
 */
final class DescriptorLoader
{
    /** Method names used by the generated classes and their base classes. */
    public const RESERVED_METHODS = ['request', 'paginate', 'document', 'iterate'];

    private const SHAPE_PATHS = [
        'body.shape' => ['body', 'shape'],
        'body.attributes.shape' => ['body', 'attributes', 'shape'],
        'response.shape' => ['response', 'shape'],
    ];

    public function __construct(
        private readonly DescriptorStore $store,
        private readonly ShapeValidator $shapeValidator,
    ) {
    }

    /**
     * @throws InvalidDescriptorException
     */
    public function load(string $name, ?string $directory = null): ApiDefinition
    {
        $errors = [];
        $apiFile = $this->store->apiFile($name, $directory);
        $api = $this->process($apiFile, DescriptorSchema::api(), $errors);
        if (null === $api) {
            throw new InvalidDescriptorException($errors);
        }

        $review = $this->review($apiFile, $api['review'] ?? []);
        $resources = [];
        $classes = [];

        foreach ($this->store->resourceFiles($name, $directory) as $resourceName => $file) {
            $resource = $this->process($file, DescriptorSchema::resource(), $errors);
            if (null === $resource) {
                continue;
            }

            $error = static function (string $message) use ($file, &$errors): void {
                $errors[] = $file.': '.$message;
            };

            if ($resource['resource'] !== $resourceName) {
                $error(\sprintf('"resource" is "%s", it must match the file name "%s".', $resource['resource'], $resourceName));
            }
            $class = Naming::className($resourceName);
            if (!Naming::isIdentifier($class) || isset($classes[$class])) {
                $error(\sprintf('The class name "%s" is invalid or already used by "%s".', $class, $classes[$class] ?? $resourceName));
            }
            $classes[$class] = $resourceName;

            if (isset($resource['object'])) {
                $this->checkShape($resource['object']['shape'], 'object.shape', $error);
                $review = array_merge($review, $this->review($file.': object', $resource['object']['review'] ?? []));
            }
            $review = array_merge($review, $this->review($file, $resource['review'] ?? []));

            foreach ($resource['operations'] as $operationName => $operation) {
                $this->checkOperation($api, $resource, (string) $operationName, $operation, static fn (string $message) => $error(\sprintf('operation "%s": %s', $operationName, $message)));
                $review = array_merge($review, $this->review(\sprintf('%s: operation "%s"', $file, $operationName), $operation['review'] ?? []));
            }

            $resources[$resourceName] = $resource;
        }

        $webhooks = null;
        $webhooksFile = $this->store->webhooksFile($name, $directory);
        if (is_file($webhooksFile)) {
            $webhooks = $this->process($webhooksFile, DescriptorSchema::webhooks(), $errors);
            if (null !== $webhooks) {
                $review = array_merge($review, $this->checkWebhooks($webhooksFile, $webhooks, $api, $resources, $errors));
            }
        }

        if ([] !== $errors) {
            throw new InvalidDescriptorException($errors);
        }

        return new ApiDefinition($name, $api['namespace'] ?? Naming::className($name), $api, $resources, $review, $webhooks);
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     * @param list<string>                        $errors
     *
     * @return list<string> review notes, including the events whose payload data cannot be typed
     */
    private function checkWebhooks(string $file, array $webhooks, array $api, array $resources, array &$errors): array
    {
        $review = $this->review($file, $webhooks['review'] ?? []);
        if (isset($webhooks['meta'])) {
            $this->checkShape($webhooks['meta']['shape'], 'meta.shape', static function (string $message) use ($file, &$errors): void {
                $errors[] = $file.': '.$message;
            });
        }

        $constants = $methods = $untyped = [];
        foreach ($webhooks['events'] as $event => $definition) {
            $event = (string) $event;
            $error = static function (string $message) use ($file, $event, &$errors): void {
                $errors[] = \sprintf('%s: event "%s": %s', $file, $event, $message);
            };

            $constant = Naming::constantName($event);
            $method = Naming::eventMethodName($event);
            if (!Naming::isIdentifier($constant) || isset($constants[$constant]) || isset($methods[$method])) {
                $error(\sprintf('its constant "%s" or method "%s" is invalid or already used by "%s".', $constant, $method, $constants[$constant] ?? $methods[$method] ?? $event));
            }
            $constants[$constant] = $methods[$method] = $event;

            if (null !== $definition['shape']) {
                $this->checkShape($definition['shape'], 'shape', $error);
            }
            if (null !== $definition['resource']) {
                if ('jsonapi' !== $api['format']) {
                    $error('"resource" requires the "jsonapi" API format, use "shape" instead.');
                } elseif (!isset($resources[$definition['resource']]['object'])) {
                    $untyped[$definition['resource']][] = $event;
                }
            }
            $review = array_merge($review, $this->review(\sprintf('%s: event "%s"', $file, $event), $definition['review'] ?? []));
        }

        foreach ($untyped as $resource => $events) {
            $review[] = \sprintf(
                '%s: the resource "%s" has no object shape: the payload data of %s is untyped (import the resource object with api-forge:import-object)',
                $file, $resource, 1 === \count($events) ? '"'.$events[0].'"' : \count($events).' events',
            );
        }

        return $review;
    }

    /**
     * @param \Closure(string): void $error
     */
    private function checkOperation(array $api, array $resource, string $name, array $operation, \Closure $error): void
    {
        $jsonApi = 'jsonapi' === $api['format'];

        if (!Naming::isIdentifier($name) || str_starts_with($name, '__') || \in_array($name, self::RESERVED_METHODS, true)) {
            $error(\sprintf('"%s" cannot be used as a method name (reserved: %s).', $name, implode(', ', self::RESERVED_METHODS)));
        }

        preg_match_all('/\{([^}]*)\}/', $operation['path'], $matches);
        $pathParameters = $matches[1];
        $declared = array_keys($operation['parameters']);
        if ($missing = array_diff($pathParameters, $declared)) {
            $error(\sprintf('path parameters {%s} are not declared in "parameters".', implode('}, {', $missing)));
        }
        if ($unused = array_diff($declared, $pathParameters)) {
            $error(\sprintf('parameters "%s" do not appear in the path.', implode('", "', $unused)));
        }

        // The generated method parameters must be distinct PHP variables.
        $variables = $pathParameters;
        foreach (array_keys($operation['query']) as $queryName) {
            $variables[] = Naming::variableName((string) $queryName);
        }
        if (isset($operation['body'])) {
            array_push($variables, ...(isset($operation['body']['attributes']) ? ['attributes', 'relationships'] : ['body']));
        }
        $variables[] = 'query';
        foreach (array_count_values($variables) as $variable => $count) {
            if (!Naming::isIdentifier((string) $variable)) {
                $error(\sprintf('"%s" is not a valid parameter name.', $variable));
            } elseif ($count > 1) {
                $error(\sprintf('several parameters would be named "$%s".', $variable));
            }
        }

        if (isset($operation['body']['attributes'])) {
            if (!$jsonApi) {
                $error('"body.attributes" requires the "jsonapi" API format.');
            } elseif (null === $resource['type']) {
                $error('"body.attributes" requires the resource "type".');
            }
        }
        if (isset($operation['response']['data']) && !$jsonApi) {
            $error('"response.data" requires the "jsonapi" API format.');
        }

        foreach (self::SHAPE_PATHS as $label => $path) {
            $shape = $operation;
            foreach ($path as $key) {
                $shape = $shape[$key] ?? null;
            }
            if (null !== $shape) {
                $this->checkShape($shape, $label, $error);
            }
        }
    }

    /**
     * @param \Closure(string): void $error
     */
    private function checkShape(string $shape, string $label, \Closure $error): void
    {
        if (null !== $parseError = $this->shapeValidator->validate($shape)) {
            $error(\sprintf('"%s" is not a valid PHPDoc type: %s', $label, $parseError));
        }
    }

    /**
     * @param list<string> $errors
     */
    private function process(string $file, NodeInterface $schema, array &$errors): ?array
    {
        if (!is_file($file)) {
            $errors[] = $file.': file not found.';

            return null;
        }

        try {
            return (new Processor())->process($schema, [Yaml::parseFile($file) ?? []]);
        } catch (ParseException|ConfigException $e) {
            $errors[] = $file.': '.$e->getMessage();

            return null;
        }
    }

    /**
     * @param list<string> $notes
     *
     * @return list<string>
     */
    private function review(string $location, array $notes): array
    {
        return array_map(static fn (string $note) => $location.': '.$note, $notes);
    }
}
