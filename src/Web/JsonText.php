<?php

namespace Snorky22\ApiForge\Web;

/**
 * Edits the text of a JSON example in place: the file keeps its layout, its comments and its other values.
 */
final class JsonText
{
    /**
     * Replaces the value of the first "key": … of the text (at any depth) by $literal.
     *
     * @param ?string $only the first character the old value must have ("{", "[", "n" for null…), null for any
     *
     * @return ?string the new text, null when the key is not found (or its value is not of the expected kind)
     */
    public static function replaceValue(string $json, string $key, string $literal, ?string $only = null): ?string
    {
        $length = \strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            if ('"' !== $json[$i]) {
                continue;
            }
            $end = self::endOfString($json, $i);
            $isKey = '"'.$key.'"' === substr($json, $i, $end - $i + 1);
            $i = $end;
            if (!$isKey) {
                continue;
            }

            $colon = self::skipSpace($json, $i + 1);
            if (':' !== ($json[$colon] ?? '')) {
                continue; // a string value equal to the key
            }
            $start = self::skipSpace($json, $colon + 1);
            if (null !== $only && ($json[$start] ?? '') !== $only) {
                continue;
            }

            return substr($json, 0, $start).$literal.substr($json, self::endOfValue($json, $start) + 1);
        }

        return null;
    }

    private static function skipSpace(string $json, int $at): int
    {
        while ($at < \strlen($json) && '' !== $json[$at] && ctype_space($json[$at])) {
            ++$at;
        }

        return $at;
    }

    /**
     * @return int the position of the closing quote
     */
    private static function endOfString(string $json, int $start): int
    {
        $length = \strlen($json);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ('\\' === $json[$i]) {
                ++$i;
            } elseif ('"' === $json[$i]) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * @return int the position of the last character of the value that starts at $start
     */
    private static function endOfValue(string $json, int $start): int
    {
        $length = \strlen($json);
        if ('"' === ($json[$start] ?? '')) {
            return self::endOfString($json, $start);
        }
        if (!\in_array($json[$start] ?? '', ['{', '['], true)) {
            $i = $start;
            while ($i + 1 < $length && !\in_array($json[$i + 1], [',', '}', ']', "\n", "\r"], true)) {
                ++$i;
            }

            return $i;
        }

        $depth = 0;
        for ($i = $start; $i < $length; ++$i) {
            $char = $json[$i];
            if ('"' === $char) {
                $i = self::endOfString($json, $i);
            } elseif ('{' === $char || '[' === $char) {
                ++$depth;
            } elseif ('}' === $char || ']' === $char) {
                if (0 === --$depth) {
                    return $i;
                }
            }
        }

        return $length - 1;
    }
}
