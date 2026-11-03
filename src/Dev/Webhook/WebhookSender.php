<?php

namespace Snorky22\ApiForge\Dev\Webhook;

use Snorky22\ApiForge\Core\Webhook\AbstractSignedRequestParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Simulates webhook deliveries: signs payloads like the API, with the secret of the webhook routing
 * (framework.webhook.routing), and sends them to the application, without a tunnel nor a real event.
 */
final class WebhookSender
{
    /**
     * @param array<string, array{parser: object, secret: string}> $parsers the webhook routing, by type
     */
    public function __construct(
        private readonly array $parsers,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly HttpKernelInterface $kernel,
        private readonly HttpClientInterface $httpClient,
        private readonly ?SendersLocatorInterface $sendersLocator = null,
    ) {
    }

    /**
     * @return list<string> the asynchronous Messenger transports the events of the type are routed to; when
     *                      empty, the consumer runs during the request
     */
    public function asyncTransports(string $type): array
    {
        if (null === $this->sendersLocator) {
            return [];
        }

        $transports = [];
        foreach ($this->sendersLocator->getSenders(new Envelope(new ConsumeRemoteEventMessage($type, new RemoteEvent('', '', [])))) as $name => $sender) {
            if (!$sender instanceof SyncTransport) {
                $transports[] = (string) $name;
            }
        }

        return $transports;
    }

    /**
     * @return list<string> the webhook types whose deliveries can be simulated
     */
    public function types(): array
    {
        return array_keys(array_filter($this->parsers, static fn (array $routing) => $routing['parser'] instanceof AbstractSignedRequestParser));
    }

    /**
     * @return array<string, string> the headers of a delivery of the payload, signed
     *
     * @throws \InvalidArgumentException when the type is unknown, not signed by api-forge, or has no secret
     */
    public function headers(string $type, string $payload, ?string $eventName = null): array
    {
        [$parser, $secret] = $this->signer($type);

        return $parser->deliveryHeaders($payload, $secret, $eventName);
    }

    /**
     * Sends a signed minimal delivery (event "api_forge_ping", ignored by the consumers) over HTTP: checks that
     * a public URL (tunnel, proxy) reaches the endpoint of the type, and that the secrets match.
     *
     * @return array{int, string} status code and response body
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface when the URL can't be reached
     */
    public function ping(string $type, string $url): array
    {
        [$parser] = $this->signer($type);

        return $this->sendTo($url, $type, $parser->pingPayload(), 'api_forge_ping');
    }

    /**
     * @return string the absolute URL of the webhook endpoint of the type
     *
     * @throws \InvalidArgumentException when the webhook routes are not imported
     */
    public function url(string $type): string
    {
        try {
            return $this->urlGenerator->generate('_webhook_controller', ['type' => $type], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException $e) {
            throw new \InvalidArgumentException('The webhook routes are not imported: add the "webhook" routes of FrameworkBundle (config/routes/webhook.yaml, created by the symfony/webhook recipe).', 0, $e);
        }
    }

    /**
     * @return Request a signed delivery of the payload to the application
     */
    public function createRequest(string $type, string $payload, ?string $eventName = null): Request
    {
        return Request::create($this->url($type), 'POST', server: self::serverParameters($this->headers($type, $payload, $eventName)), content: $payload);
    }

    /**
     * Delivers the payload to this application, in-process.
     */
    public function send(string $type, string $payload, ?string $eventName = null): Response
    {
        return $this->kernel->handle($this->createRequest($type, $payload, $eventName));
    }

    /**
     * Delivers the payload over HTTP, e.g. to a running server or a tunnel.
     *
     * @return array{int, string} status code and response body
     */
    public function sendTo(string $url, string $type, string $payload, ?string $eventName = null): array
    {
        $response = $this->httpClient->request('POST', $url, ['headers' => $this->headers($type, $payload, $eventName), 'body' => $payload]);

        return [$response->getStatusCode(), $response->getContent(false)];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string> the headers as $_SERVER parameters, for Request::create() or KernelBrowser::request()
     */
    public static function serverParameters(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    /**
     * @return array{AbstractSignedRequestParser, string}
     */
    private function signer(string $type): array
    {
        $routing = $this->parsers[$type] ?? throw new \InvalidArgumentException(\sprintf('Unknown webhook type "%s"; the known types are: %s.', $type, implode(', ', array_keys($this->parsers)) ?: 'none (framework.webhook.routing is empty)'));
        if (!$routing['parser'] instanceof AbstractSignedRequestParser) {
            throw new \InvalidArgumentException(\sprintf('The parser of the webhook type "%s" (%s) is not generated by api-forge: its signing scheme is unknown.', $type, get_debug_type($routing['parser'])));
        }
        if ('' === $routing['secret']) {
            throw new \InvalidArgumentException(\sprintf('The secret of the webhook type "%s" is empty: set it in .env.local (see framework.webhook.routing).', $type));
        }

        return [$routing['parser'], $routing['secret']];
    }
}
