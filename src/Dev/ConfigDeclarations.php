<?php

namespace Snorky22\ApiForge\Dev;

use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Where the YAML files of a configuration directory declare the scoped HTTP clients
 * (framework.http_client.scoped_clients) and the webhook types (framework.webhook.routing), also under when@<env>.
 * A file written by api-forge:generate is recognized by its marker: make:resource and make:webhook have priority over
 * it.
 */
final class ConfigDeclarations
{
    /**
     * The scoped clients declared in directories.
     *
     * @param string|list<string> $directories
     *
     * @return array<string, list<array{file: string, generated: bool, env: ?string}>> client id => declarations
     */
    public function clients(string|array $directories): array
    {
        return $this->declarations((array) $directories, ['http_client', 'scoped_clients']);
    }

    /**
     * The webhook types routed in directories.
     *
     * @param string|list<string> $directories
     *
     * @return array<string, list<array{file: string, generated: bool, env: ?string}>> type => declarations
     */
    public function webhooks(string|array $directories): array
    {
        return $this->declarations((array) $directories, ['webhook', 'routing']);
    }

    /**
     * The declarations written by hand (not by api-forge:generate) that apply to every environment: those of a
     * when@<env> section or of an environment directory (config/packages/test/) only replace a value there.
     *
     * @param array<string, list<array{file: string, generated: bool, env: ?string}>> $declarations
     *
     * @return array<string, list<array{file: string, generated: bool, env: ?string}>>
     */
    public function handWrittenEverywhere(array $declarations): array
    {
        return array_filter(array_map(static fn (array $list): array => array_values(array_filter($list, static fn (array $entry): bool => !$entry['generated'] && null === $entry['env'])), $declarations));
    }

    /**
     * The declarations that apply to every environment, generated or not.
     *
     * @param array<string, list<array{file: string, generated: bool, env: ?string}>> $declarations
     *
     * @return array<string, list<array{file: string, generated: bool, env: ?string}>>
     */
    public function everywhere(array $declarations): array
    {
        return array_filter(array_map(static fn (array $list): array => array_values(array_filter($list, static fn (array $entry): bool => null === $entry['env'])), $declarations));
    }

    /**
     * The declarations of a name whose letters and digits match another one, whatever the case and separators:
     * "LemonSqueezy" matches "lemonsqueezy" and "lemon_squeezy" ("lemonsqueezy.client" for clients).
     *
     * @param array<string, list<array{file: string, generated: bool, env: ?string}>> $declarations
     *
     * @return list<string> the matching names
     */
    public function matching(array $declarations, string $name, string $suffix = ''): array
    {
        $normalized = self::normalize($name);

        return array_values(array_filter(array_keys($declarations), static fn (string $declared): bool => self::normalize('' !== $suffix && str_ends_with($declared, $suffix) ? substr($declared, 0, -\strlen($suffix)) : $declared) === $normalized));
    }

    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    /**
     * @param list<string>          $directories
     * @param array{string, string} $path        the keys under "framework"
     *
     * @return array<string, list<array{file: string, generated: bool, env: ?string}>>
     */
    private function declarations(array $directories, array $path): array
    {
        $found = [];
        foreach (array_unique($directories) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach ((new Finder())->files()->in($directory)->name(['*.yaml', '*.yml'])->sortByName() as $file) {
                $content = $file->getContents();
                try {
                    $config = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
                } catch (ParseException) {
                    continue;
                }
                if (!\is_array($config)) {
                    continue;
                }
                $generated = str_contains($content, ServiceGenerator::MARKER);
                // An environment directory (config/packages/<env>/) applies to that environment only
                $directoryEnv = '' === $file->getRelativePath() ? null : explode('/', str_replace('\\', '/', $file->getRelativePath()))[0];
                // The file itself, then its when@<env> sections
                $sections = [[$config, $directoryEnv]];
                foreach ($config as $key => $value) {
                    if (\is_string($key) && str_starts_with($key, 'when@') && \is_array($value)) {
                        $sections[] = [$value, substr($key, 5)];
                    }
                }
                foreach ($sections as [$section, $env]) {
                    $entries = $section['framework'][$path[0]][$path[1]] ?? null;
                    foreach (\is_array($entries) ? array_keys($entries) : [] as $name) {
                        $entry = ['file' => $file->getPathname(), 'generated' => $generated, 'env' => $env];
                        if (!\in_array($entry, $found[(string) $name] ?? [], true)) {
                            $found[(string) $name][] = $entry;
                        }
                    }
                }
            }
        }

        return $found;
    }
}
