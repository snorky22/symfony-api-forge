<?php

namespace Snorky22\ApiForge\Dev\Webhook;

use Snorky22\ApiForge\Dev\Doctor\Check;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Checks the webhook chain of the application for an API, from the URL to the consumer, and reports what
 * breaks it: routes not imported, missing secret, no consumer, events queued without a worker…
 */
final class WebhookDiagnostics
{
    /**
     * @param list<string> $consumers the types of the registered RemoteEvent consumers
     */
    public function __construct(
        private readonly WebhookSender $sender,
        private readonly array $consumers = [],
    ) {
    }

    /**
     * @return list<Check>
     */
    public function check(string $type): array
    {
        $checks = [];
        try {
            $url = $this->sender->url($type);
            $checks[] = Check::ok(\sprintf('Endpoint: %s (path %s)', $url, parse_url($url, \PHP_URL_PATH)));
        } catch (\InvalidArgumentException $e) {
            return [Check::error($e->getMessage())];
        }

        try {
            $this->sender->headers($type, '{}');
            $checks[] = Check::ok('Request parser routed, signing secret set.');
        } catch (\InvalidArgumentException $e) {
            $checks[] = Check::error($e->getMessage());
        }

        $checks[] = \in_array($type, $this->consumers, true)
            ? Check::ok(\sprintf('Consumer registered (#[AsRemoteEventConsumer(\'%s\')]).', $type))
            : Check::error(\sprintf('No consumer registered with #[AsRemoteEventConsumer(\'%s\')]: deliveries are accepted, then fail.', $type));

        $async = $this->sender->asyncTransports($type);
        $checks[] = [] === $async
            ? Check::ok('Events consumed during the request (no asynchronous transport).')
            : Check::warning(\sprintf('Events queued on the "%s" transport: a worker must run (messenger:consume %s), or they are never consumed.', implode('", "', $async), implode(' ', $async)));

        return $checks;
    }

    /**
     * Sends a signed ping to a public URL (tunnel, proxy) and reports whether it reaches the endpoint.
     */
    public function ping(string $type, string $url): Check
    {
        try {
            [$status, $body] = $this->sender->ping($type, $url);
        } catch (TransportExceptionInterface $e) {
            return Check::error(\sprintf('%s is unreachable (tunnel down? wrong host?): %s', $url, $e->getMessage()));
        } catch (\InvalidArgumentException $e) {
            return Check::error($e->getMessage());
        }

        $path = parse_url($this->sender->url($type), \PHP_URL_PATH);
        $excerpt = mb_strimwidth(trim(strip_tags($body)), 0, 160, '…');

        // The webhook endpoint answers 202: another success status comes from another page (a path is missing)
        return match (true) {
            202 === $status => Check::ok(\sprintf('%s reaches the endpoint, signed with the same secret (HTTP 202).', $url)),
            $status >= 200 && $status < 300 => Check::error(\sprintf('%s answers HTTP %d, not the webhook endpoint (202): the path should be %s.', $url, $status, $path)),
            404 === $status => Check::error(\sprintf('%s answers 404: wrong path (the endpoint is %s), or the tunnel is offline. %s', $url, $path, $excerpt)),
            406 === $status => Check::error(\sprintf('%s rejects the signature (HTTP 406): the server behind the URL has another secret, or is another application. %s', $url, $excerpt)),
            default => Check::error(\sprintf('%s answers HTTP %d: %s', $url, $status, $excerpt)),
        };
    }
}
