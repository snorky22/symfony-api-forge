<?php

namespace Snorky22\ApiForge\Web;

/**
 * Edits the text of a PHP array example in place, as JsonText does for JSON: the file keeps its layout and comments.
 */
final class PhpText
{
    /**
     * Adds 'key' => literal next to the first 'anchor' => … of the text (after it, or before it), in the same array;
     * or, with $asNewItem, as a new item of the list holding that array: a copy of it, with 'key' instead of 'anchor'.
     *
     * @return ?string the new text, null when the anchor is not found (or, with $asNewItem, is not in an item of a list)
     */
    public static function addMember(string $php, string $anchor, string $key, string $literal, bool $asNewItem = false, bool $before = false): ?string
    {
        $found = self::findKey($php, $anchor);
        if (null === $found) {
            return null;
        }
        [$keyStart, $valueEnd] = $found;
        $lineStart = strrpos(substr($php, 0, $keyStart), "\n");
        $indent = false === $lineStart ? '' : (string) preg_replace('/\S.*$/s', '', substr($php, $lineStart + 1, $keyStart - $lineStart - 1));
        $member = "'".$key."' => ".$literal;
        if ($before && !$asNewItem) {
            return substr($php, 0, $keyStart).$member.",\n".$indent.substr($php, $keyStart);
        }
        if (!$asNewItem) {
            return substr($php, 0, $valueEnd + 1).','."\n".$indent.$member.substr($php, $valueEnd + 1);
        }

        // The array holding the anchor, and the list holding that array
        $stack = [];
        for ($i = 0; $i < $keyStart; ++$i) {
            $char = $php[$i];
            if ("'" === $char || '"' === $char) {
                $i = self::endOfString($php, $i);
            } elseif ('/' === $char && '/' === ($php[$i + 1] ?? '')) {
                $i = self::endOfLine($php, $i);
            } elseif ('[' === $char) {
                $stack[] = $i;
            } elseif (']' === $char) {
                array_pop($stack);
            }
        }
        $arrayStart = array_pop($stack);
        $listStart = array_pop($stack);
        // An item of a list, not the value of a key ('data' => [...]): what comes before it is "[" or ","
        $previous = rtrim(substr($php, 0, $arrayStart ?? 0));
        if (null === $arrayStart || null === $listStart || !\in_array(substr($previous, -1), ['[', ','], true)) {
            return null;
        }
        $arrayEnd = self::endOfValue($php, $arrayStart);
        $item = substr($php, $arrayStart, $keyStart - $arrayStart).$member.substr($php, $valueEnd + 1, $arrayEnd - $valueEnd);
        $itemLine = strrpos(substr($php, 0, $arrayStart), "\n");
        $itemIndent = false === $itemLine ? '' : (string) preg_replace('/\S.*$/s', '', substr($php, $itemLine + 1, $arrayStart - $itemLine - 1));
        $next = self::skipComma($php, $arrayEnd);

        return substr($php, 0, $next + 1).("," === $php[$next] ? '' : ',')."\n".$itemIndent.$item.(',' === $php[$next] ? ',' : '').substr($php, $next + 1);
    }

    /**
     * Whether the first 'key' => … of the text is in an array that is an item of a list.
     */
    public static function isInListItem(string $php, string $key): bool
    {
        return null !== self::addMember($php, $key, '_probe', 'null', true);
    }

    /**
     * @return ?array{int, int} the position of the first 'key' (its opening quote) and of the end of its value
     */
    /**
     * Whether the value of the first "outer" key of the text holds the key "inner" (an object and one of its fields).
     */
    public static function holds(string $php, string $outer, string $inner): bool
    {
        $found = self::findKey($php, $outer);
        if (null === $found) {
            return false;
        }
        $inside = self::findKey(substr($php, $found[0], $found[1] - $found[0] + 1), $inner);

        return null !== $inside;
    }

    private static function findKey(string $php, string $key): ?array
    {
        $length = \strlen($php);
        for ($i = 0; $i < $length; ++$i) {
            $char = $php[$i];
            if ('/' === $char && '/' === ($php[$i + 1] ?? '')) {
                $i = self::endOfLine($php, $i);
                continue;
            }
            if ("'" !== $char && '"' !== $char) {
                continue;
            }
            $end = self::endOfString($php, $i);
            $isKey = substr($php, $i + 1, $end - $i - 1) === $key;
            $start = $i;
            $i = $end;
            if (!$isKey) {
                continue;
            }
            $arrow = self::skipSpace($php, $i + 1);
            if ('=>' !== substr($php, $arrow, 2)) {
                continue;
            }

            return [$start, self::endOfValue($php, self::skipSpace($php, $arrow + 2))];
        }

        return null;
    }

    private static function skipSpace(string $php, int $at): int
    {
        while ($at < \strlen($php) && ctype_space($php[$at])) {
            ++$at;
        }

        return $at;
    }

    /**
     * @return int the position of the "," after the value ending at $end, or $end when there is none
     */
    private static function skipComma(string $php, int $end): int
    {
        $next = self::skipSpace($php, $end + 1);

        return ',' === ($php[$next] ?? '') ? $next : $end;
    }

    private static function endOfLine(string $php, int $start): int
    {
        $end = strpos($php, "\n", $start);

        return false === $end ? \strlen($php) - 1 : $end - 1;
    }

    private static function endOfString(string $php, int $start): int
    {
        $quote = $php[$start];
        $length = \strlen($php);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ('\\' === $php[$i]) {
                ++$i;
            } elseif ($quote === $php[$i]) {
                return $i;
            }
        }

        return $length - 1;
    }

    private static function endOfValue(string $php, int $start): int
    {
        $length = \strlen($php);
        $char = $php[$start] ?? '';
        if ("'" === $char || '"' === $char) {
            return self::endOfString($php, $start);
        }
        if ('[' !== $char) {
            $i = $start;
            while ($i + 1 < $length && !\in_array($php[$i + 1], [',', ']', "\n", "\r"], true)) {
                ++$i;
            }

            return $i;
        }
        $depth = 0;
        for ($i = $start; $i < $length; ++$i) {
            $c = $php[$i];
            if ("'" === $c || '"' === $c) {
                $i = self::endOfString($php, $i);
            } elseif ('/' === $c && '/' === ($php[$i + 1] ?? '')) {
                $i = self::endOfLine($php, $i);
            } elseif ('[' === $c) {
                ++$depth;
            } elseif (']' === $c && 0 === --$depth) {
                return $i;
            }
        }

        return $length - 1;
    }
}
