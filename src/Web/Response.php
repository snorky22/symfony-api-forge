<?php

namespace Snorky22\ApiForge\Web;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE), ['Content-Type' => 'application/json']);
    }

    public static function redirect(string $location): self
    {
        return new self(303, '', ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers + ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'] as $name => $value) {
            header($name.': '.$value);
        }
        echo $this->body;
    }
}
