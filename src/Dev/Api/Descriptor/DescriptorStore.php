<?php

namespace Snorky22\ApiForge\Dev\Api\Descriptor;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes API descriptors: <dir>/<api>/_api.yaml for API-wide settings,
 * <dir>/<api>/_webhooks.yaml for webhooks, and <dir>/<api>/<resource>.yaml for each resource.
 * Files whose name starts with "_" are not resources.
 */
final class DescriptorStore
{
    private const API_FILE = '_api';
    private const WEBHOOKS_FILE = '_webhooks';

    /**
     * @param string $directory default descriptor directory
     */
    public function __construct(
        private readonly string $directory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function loadApi(string $api, ?string $directory = null): ?array
    {
        return $this->load($this->path($api, self::API_FILE, $directory));
    }

    public function loadResource(string $api, string $resource, ?string $directory = null): ?array
    {
        return $this->load($this->resourcePath($api, $resource, $directory));
    }

    public function loadWebhooks(string $api, ?string $directory = null): ?array
    {
        return $this->load($this->path($api, self::WEBHOOKS_FILE, $directory));
    }

    public function saveApi(string $api, array $data, ?string $directory = null): string
    {
        return $this->save($this->path($api, self::API_FILE, $directory), $data);
    }

    public function saveResource(string $api, string $resource, array $data, ?string $directory = null): string
    {
        return $this->save($this->resourcePath($api, $resource, $directory), $data);
    }

    public function saveWebhooks(string $api, array $data, ?string $directory = null): string
    {
        return $this->save($this->path($api, self::WEBHOOKS_FILE, $directory), $data);
    }

    /**
     * @return array<string, string> resource name => file path, sorted by name
     */
    public function resourceFiles(string $api, ?string $directory = null): array
    {
        $files = [];
        foreach (glob(\dirname($this->path($api, self::API_FILE, $directory)).'/*.yaml') ?: [] as $file) {
            $name = basename($file, '.yaml');
            if (!str_starts_with($name, '_')) {
                $files[$name] = $file;
            }
        }
        ksort($files);

        return $files;
    }

    public function apiFile(string $api, ?string $directory = null): string
    {
        return $this->path($api, self::API_FILE, $directory);
    }

    public function webhooksFile(string $api, ?string $directory = null): string
    {
        return $this->path($api, self::WEBHOOKS_FILE, $directory);
    }

    private function resourcePath(string $api, string $resource, ?string $directory): string
    {
        if (str_starts_with($resource, '_')) {
            throw new \InvalidArgumentException(\sprintf('Invalid resource name "%s": names starting with "_" are reserved.', $resource));
        }

        return $this->path($api, $resource, $directory);
    }

    private function path(string $api, string $name, ?string $directory): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $api) || !preg_match('/^_?[a-z0-9][a-z0-9_-]*$/i', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid API or resource name "%s/%s".', $api, $name));
        }

        return ($directory ?? $this->directory).'/'.$api.'/'.$name.'.yaml';
    }

    private function load(string $file): ?array
    {
        return is_file($file) ? Yaml::parseFile($file) : null;
    }

    private function save(string $file, array $data): string
    {
        $this->filesystem->dumpFile($file, Yaml::dump($data, 20, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return $file;
    }
}
