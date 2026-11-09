<?php

namespace Snorky22\ApiForge\Web;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $host = '',
        public readonly string $accept = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/'),
            $_GET,
            $_POST,
            $_SERVER['HTTP_HOST'] ?? '',
            $_SERVER['HTTP_ACCEPT'] ?? '',
        );
    }

    /**
     * Whether the caller (the editor of the page) wants JSON rather than a page.
     */
    public function wantsJson(): bool
    {
        return str_contains($this->accept, 'application/json');
    }

    public function text(string $key): string
    {
        $value = $this->post[$key] ?? '';

        return \is_string($value) ? str_replace("\r\n", "\n", $value) : '';
    }

    public function flag(string $key): bool
    {
        return isset($this->post[$key]) && '' !== $this->post[$key];
    }
}
