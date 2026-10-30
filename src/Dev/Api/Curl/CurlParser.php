<?php

namespace App\Dev\Api\Curl;

/**
 * Parses curl commands as found in API documentation.
 *
 * Handles shell quoting (single, double, $'...' ANSI-C), backslash line continuations,
 * a leading "$ " prompt and the curl options that matter for describing a request.
 */
final class CurlParser
{
    private const VALUE_OPTIONS = [
        '-X' => 'method', '--request' => 'method',
        '-H' => 'header', '--header' => 'header',
        '-d' => 'data', '--data' => 'data', '--data-raw' => 'data', '--data-binary' => 'data', '--data-ascii' => 'data',
        '--json' => 'json',
        '-u' => 'user', '--user' => 'user',
        '--url' => 'url',
    ];

    private const FLAGS = [
        '-G', '--get', '-s', '--silent', '-S', '--show-error', '-L', '--location', '-i', '--include',
        '-v', '--verbose', '-k', '--insecure', '-g', '--globoff', '--compressed', '-f', '--fail',
    ];

    public function parse(string $command): CurlRequest
    {
        $args = $this->tokenize($command);
        if ('$' === ($args[0] ?? null)) {
            array_shift($args);
        }
        if ('curl' !== array_shift($args)) {
            throw new \InvalidArgumentException('Not a curl command.');
        }

        $method = $url = $user = $body = null;
        $headers = [];
        $unknown = [];
        $get = false;

        for ($i = 0, $count = \count($args); $i < $count; ++$i) {
            [$option, $value] = $this->splitOption($args[$i]);

            if (null === $option) {
                $url ??= $value;
                continue;
            }

            if (isset(self::VALUE_OPTIONS[$option])) {
                if (null === $value) {
                    if (++$i >= $count) {
                        throw new \InvalidArgumentException(\sprintf('Option "%s" requires a value.', $option));
                    }
                    $value = $args[$i];
                }

                switch (self::VALUE_OPTIONS[$option]) {
                    case 'method':
                        $method = strtoupper($value);
                        break;
                    case 'header':
                        [$name, $headerValue] = array_map('trim', explode(':', $value, 2)) + [1 => ''];
                        $headers[$name] = $headerValue;
                        break;
                    case 'json':
                        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
                        // no break
                    case 'data':
                        $body = null === $body ? $value : $body.'&'.$value;
                        break;
                    case 'user':
                        $user = $value;
                        break;
                    case 'url':
                        $url = $value;
                        break;
                }
            } elseif (\in_array($option, ['-G', '--get'], true)) {
                $get = true;
            } elseif (!\in_array($option, self::FLAGS, true)) {
                $unknown[] = $args[$i];
            }
        }

        if (null === $url) {
            throw new \InvalidArgumentException('No URL found in the curl command.');
        }

        if ($get && null !== $body) {
            $url .= (str_contains($url, '?') ? '&' : '?').$body;
            $body = null;
        }

        return new CurlRequest($method ?? (null === $body ? 'GET' : 'POST'), $url, $headers, $body, $user, $unknown);
    }

    /**
     * @return array{?string, ?string} [option, attached value]; option is null for positional arguments
     */
    private function splitOption(string $arg): array
    {
        if (!str_starts_with($arg, '-') || '-' === $arg) {
            return [null, $arg];
        }
        if (str_starts_with($arg, '--')) {
            $parts = explode('=', $arg, 2);

            return [$parts[0], $parts[1] ?? null];
        }
        // Short option with attached value, e.g. -XPOST
        if (\strlen($arg) > 2 && isset(self::VALUE_OPTIONS[substr($arg, 0, 2)])) {
            return [substr($arg, 0, 2), substr($arg, 2)];
        }

        return [$arg, null];
    }

    /**
     * Splits a command line into words the way a POSIX shell does (without expansions).
     *
     * @return list<string>
     */
    public function tokenize(string $command): array
    {
        $words = [];
        $word = null;
        $length = \strlen($command);

        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];

            if (' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char) {
                if (null !== $word) {
                    $words[] = $word;
                    $word = null;
                }
            } elseif ('#' === $char && null === $word) {
                $end = strpos($command, "\n", $i);
                $i = false === $end ? $length : $end;
            } elseif ('\\' === $char) {
                $next = $command[++$i] ?? '';
                if ("\r" === $next && "\n" === ($command[$i + 1] ?? '')) {
                    ++$i;
                } elseif ("\n" !== $next) {
                    $word .= $next;
                }
            } elseif ("'" === $char) {
                $end = strpos($command, "'", $i + 1);
                if (false === $end) {
                    throw new \InvalidArgumentException('Unterminated single quote.');
                }
                $word .= substr($command, $i + 1, $end - $i - 1);
                $i = $end;
            } elseif ('"' === $char) {
                $word .= $this->readDoubleQuoted($command, $i);
            } elseif ('$' === $char && "'" === ($command[$i + 1] ?? '')) {
                ++$i;
                $word .= $this->readAnsiCQuoted($command, $i);
            } else {
                $word .= $char;
            }
        }

        if (null !== $word) {
            $words[] = $word;
        }

        return $words;
    }

    /**
     * @param int $i position of the opening quote; moved to the closing quote
     */
    private function readDoubleQuoted(string $command, int &$i): string
    {
        $result = '';
        for (++$i; $i < \strlen($command) && '"' !== $command[$i]; ++$i) {
            $char = $command[$i];
            if ('\\' === $char && \in_array($command[$i + 1] ?? '', ['"', '\\', '$', '`', "\n"], true)) {
                $char = $command[++$i];
                if ("\n" === $char) {
                    continue;
                }
            }
            $result .= $char;
        }
        if ($i >= \strlen($command)) {
            throw new \InvalidArgumentException('Unterminated double quote.');
        }

        return $result;
    }

    /**
     * @param int $i position of the opening quote; moved to the closing quote
     */
    private function readAnsiCQuoted(string $command, int &$i): string
    {
        $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'f' => "\f", 'a' => "\x07", 'b' => "\x08", 'e' => "\e", 'E' => "\e", '\\' => '\\', "'" => "'", '"' => '"', '?' => '?'];
        $result = '';

        for (++$i; $i < \strlen($command) && "'" !== $command[$i]; ++$i) {
            if ('\\' !== $command[$i]) {
                $result .= $command[$i];
                continue;
            }

            $rest = substr($command, $i + 1);
            if (preg_match('/^(?:x([0-9a-fA-F]{1,2})|[uU]([0-9a-fA-F]{1,8})|([0-7]{1,3}))/', $rest, $m)) {
                $result .= match (true) {
                    'x' === $m[0][0] => \chr(hexdec($m[1])),
                    'u' === strtolower($m[0][0]) => mb_chr(hexdec($m[2]), 'UTF-8'),
                    default => \chr(octdec($m[3]) & 0xFF),
                };
                $i += \strlen($m[0]);
            } elseif (isset($simple[$rest[0] ?? ''])) {
                $result .= $simple[$rest[0]];
                ++$i;
            } else {
                $result .= '\\';
            }
        }
        if ($i >= \strlen($command)) {
            throw new \InvalidArgumentException('Unterminated $\'...\' quote.');
        }

        return $result;
    }
}
