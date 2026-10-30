<?php

namespace App\Api\Core;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An API answered with an error status, or with an invalid response.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param array<mixed> $content decoded error response, [] if not JSON
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $content = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(ResponseInterface $response, ?\Throwable $previous = null): self
    {
        $statusCode = $response->getStatusCode();
        try {
            $content = $response->toArray(false);
        } catch (\Throwable) {
            $content = [];
        }

        $message = \sprintf('HTTP %d returned for "%s"', $statusCode, $response->getInfo('url'));
        if ([] !== $details = self::details($content)) {
            $message .= ': '.implode('; ', $details);
        }

        return new self($message, $statusCode, $content, $previous);
    }

    /**
     * Error messages found in JSON:API ("errors": [{"detail": ...}]) and common JSON error formats.
     *
     * @return list<string>
     */
    private static function details(array $content): array
    {
        $details = [];
        foreach (\is_array($content['errors'] ?? null) ? $content['errors'] : [] as $error) {
            $detail = \is_array($error) ? ($error['detail'] ?? $error['title'] ?? $error['message'] ?? null) : $error;
            if (\is_string($detail)) {
                $details[] = $detail;
            }
        }
        foreach (['message', 'error', 'error_description'] as $key) {
            if (\is_string($content[$key] ?? null)) {
                $details[] = $content[$key];
            }
        }

        return array_values(array_unique($details));
    }
}
