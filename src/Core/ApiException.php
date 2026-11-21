<?php

namespace Snorky22\ApiForge\Core;

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
     * Error messages found in JSON:API ("errors": [{"detail": ...}]), an error object ("error": {"detail": ...}) and
     * common JSON error formats.
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
        // An error object: {"error": {"code": "not_found", "detail": "…", "errors": [{"field": "name", "message": "…"}]}} (Paddle)
        if (\is_array($error = $content['error'] ?? null)) {
            $detail = $error['detail'] ?? $error['message'] ?? $error['description'] ?? null;
            if (\is_string($detail)) {
                $details[] = $detail;
            }
            foreach (\is_array($error['errors'] ?? null) ? $error['errors'] : [] as $item) {
                $message = \is_array($item) ? ($item['message'] ?? $item['detail'] ?? null) : $item;
                if (\is_string($message)) {
                    $details[] = \is_array($item) && \is_string($item['field'] ?? null) ? $item['field'].': '.$message : $message;
                }
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
