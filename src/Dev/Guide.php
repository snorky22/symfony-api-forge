<?php

namespace Snorky22\ApiForge\Dev;

use Snorky22\ApiForge\Dev\Api\Descriptor\ApiDefinition;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\InvalidDescriptorException;
use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Symfony\Component\Filesystem\Path;

/**
 * The essentials to proceed with an API (api-forge:guide): the workflow, the conventions, and the state of an
 * API with its next step. The single source of the conventions: docs/workflow.md must match them (tested).
 */
final class Guide
{
    /** Where the documentation examples of an API are kept: they are the test fixtures too */
    public const FIXTURES_DIR = 'tests/Fixtures/<api>';

    /** Naming of the documentation examples: pattern, content, examples */
    public const FIXTURES = [
        ['<operation>-<resource>.curl', 'a documented request: the cURL sample of the docs', 'get-account.curl, list-products.curl'],
        ['<operation>-<resource>.json', 'its example response', 'get-account.json, list-products.json'],
        ['<resource>-object.json', 'an object example ("The product object")', 'transaction-object.json'],
        ['webhook-events.md', 'the events table of the docs', 'webhook-events.md'],
        ['webhook-<event>.json', 'a webhook payload example', 'webhook-transaction.completed.json'],
    ];

    /** The workflow: step, command */
    public const STEPS = [
        ['Import a documented request and its response', 'api-forge:import <api> <curl> --response=<json>'],
        ['Import an object example', 'api-forge:import-object <api> <resource> <json>'],
        ['Import the webhooks', 'api-forge:import-webhook <api> --events=<md> --payload=<json>'],
        ['Review the guesses listed under "review" in the descriptors, then delete the notes', 'config/api/<api>/*.yaml'],
        ['Generate the services, the doctor and the configuration', 'api-forge:generate <api>'],
        ['Check the API, its webhooks and the webhooks registered at the provider', 'api-forge:doctor <api>'],
        ['Try a webhook locally, see what arrived, replay it', 'api-forge:webhook:send, api-forge:webhook:inbox, api-forge:webhook:replay'],
    ];

    public function __construct(
        private readonly DescriptorLoader $loader,
        private readonly ServiceGenerator $generator,
        private readonly string $projectDir,
        private readonly string $descriptorDir,
    ) {
    }

    /**
     * @return list<string> the APIs of the descriptor directory
     */
    public function apis(?string $dir = null): array
    {
        $apis = array_map('basename', array_filter(glob(($dir ?? $this->descriptorDir).'/*') ?: [], 'is_dir'));
        sort($apis);

        return $apis;
    }

    /**
     * The state of an API and its next step.
     *
     * @param ?string $dir      descriptor directory (default: api_forge.descriptor_dir)
     * @param ?string $output   project directory the services are generated into (default: this project)
     * @param ?string $fixtures directory of its documentation examples (default: tests/Fixtures/<api>)
     *
     * @return array{state: list<array{string, string}>, next: string}
     */
    public function status(string $api, ?string $dir = null, ?string $output = null, ?string $fixtures = null): array
    {
        $output ??= $this->projectDir;
        $fixtures ??= $this->projectDir.'/'.str_replace('<api>', $api, self::FIXTURES_DIR);
        $state = [['Descriptors', Path::makeRelative(($dir ?? $this->descriptorDir).'/'.$api, $this->projectDir)]];
        $state[] = ['Examples', is_dir($fixtures) ? $this->fixtures($fixtures) : 'none in '.Path::makeRelative($fixtures, $this->projectDir)];

        if (!is_file(($dir ?? $this->descriptorDir).'/'.$api.'/_api.yaml')) {
            return ['state' => $state, 'next' => \sprintf('Import a first documented request: api-forge:import %s %s/get-<resource>.curl --response=…/get-<resource>.json', $api, Path::makeRelative($fixtures, $this->projectDir))];
        }

        try {
            $definition = $this->loader->load($api, $dir);
        } catch (InvalidDescriptorException $e) {
            $state[] = ['Validity', \sprintf('%d error(s)', \count($e->errors))];

            return ['state' => $state, 'next' => "Fix the descriptors:\n".implode("\n", \array_slice($e->errors, 0, 5))];
        }

        $operations = array_sum(array_map(static fn (array $resource) => \count($resource['operations']), $definition->resources));
        $state[] = ['Resources', \sprintf('%d resource(s), %d operation(s): %s', \count($definition->resources), $operations, implode(', ', array_keys($definition->resources)) ?: 'none')];
        $state[] = ['Review notes', (string) \count($definition->review)];
        $probe = $definition->api['doctor']['probe'] ?? null;
        $state[] = ['Doctor probe', $probe ?? 'none'];
        $webhooks = $definition->webhooks;
        $state[] = ['Webhooks', null === $webhooks ? 'none' : \sprintf('%d event(s), secret in %s, registration %s', \count($webhooks['events']), $webhooks['signature']['env'], isset($webhooks['registration']) ? $webhooks['registration']['operation'] : 'not described')];
        $stale = $this->staleFiles($definition, $output);
        $state[] = ['Generated code', [] === $stale ? 'up to date' : \sprintf('%d file(s) to (re)generate', \count($stale))];

        $next = match (true) {
            [] !== $definition->review => \sprintf("Review the %d note(s) left in the descriptors, then delete them:\n%s", \count($definition->review), implode("\n", \array_slice($definition->review, 0, 5))),
            [] !== $stale => 'Generate: api-forge:generate '.$api,
            null === $probe => 'Declare the doctor\'s probe in _api.yaml (doctor.probe: a harmless GET operation), then generate',
            default => 'Import the other endpoints you need (api-forge:import), or check the API: api-forge:doctor '.$api.(null === $webhooks ? '; import its webhooks if it sends some (api-forge:import-webhook)' : ''),
        };

        return ['state' => $state, 'next' => $next];
    }

    /**
     * @return list<string> the generated files missing or different from what the descriptors generate
     */
    private function staleFiles(ApiDefinition $definition, string $output): array
    {
        $stale = [];
        foreach ($this->generator->generate($definition, 'x') as $file => $content) {
            $path = $output.'/'.$file;
            // The header names the descriptor directory: compare without it
            if (!is_file($path) || self::withoutHeader((string) file_get_contents($path)) !== self::withoutHeader($content)) {
                $stale[] = $file;
            }
        }

        return $stale;
    }

    private static function withoutHeader(string $content): string
    {
        return preg_replace('/^(<\?php\n\n)?(\/\/|#) '.preg_quote(ServiceGenerator::MARKER, '/').' from [^\n]*\n/', '', $content);
    }

    private function fixtures(string $directory): string
    {
        $files = array_map('basename', glob($directory.'/*') ?: []);
        $orphans = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.curl') && !\in_array(substr($file, 0, -5).'.json', $files, true)) {
                $orphans[] = $file;
            }
        }

        return \sprintf('%d file(s)%s', \count($files), [] !== $orphans ? ', without response: '.implode(', ', $orphans) : '');
    }
}
