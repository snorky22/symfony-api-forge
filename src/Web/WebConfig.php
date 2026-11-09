<?php

namespace Snorky22\ApiForge\Web;

/**
 * What the web interface needs to know of the project it works on. The server process gets it as JSON in the
 * environment (api-forge:serve), as it does not boot the application.
 */
final class WebConfig
{
    /** Environment variable holding the settings, as JSON */
    public const ENV_NAME = 'API_FORGE_WEB';

    /**
     * @param string       $descriptorDir absolute
     * @param list<string> $console       how to run the console, e.g. ["/usr/bin/php", "/project/bin/console"]
     * @param list<string> $hosts         the Host headers accepted (the interface answers on the loopback only)
     */
    public function __construct(
        public readonly string $projectDir,
        public readonly string $descriptorDir,
        public readonly string $namespace,
        public readonly string $sourceDir,
        public readonly string $configDir,
        public readonly array $console,
        public readonly string $secret,
        public readonly array $hosts,
    ) {
    }

    public function fixturesDir(string $api): string
    {
        return $this->projectDir.'/tests/Fixtures/'.$api;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['projectDir'], $data['descriptorDir'], $data['namespace'], $data['sourceDir'], $data['configDir'], $data['console'], $data['secret'], $data['hosts']);
    }

    public function toJson(): string
    {
        return json_encode(get_object_vars($this), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
