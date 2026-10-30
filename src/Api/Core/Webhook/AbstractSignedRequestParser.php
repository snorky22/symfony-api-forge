<?php

namespace App\Api\Core\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Base class of the generated webhook request parsers: JSON webhooks signed with an HMAC of the raw body.
 *
 * Subclasses describe the API's scheme with the constants below. Each valid request becomes a RemoteEvent,
 * whose id is the delivery id when the API sends one, or a hash of the body (so a retried delivery keeps its id).
 */
abstract class AbstractSignedRequestParser extends AbstractRequestParser
{
    protected const SIGNATURE_HEADER = '';
    /** An algorithm of hash_hmac_algos(). */
    protected const SIGNATURE_ALGORITHM = 'sha256';
    /** "hex" or "base64". */
    protected const SIGNATURE_ENCODING = 'hex';
    /** Prefix of the header value, e.g. "sha256=". */
    protected const SIGNATURE_PREFIX = '';
    /** Path of the event name in the payload, in dot notation, e.g. "meta.event_name"... */
    protected const EVENT_NAME_PATH = null;
    /** ...or header holding it. */
    protected const EVENT_NAME_HEADER = null;
    protected const EVENT_ID_PATH = null;
    protected const EVENT_ID_HEADER = null;

    /**
     * Signature of a payload, as the API computes it.
     */
    public function sign(string $payload, #[\SensitiveParameter] string $secret): string
    {
        $hash = hash_hmac(static::SIGNATURE_ALGORITHM, $payload, $secret, true);

        return static::SIGNATURE_PREFIX.('base64' === static::SIGNATURE_ENCODING ? base64_encode($hash) : bin2hex($hash));
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([new MethodRequestMatcher('POST'), new IsJsonRequestMatcher()]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent
    {
        // Never accept unsigned requests: a missing secret is a configuration error.
        if ('' === $secret) {
            throw new RejectWebhookException(500, 'The webhook secret is not configured.');
        }

        $signature = (string) $request->headers->get(static::SIGNATURE_HEADER, '');
        if ('hex' === static::SIGNATURE_ENCODING) {
            $signature = strtolower($signature);
        }
        if (!hash_equals($this->sign($request->getContent(), $secret), $signature)) {
            throw new RejectWebhookException(406, 'Invalid signature.');
        }

        $payload = $request->toArray();

        $name = $this->find($request, $payload, static::EVENT_NAME_PATH, static::EVENT_NAME_HEADER);
        if (null === $name) {
            throw new RejectWebhookException(406, 'The event name is missing.');
        }
        $id = $this->find($request, $payload, static::EVENT_ID_PATH, static::EVENT_ID_HEADER) ?? hash('sha256', $request->getContent());

        return new RemoteEvent($name, $id, $payload);
    }

    /**
     * @param array<mixed> $payload
     */
    private function find(Request $request, array $payload, ?string $path, ?string $header): ?string
    {
        $value = null !== $header ? $request->headers->get($header) : null;
        if (null !== $path) {
            $value = $payload;
            foreach (explode('.', $path) as $key) {
                $value = \is_array($value) ? ($value[$key] ?? null) : null;
            }
        }

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }
}
