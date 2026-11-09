<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * The words of an API documentation page that say what a field is: its type (and the aliases of the types: int, bool,
 * list, map…), its format (date-time, uuid, uri…), and its flags (required, optional, nullable, deprecated, read-only…).
 *
 * parse() reads what follows the name of a field, in any order and whatever the punctuation ("string | null required",
 * "(string, required)", "array of strings", "string[]", "string (date-time), optional"). Every word has to be known: a
 * sentence that happens to hold a type word is not taken for a field.
 */
final class DocsVocabulary
{
    /** type word => type (string, integer, number, boolean, object, array) */
    public const TYPES = [
        'string' => 'string', 'str' => 'string', 'text' => 'string',
        'integer' => 'integer', 'int' => 'integer', 'long' => 'integer',
        'number' => 'number', 'float' => 'number', 'double' => 'number', 'decimal' => 'number', 'numeric' => 'number',
        'boolean' => 'boolean', 'bool' => 'boolean',
        'object' => 'object', 'map' => 'object', 'dictionary' => 'object', 'hash' => 'object',
        'array' => 'array', 'list' => 'array',
    ];

    /** format word => format: a string with a known format */
    public const FORMATS = [
        'date' => 'date', 'datetime' => 'date-time', 'date-time' => 'date-time', 'timestamp' => 'date-time', 'time' => 'time',
        'uuid' => 'uuid', 'uri' => 'uri', 'url' => 'uri', 'email' => 'email', 'ipv4' => 'ipv4', 'ipv6' => 'ipv6', 'hostname' => 'hostname',
    ];

    public const REQUIRED = ['required', 'mandatory'];
    public const OPTIONAL = ['optional'];
    public const NULLABLE = ['nullable', 'null'];
    public const DEPRECATED = ['deprecated'];
    public const READ_ONLY = ['readonly', 'read-only'];
    public const WRITE_ONLY = ['writeonly', 'write-only'];
    /** badges a page shows next to a field ("early access") */
    public const BADGES = ['early', 'access', 'beta', 'alpha', 'preview', 'new', 'experimental'];
    /** words that join the others ("string or null", "array of strings") */
    private const JOINERS = ['or', 'and', 'of'];

    private const PLURALS = ['strings' => 'string', 'integers' => 'integer', 'ints' => 'integer', 'numbers' => 'number', 'booleans' => 'boolean', 'bools' => 'boolean', 'objects' => 'object', 'arrays' => 'array'];

    /**
     * @return ?array{type: string, itemType: ?string, nullable: bool, required: bool, optional: bool, format: ?string, deprecated: bool, readOnly: bool, writeOnly: bool}
     *                null when a word is not of the vocabulary, or no type is given
     */
    public static function parse(string $text): ?array
    {
        $text = strtolower(trim($text));
        $info = ['type' => null, 'itemType' => null, 'nullable' => false, 'required' => false, 'optional' => false, 'format' => null, 'deprecated' => false, 'readOnly' => false, 'writeOnly' => false];

        // The groups in parentheses: flags and types, or else a format ("(uri)") the page names its own way
        $unknown = [];
        $text = (string) preg_replace_callback('/\(([^)]*)\)/', static function (array $m) use (&$info, &$unknown): string {
            $words = self::words($m[1]);
            if ([] !== $words && self::apply($words, $info)) {
                return ' ';
            }
            $unknown[] = trim($m[1]);

            return ' ';
        }, $text);

        if (!self::apply(self::words($text), $info) || null === $info['type']) {
            return null;
        }
        if (null === $info['format'] && [] !== $unknown && '' !== $unknown[0]) {
            $info['format'] = $unknown[0];
        }
        if ($info['required'] && $info['optional']) {
            return null;
        }

        return $info;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return preg_split('/[\s,;|]+/', trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param list<string>         $words
     * @param array<string, mixed> $info
     *
     * @return bool false when a word is not of the vocabulary
     */
    private static function apply(array $words, array &$info): bool
    {
        $count = \count($words);
        for ($i = 0; $i < $count; ++$i) {
            $word = $words[$i];
            if (1 === preg_match('/^([a-z]+)\[\]$/', $word, $m) && isset(self::TYPES[$m[1]])) {
                $info['type'] ??= 'array';
                $info['itemType'] = self::TYPES[$m[1]];
            } elseif (1 === preg_match('/^(array|list)<([a-z]+)>$/', $word, $m) && isset(self::TYPES[$m[2]])) {
                $info['type'] ??= 'array';
                $info['itemType'] = self::TYPES[$m[2]];
            } elseif ('of' === $word && 'array' === $info['type']) {
                // "array of strings": the items
                $next = $words[$i + 1] ?? '';
                $item = self::PLURALS[$next] ?? self::TYPES[$next] ?? null;
                if (null === $item) {
                    return false;
                }
                $info['itemType'] = $item;
                ++$i;
            } elseif (isset(self::TYPES[$word])) {
                $info['type'] ??= self::TYPES[$word];
            } elseif (isset(self::FORMATS[$word])) {
                $info['type'] ??= 'string';
                $info['format'] ??= self::FORMATS[$word];
            } elseif (\in_array($word, self::NULLABLE, true)) {
                $info['nullable'] = true;
            } elseif (\in_array($word, self::REQUIRED, true)) {
                $info['required'] = true;
            } elseif (\in_array($word, self::OPTIONAL, true)) {
                $info['optional'] = true;
            } elseif (\in_array($word, self::DEPRECATED, true)) {
                $info['deprecated'] = true;
            } elseif (\in_array($word, self::READ_ONLY, true)) {
                $info['readOnly'] = true;
            } elseif (\in_array($word, self::WRITE_ONLY, true)) {
                $info['writeOnly'] = true;
            } elseif (!\in_array($word, self::BADGES, true) && !\in_array($word, self::JOINERS, true)) {
                return false;
            }
        }

        return true;
    }
}
