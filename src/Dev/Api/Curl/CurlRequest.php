<?php

namespace Snorky22\ApiForge\Dev\Api\Curl;

final class CurlRequest
{
    /**
     * @param array<string, string> $headers        header name (as written) => value
     * @param list<string>          $unknownOptions options the parser does not understand
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly ?string $user = null,
        public readonly array $unknownOptions = [],
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (0 === strcasecmp($headerName, $name)) {
                return $value;
            }
        }

        return null;
    }
}
