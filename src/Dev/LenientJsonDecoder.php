<?php

namespace App\Dev;

/**
 * Decodes JSON copied from API documentation, which is often not strictly valid:
 * it may contain comments, "..." placeholders ({...}, [...]) and trailing commas.
 */
final class LenientJsonDecoder
{
    /**
     * @throws \JsonException
     */
    public function decode(string $json, bool $associative = true): mixed
    {
        return json_decode($this->sanitize($json), $associative, 512, \JSON_THROW_ON_ERROR);
    }

    public function sanitize(string $json): string
    {
        $result = '';
        foreach ($this->tokenize($json) as [$isString, $text]) {
            $result .= $isString ? $text : $this->cleanStructure($text);
        }

        return $result;
    }

    /**
     * Splits the input into string literals and structural chunks, dropping comments.
     * Chunks never span a string literal, so the cleanup regexes cannot alter string contents.
     *
     * @return list<array{bool, string}>
     */
    private function tokenize(string $json): array
    {
        $tokens = [];
        $chunk = '';
        $length = \strlen($json);

        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];

            if ('"' === $char) {
                $start = $i;
                for (++$i; $i < $length && '"' !== $json[$i]; ++$i) {
                    if ('\\' === $json[$i]) {
                        ++$i;
                    }
                }
                $tokens[] = [false, $chunk];
                $tokens[] = [true, substr($json, $start, $i - $start + 1)];
                $chunk = '';
            } elseif ('/' === $char && '/' === ($json[$i + 1] ?? '')) {
                $end = strpos($json, "\n", $i);
                $i = false === $end ? $length : $end - 1;
            } elseif ('/' === $char && '*' === ($json[$i + 1] ?? '')) {
                $end = strpos($json, '*/', $i + 2);
                $i = false === $end ? $length : $end + 1;
            } else {
                $chunk .= $char;
            }
        }
        $tokens[] = [false, $chunk];

        return $tokens;
    }

    private function cleanStructure(string $chunk): string
    {
        // A placeholder used as a value becomes an empty container; as a list element, it is removed.
        $chunk = preg_replace(['/:\s*\{\s*(?:\.{3}|…)\s*\}/u', '/:\s*\[\s*(?:\.{3}|…)\s*\]/u'], [': {}', ': []'], $chunk);
        $chunk = preg_replace('/\{\s*(?:\.{3}|…)\s*\}|\[\s*(?:\.{3}|…)\s*\]|\.{3}|…/u', '', $chunk);
        $chunk = preg_replace('/,(?:\s*,)+/', ',', $chunk);
        $chunk = preg_replace('/([\[{])\s*,/', '$1', $chunk);

        return preg_replace('/,(\s*[\]}])/', '$1', $chunk);
    }
}
