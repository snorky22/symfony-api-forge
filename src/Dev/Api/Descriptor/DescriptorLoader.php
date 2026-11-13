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

        $review = $this->review($apiFile, $api['review'] ?? [], $api['accepted'] ?? []);
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
                $review = array_merge($review, $this->review($file.': object', $resource['object']['review'] ?? [], $resource['object']['accepted'] ?? []));
            }
            $review = array_merge($review, $this->review($file, $resource['review'] ?? [], $resource['accepted'] ?? []));

            foreach ($resource['operations'] as $operationName => $operation) {
                $this->checkOperation($api, $resource, (string) $operationName, $operation, static fn (string $message) => $error(\sprintf('operation "%s": %s', $operationName, $message)));
                $review = array_merge($review, $this->review(\sprintf('%s: operation "%s"', $file, $operationName), $operation['review'] ?? [], $operation['accepted'] ?? []));
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

        if ([] !== $api['environments'] && null === $api['base_uri_env']) {
            $errors[] = $apiFile.': "environments" needs "base_uri_env", the environment variable choosing the host at runtime.';
        }

        if (isset($api['doctor'])) {
            $this->checkDoctor($apiFile, $api['doctor'], $resources, $errors);
            $review = array_merge($review, $this->review($apiFile.': doctor', $api['doctor']['review'] ?? [], $api['doctor']['accepted'] ?? []));
        }

        if ([] !== $errors) {
            throw new InvalidDescriptorException($errors);
        }

        return new ApiDefinition($name, $api['namespace'] ?? Naming::className($name), $api, $resources, $review, $webhooks);
    }

    /**
     * The probe must be a GET operation of the API, with a value for each of its path parameters.
     *
     * @param array<string, mixed>                $doctor
     * @param array<string, array<string, mixed>> $resources
     * @param list<string>                        $errors
     */
    private function checkDoctor(string $file, array $doctor, array $resources, array &$errors): void
    {
        if (null !== $doctor['probe']) {
            $this->checkDoctorCall($file, 'doctor.probe', 'doctor.parameters', $doctor['probe'], $doctor['parameters'], $resources, $errors);
        }
    }

    /**
     * A call made by the doctor (probe, registered webhooks): a GET operation of the API, with a value for each
     * of its path parameters.
     *
     * @param array<string, string>               $parameters
     * @param array<string, array<string, mixed>> $resources
     * @param list<string>                        $errors
     */
    private function checkDoctorCall(string $file, string $key, string $parametersKey, string $call, array $parameters, array $resources, array &$errors): void
    {
        [$resource, $operationName] = explode('.', $call, 2);
        $operation = $resources[$resource]['operations'][$operationName] ?? null;
        if (null === $operation) {
            $errors[] = \sprintf('%s: %s "%s" is not an operation of the API.', $file, $key, $call);

            return;
        }
        if ('GET' !== $operation['method']) {
            $errors[] = \sprintf('%s: %s "%s" is a %s operation: the doctor only makes harmless GET calls.', $file, $key, $call, $operation['method']);
        }
        if ([] !== $missing = array_diff(array_keys($operation['parameters']), array_keys($parameters))) {
            $errors[] = \sprintf('%s: %s must give the path parameters of "%s": %s.', $file, $parametersKey, $call, implode(', ', $missing));
        }
        if ([] !== $unknown = array_diff(array_keys($parameters), array_keys($operation['parameters']))) {
            $errors[] = \sprintf('%s: %s has unknown parameters for "%s": %s.', $file, $parametersKey, $call, implode(', ', $unknown));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     * @param list<string>                        $errors
     *
     * @return list<string> review notes, including the events whose payload data cannot be typed
     */
    private function checkWebhooks(string $file, array $webhooks, array $api, array $resources, array &$errors): array
    {
        $review = $this->review($file, $webhooks['review'] ?? [], $webhooks['accepted'] ?? []);
        if (isset($webhooks['registration'])) {
            $registration = $webhooks['registration'];
            $this->checkDoctorCall($file, 'registration.operation', 'registration.parameters', $registration['operation'], $registration['parameters'], $resources, $errors);
        }
        if (isset($webhooks['meta'])) {
            $this->checkShape($webhooks['meta']['shape'], 'meta.shape', static function (string $message) use ($file, &$errors): void {
                $errors[] = $file.': '.$message;
            });
        }
        if (isset($webhooks['envelope'])) {
            if ('jsonapi' === $api['format']) {
                $errors[] = $file.': "envelope" is for plain JSON payloads; JSON:API payloads hold their object in "data", with "meta".';
            } elseif (!str_starts_with(trim($webhooks['envelope']['shape']), 'array{')) {
                $errors[] = $file.': envelope.shape must be an array shape, "array{...}", without the object key.';
            } else {
                $this->checkShape($webhooks['envelope']['shape'], 'envelope.shape', static function (string $message) use ($file, &$errors): void {
                    $errors[] = $file.': '.$message;
                });
            }
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
                if ('jsonapi' !== $api['format'] && !isset($webhooks['envelope'])) {
                    $error('"resource" needs "envelope" in plain JSON (the key of the object and the shape around it), or use "shape".');
                } elseif (!isset($resources[$definition['resource']]['object'])) {
                    $untyped[$definition['resource']][] = $event;
                }
            }
            $review = array_merge($review, $this->review(\sprintf('%s: event "%s"', $file, $event), $definition['review'] ?? [], $definition['accepted'] ?? []));
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
        if (isset($operation['response']['data'], $operation['response']['shape'])) {
            if ($jsonApi) {
                $error('"response": "shape" or "data", not both, for a JSON:API document.');
            } elseif (!str_starts_with(trim($operation['response']['shape']), 'array{')) {
                $error('"response.shape" must be an array shape, "array{...}": the members around "data".');
            }
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
    /**
     * @param list<string> $notes
     * @param list<string> $accepted the notes the user accepted: hidden when an import writes them again
     */
    private function review(string $location, array $notes, array $accepted = []): array
    {
        return array_values(array_map(static fn (string $note) => $location.': '.$note, array_diff($notes, $accepted)));
    }
}
