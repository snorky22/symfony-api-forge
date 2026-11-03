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
    /** Environment variable choosing the host at runtime, null when the host is fixed */
    protected const BASE_URI_ENV = null;
    /** The other hosts of the API: name => URI */
    protected const ENVIRONMENTS = [];
    /** "resource.operation" of the probe, null without probe */
    protected const PROBE = null;
    /** Array shape of the probe's response (or of its items), null when the descriptor has none */
    protected const PROBE_SHAPE = null;
    /** Key of the checked part of the response ("data" for JSON:API), null for the whole response */
    protected const PROBE_PATH = null;
    /** Whether the checked part is a list of items of PROBE_SHAPE */
    protected const PROBE_LIST = false;
    /** Items of a list compared with the shape */
    private const CHECKED_ITEMS = 5;
    /** Differences shown */
    private const SHOWN_DIFFERENCES = 10;

    /**
     * @param array<string, string> $env     the values of the environment variables (resolved by Symfony: defaults, .env files)
     * @param ?string               $baseUri the host in use, when chosen at runtime (BASE_URI_ENV)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $env = [],
        private readonly ?string $baseUri = null,
    ) {
    }

    /**
     * The host the API is called on.
     */
    protected function baseUri(): string
    {
        return $this->baseUri ?? static::BASE_URI;
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

        if (null !== static::BASE_URI_ENV) {
            $uri = rtrim($this->baseUri(), '/').'/';
            $name = array_search($uri, [...static::ENVIRONMENTS, 'live' => static::BASE_URI], true);
            $checks[] = false !== $name
                ? Check::ok(\sprintf('Host: %s (%s, from %s).', $uri, $name, static::BASE_URI_ENV))
                : Check::warning(\sprintf('Host: %s (from %s) is not a known host of the API: %s.', $uri, static::BASE_URI_ENV, implode(', ', [static::BASE_URI, ...array_values(static::ENVIRONMENTS)])));
        }

        if (null === static::PROBE) {
            $checks[] = Check::warning('No probe: set doctor.probe in _api.yaml (a harmless GET operation) to check that the API answers and accepts the credentials.');

            return $checks;
        }

        $start = hrtime(true);
        try {
            $response = $this->probe();
            $checks[] = Check::ok(\sprintf('Probe %s: the API answers and accepts the credentials (%d ms, %s).', static::PROBE, (hrtime(true) - $start) / 1e6, $this->baseUri()));
            if (null !== static::PROBE_SHAPE) {
                array_push($checks, ...$this->contract($response));
            }
        } catch (ApiException $e) {
            $checks[] = Check::error(\sprintf('Probe %s: %s', static::PROBE, $this->diagnoseStatus($e)));
        } catch (TransportExceptionInterface $e) {
            $checks[] = Check::error(\sprintf('Probe %s: %s is unreachable (DNS, TLS, network, timeout): %s', static::PROBE, $this->baseUri(), $e->getMessage()));
        }

        return $checks;
    }

    /**
     * Calls the probe operation; throws when it fails. Generated when the API has a probe.
     *
     * @return array<mixed> the decoded response
     *
     * @throws ApiException|TransportExceptionInterface
     */
    protected function probe(): array
    {
        return [];
    }

    /**
     * The webhooks registered at the provider (registration in _webhooks.yaml). Generated when the descriptors
     * say how to read them.
     *
     * @return ?list<array{id: ?string, url: string, events: ?list<string>}> null when unknown
     *
     * @throws ApiException|TransportExceptionInterface
     */
    public function registeredWebhooks(): ?array
    {
        return null;
    }

    /**
     * @param array<mixed> $response the response of the list operation
     * @param ?string      $items    key of the list in the response, null for the response itself
     *
     * @return list<array{id: ?string, url: string, events: ?list<string>}>
     */
    protected function registrations(array $response, ?string $items, string $url, ?string $events, ?string $id): array
    {
        $list = null !== $items ? ($response[$items] ?? []) : $response;
        $registrations = [];
        foreach (\is_array($list) ? $list : [] as $item) {
            $registrations[] = [
                'id' => null !== $id ? (string) (self::get($item, $id) ?? '') ?: null : null,
                'url' => (string) self::get($item, $url),
                'events' => null !== $events && \is_array($subscribed = self::get($item, $events)) ? array_values(array_map('strval', $subscribed)) : null,
            ];
        }

        return $registrations;
    }

    private static function get(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * Compares the probe's response with its shape in the descriptor: the API may have changed since.
     *
     * @param array<mixed> $response
     *
     * @return list<Check>
     */
    private function contract(array $response): array
    {
        $value = null !== static::PROBE_PATH ? ($response[static::PROBE_PATH] ?? null) : $response;
        $path = (string) static::PROBE_PATH;
        if (static::PROBE_LIST) {
            if (!\is_array($value) || [] === $value) {
                return [Check::warning(\sprintf('Contract of %s not checked: the response has no items.', static::PROBE))];
            }
            $items = \array_slice(array_values($value), 0, self::CHECKED_ITEMS);
            $path .= '[]';
        } else {
            $items = [$value];
        }

        $checker = new ShapeChecker();
        $differences = [];
        foreach ($items as $item) {
            foreach ($checker->check(static::PROBE_SHAPE, $item, $path) as $difference) {
                $differences[$difference->kind.' '.$difference->path] ??= $difference;
            }
        }

        $checked = static::PROBE_LIST ? \sprintf(' (%d item(s) checked)', \count($items)) : '';
        if ([] === $differences) {
            return [Check::ok(\sprintf('The response of %s matches the descriptor%s.', static::PROBE, $checked))];
        }

        $checks = [Check::warning(\sprintf('The response of %s differs from the descriptor%s: the API may have changed. Update the shape (api-forge:import-object with a real response), then api-forge:generate.', static::PROBE, $checked))];
        foreach (\array_slice(array_values($differences), 0, self::SHOWN_DIFFERENCES) as $difference) {
            // A missing key breaks the code reading it; the other differences don't
            $checks[] = ShapeDifference::MISSING === $difference->kind ? Check::error('  '.$difference) : Check::warning('  '.$difference);
        }
        if (\count($differences) > self::SHOWN_DIFFERENCES) {
            $checks[] = Check::warning(\sprintf('  … %d more difference(s).', \count($differences) - self::SHOWN_DIFFERENCES));
        }

        return $checks;
    }

    private function diagnoseStatus(ApiException $e): string
    {
        $credentials = implode(', ', array_keys(static::ENV, 'credentials', true)) ?: 'no credentials configured';

        return match (true) {
            401 === $e->statusCode => \sprintf('the API rejects the credentials (HTTP 401): check %s.', $credentials),
            403 === $e->statusCode => \sprintf('the credentials are accepted but not allowed to call this operation (HTTP 403): check the permissions of the key (%s), or choose another probe.', $credentials),
            404 === $e->statusCode => \sprintf('not found (HTTP 404): wrong base URI or API version (%s), or the probe parameters point to nothing.', $this->baseUri()),
            429 === $e->statusCode => 'rate limited (HTTP 429): retry later.',
            $e->statusCode >= 500 => \sprintf('the API fails (HTTP %d): the provider may be down, retry later.', $e->statusCode),
            default => $e->getMessage(),
        };
    }
}
