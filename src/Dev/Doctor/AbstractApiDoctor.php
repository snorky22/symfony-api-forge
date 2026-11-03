<?php

namespace Snorky22\ApiForge\Dev\Doctor;

use Snorky22\ApiForge\Core\ApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Base class of the generated API doctors (api-forge:doctor): checks the configuration of an API (its
 * environment variables), then calls a harmless operation, the probe (doctor.probe in _api.yaml), and
 * diagnoses what fails: unreachable host, rejected credentials, wrong base URI, rate limit, provider error.
 */
abstract class AbstractApiDoctor
{
    /** API name, e.g. "lemonsqueezy" */
    public const API = '';
    protected const BASE_URI = '';
    /** Environment variable name => what it holds ("credentials" for the authentication) */
    protected const ENV = [];
    /** "resource.operation" of the probe, null without probe */
    protected const PROBE = null;

    /**
     * @param array<string, string> $env the values of the environment variables (resolved by Symfony: defaults, .env files)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $env = [],
    ) {
    }

    /**
     * Index of the doctors in the api_forge.doctor locator.
     */
    public static function api(): string
    {
        return static::API;
    }

    /**
     * @return list<Check>
     */
    public function diagnose(): array
    {
        $checks = [];
        foreach (static::ENV as $name => $role) {
            $checks[] = '' === trim($this->env[$name] ?? '')
                ? Check::error(\sprintf('%s (%s) is empty: set it in .env.local.', $name, $role))
                : Check::ok(\sprintf('%s (%s) is set.', $name, $role));
        }

        if (null === static::PROBE) {
            $checks[] = Check::warning('No probe: set doctor.probe in _api.yaml (a harmless GET operation) to check that the API answers and accepts the credentials.');

            return $checks;
        }

        $start = hrtime(true);
        try {
            $this->probe();
            $checks[] = Check::ok(\sprintf('Probe %s: the API answers and accepts the credentials (%d ms, %s).', static::PROBE, (hrtime(true) - $start) / 1e6, static::BASE_URI));
        } catch (ApiException $e) {
            $checks[] = Check::error(\sprintf('Probe %s: %s', static::PROBE, $this->diagnoseStatus($e)));
        } catch (TransportExceptionInterface $e) {
            $checks[] = Check::error(\sprintf('Probe %s: %s is unreachable (DNS, TLS, network, timeout): %s', static::PROBE, static::BASE_URI, $e->getMessage()));
        }

        return $checks;
    }

    /**
     * Calls the probe operation; throws when it fails. Generated when the API has a probe.
     *
     * @throws ApiException|TransportExceptionInterface
     */
    protected function probe(): void
    {
    }

    private function diagnoseStatus(ApiException $e): string
    {
        $credentials = implode(', ', array_keys(static::ENV, 'credentials', true)) ?: 'no credentials configured';

        return match (true) {
            401 === $e->statusCode => \sprintf('the API rejects the credentials (HTTP 401): check %s.', $credentials),
            403 === $e->statusCode => \sprintf('the credentials are accepted but not allowed to call this operation (HTTP 403): check the permissions of the key (%s), or choose another probe.', $credentials),
            404 === $e->statusCode => \sprintf('not found (HTTP 404): wrong base URI or API version (%s), or the probe parameters point to nothing.', static::BASE_URI),
            429 === $e->statusCode => 'rate limited (HTTP 429): retry later.',
            $e->statusCode >= 500 => \sprintf('the API fails (HTTP %d): the provider may be down, retry later.', $e->statusCode),
            default => $e->getMessage(),
        };
    }
}
