<?php

namespace Snorky22\ApiForge\Dev\Api\Example;

use Snorky22\ApiForge\Dev\PhpLiteral;

/**
 * Examples written as PHP arrays (extension "php"): for the parts of an API that the documentation describes
 * in prose, with no curl nor JSON to copy. The file is read as data, never run (PhpLiteral), and may hold
 * comments, e.g. the constraints of the docs.
 *
 * As an operation, the array describes the request and the response:
 *
 *     <?php return [
 *         'request' => 'POST /prices',               // "METHOD path", or "METHOD https://full/url"
 *         'query' => ['status' => 'active'],         // optional
 *         'headers' => ['Paddle-Version' => '1'],    // optional
 *         'body' => ['name' => 'Monthly'],           // optional, sent as JSON
 *         'response' => ['data' => [...]],           // optional, the example response
 *     ];
 *
 * As a bare JSON document (a response, an object, a type, a webhook payload), the array is the document.
 * A value naming another data file ("duration-type.json") is replaced by its content (ReferenceResolver).
 */
final class PhpArrayExample implements ExampleFormat
{
    private const KEYS = ['request', 'query', 'headers', 'body', 'response'];

    public function extensions(): array
    {
        return ['php', 'array'];
    }

    public function operation(string $content, ?string $baseUri, ?\Closure $resolve = null): Example
    {
        $data = $this->data($content);
        if (array_is_list($data) && [] !== $data || [] !== $unknown = array_diff(array_keys($data), self::KEYS)) {
            throw new \InvalidArgumentException(\sprintf('an operation example has the keys %s%s.', implode(', ', self::KEYS), isset($unknown) ? '; found '.implode(', ', array_map('strval', $unknown)) : ''));
        }

        if (null !== $resolve) {
            foreach (['body', 'response'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = $resolve($data[$key]);
                }
            }
        }

        return new Example(
            isset($data['request']) ? $this->curl($data, $baseUri) : null,
            isset($data['response']) ? $this->encode($data['response']) : null,
        );
    }

    public function document(string $content): mixed
    {
        return $this->data($content);
    }

    /**
     * @return array<mixed>
     */
    private function data(string $content): array
    {
        try {
            $data = PhpLiteral::evaluate($content);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('not an array of literal data (it is read, not run): '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('the file must return an array.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function curl(array $data, ?string $baseUri): string
    {
        if (!\is_string($data['request']) || !preg_match('/^([A-Za-z]+)\s+(\S+)$/', trim($data['request']), $matches)) {
            throw new \InvalidArgumentException('"request" must be "METHOD path", e.g. \'POST /prices\'.');
        }
        [, $method, $target] = $matches;
        $method = strtoupper($method);

        if (!preg_match('#^https?://#i', $target)) {
            if (null === $baseUri) {
                throw new \InvalidArgumentException(\sprintf('"request": the API has no base URI yet, write the full URL ("%s https://api.example.com%s").', $method, str_starts_with($target, '/') ? $target : '/'.$target));
            }
            $target = rtrim($baseUri, '/').'/'.ltrim($target, '/');
        }

        $query = $this->query($data['query'] ?? []);
        if ('' !== $query) {
            $target .= (str_contains($target, '?') ? '&' : '?').$query;
        }

        $parts = ['curl '.self::quote($target)];
        if ('GET' !== $method) {
            $parts[] = '-X '.$method;
        }
        $headers = $data['headers'] ?? [];
        if (!\is_array($headers)) {
            throw new \InvalidArgumentException('"headers" must be an array of name => value.');
        }
        foreach ($headers as $name => $value) {
            $parts[] = '-H '.self::quote($name.': '.$value);
        }
        if (isset($data['body'])) {
            if (!\is_array($data['body'])) {
                throw new \InvalidArgumentException('"body" must be an array (it is sent as JSON).');
            }
            if ([] === array_filter(array_keys($headers), static fn ($name) => 0 === strcasecmp((string) $name, 'Content-Type'))) {
                $parts[] = '-H '.self::quote('Content-Type: application/json');
            }
            $parts[] = '-d '.self::quote($this->encode($data['body']));
        }

        return implode(" \\\n  ", $parts)."\n";
    }

    /**
     * @param mixed $query name => value; a list is joined with commas, a nested array becomes name[key]
     */
    private function query(mixed $query): string
    {
        if (!\is_array($query)) {
            throw new \InvalidArgumentException('"query" must be an array of name => value.');
        }

        $pairs = [];
        $add = static function (string $name, mixed $value) use (&$add, &$pairs): void {
            if (\is_array($value) && !array_is_list($value)) {
                foreach ($value as $key => $item) {
                    $add($name.'['.$key.']', $item);
                }

                return;
            }
            $value = \is_array($value) ? implode(',', array_map('strval', $value)) : (\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            $pairs[] = $name.'='.rawurlencode($value);
        };
        foreach ($query as $name => $value) {
            $add((string) $name, $value);
        }

        return implode('&', $pairs);
    }

    private function encode(mixed $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)."\n";
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
