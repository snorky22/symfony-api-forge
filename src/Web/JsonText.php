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

    /**
     * Adds "key": literal next to the first "after": … of the text, in the same object; or, with $asNewItem, as a new
     * item of the list holding that object: a copy of it, with "key" instead of "after" (another form of the item).
     *
     * @return ?string the new text, null when "after" is not found (or, with $asNewItem, is not in an item of a list)
     */
    public static function addMember(string $json, string $after, string $key, string $literal, bool $asNewItem = false, bool $before = false): ?string
    {
        $found = self::findKey($json, $after);
        if (null === $found) {
            return null;
        }
        [$keyStart, $valueEnd] = $found;
        $lineStart = strrpos(substr($json, 0, $keyStart), "\n");
        $indent = false === $lineStart ? '' : (string) preg_replace('/\S.*$/s', '', substr($json, $lineStart + 1, $keyStart - $lineStart - 1));
        $member = '"'.$key.'": '.$literal;
        if ($before && !$asNewItem) {
            return substr($json, 0, $keyStart).$member.','.('' !== $indent ? "\n".$indent : ' ').substr($json, $keyStart);
        }
        if (!$asNewItem) {
            return substr($json, 0, $valueEnd + 1).','.("\n" === ($json[$valueEnd + 1] ?? '') || '' !== $indent ? "\n".$indent : ' ').$member.substr($json, $valueEnd + 1);
        }

        // The object holding "after", and the list holding that object
        $stack = [];
        for ($i = 0; $i < $keyStart; ++$i) {
            $char = $json[$i];
            if ('"' === $char) {
                $i = self::endOfString($json, $i);
            } elseif ('{' === $char || '[' === $char) {
                $stack[] = $i;
            } elseif ('}' === $char || ']' === $char) {
                array_pop($stack);
            }
        }
        $objectStart = array_pop($stack);
        $listStart = array_pop($stack);
        if (null === $objectStart || null === $listStart || '{' !== $json[$objectStart] || '[' !== $json[$listStart]) {
            return null;
        }
        $objectEnd = self::endOfValue($json, $objectStart);
        $item = substr($json, $objectStart, $keyStart - $objectStart).$member.substr($json, $valueEnd + 1, $objectEnd - $valueEnd);
        $itemLine = strrpos(substr($json, 0, $objectStart), "\n");
        $itemIndent = false === $itemLine ? '' : (string) preg_replace('/\S.*$/s', '', substr($json, $itemLine + 1, $objectStart - $itemLine - 1));

        return substr($json, 0, $objectEnd + 1).",\n".$itemIndent.$item.substr($json, $objectEnd + 1);
    }

    /**
     * Whether the first "key": … of the text is in an object that is an item of a list.
     */
    public static function isInListItem(string $json, string $key): bool
    {
        return null !== self::addMember($json, $key, '_probe', 'null', true);
    }

    /**
     * @return ?array{int, int} the position of the first "key" (its opening quote) and of the end of its value
     */
    /**
     * Whether the value of the first "outer" key of the text holds the key "inner" (an object and one of its fields).
     */
    public static function holds(string $json, string $outer, string $inner): bool
    {
        $found = self::findKey($json, $outer);
        if (null === $found) {
            return false;
        }
        $inside = self::findKey(substr($json, $found[0], $found[1] - $found[0] + 1), $inner);

        return null !== $inside;
    }

    private static function findKey(string $json, string $key): ?array
    {
        $length = \strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            if ('"' !== $json[$i]) {
                continue;
            }
            $end = self::endOfString($json, $i);
            $isKey = '"'.$key.'"' === substr($json, $i, $end - $i + 1);
            $start = $i;
            $i = $end;
            if (!$isKey) {
                continue;
            }
            $colon = self::skipSpace($json, $i + 1);
            if (':' !== ($json[$colon] ?? '')) {
                continue;
            }
            $valueStart = self::skipSpace($json, $colon + 1);

            return [$start, self::endOfValue($json, $valueStart)];
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
