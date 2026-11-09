<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Docs\DocsVocabulary;

final class DocsVocabularyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function descriptions(): iterable
    {
        yield 'type and required' => ['string required', ['type' => 'string', 'required' => true]];
        yield 'type only' => ['string', ['type' => 'string', 'required' => false]];
        yield 'null union' => ['string | null', ['type' => 'string', 'nullable' => true]];
        yield 'null, any order' => ['required null integer', ['type' => 'integer', 'nullable' => true, 'required' => true]];
        yield 'nullable' => ['string nullable', ['type' => 'string', 'nullable' => true]];
        yield 'or null' => ['string or null', ['type' => 'string', 'nullable' => true]];
        yield 'optional' => ['string optional', ['type' => 'string', 'optional' => true, 'required' => false]];
        yield 'mandatory' => ['boolean mandatory', ['type' => 'boolean', 'required' => true]];
        yield 'aliases' => ['int', ['type' => 'integer']];
        yield 'aliases, bool' => ['bool required', ['type' => 'boolean', 'required' => true]];
        yield 'aliases, float' => ['float', ['type' => 'number']];
        yield 'aliases, double' => ['double', ['type' => 'number']];
        yield 'aliases, map' => ['map', ['type' => 'object']];
        yield 'aliases, list' => ['list', ['type' => 'array']];
        yield 'format in parentheses' => ['string (date-time) required', ['type' => 'string', 'format' => 'date-time', 'required' => true]];
        yield 'own format' => ['string (iso-8601)', ['type' => 'string', 'format' => 'iso-8601']];
        yield 'format word' => ['uuid', ['type' => 'string', 'format' => 'uuid']];
        yield 'format word, timestamp' => ['timestamp required', ['type' => 'string', 'format' => 'date-time', 'required' => true]];
        yield 'all in parentheses' => ['(string, required)', ['type' => 'string', 'required' => true]];
        yield 'punctuation' => ['string, optional', ['type' => 'string', 'optional' => true]];
        yield 'array of' => ['array of strings', ['type' => 'array', 'itemType' => 'string']];
        yield 'array of, flags' => ['array of integers required', ['type' => 'array', 'itemType' => 'integer', 'required' => true]];
        yield 'brackets' => ['string[] required', ['type' => 'array', 'itemType' => 'string', 'required' => true]];
        yield 'generics' => ['list<object>', ['type' => 'array', 'itemType' => 'object']];
        yield 'deprecated and read-only' => ['string deprecated read-only', ['type' => 'string', 'deprecated' => true, 'readOnly' => true]];
        yield 'write-only' => ['string write-only', ['type' => 'string', 'writeOnly' => true]];
        yield 'badge' => ['array early access', ['type' => 'array']];
        yield 'two types: the first' => ['string | integer', ['type' => 'string']];
        yield 'upper case' => ['String Required', ['type' => 'string', 'required' => true]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('descriptions')]
    public function testReadsTheWordsThatSayWhatAFieldIs(string $text, array $expected): void
    {
        $info = DocsVocabulary::parse($text);

        self::assertNotNull($info, $text);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $info[$key], $text.': '.$key);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDescriptions(): iterable
    {
        yield 'no type' => ['required'];
        yield 'empty' => [''];
        yield 'a sentence' => ['must be a string'];
        yield 'an unknown word' => ['string mandatory-ish'];
        yield 'required and optional' => ['string required optional'];
        yield 'array of an unknown' => ['array of widgets'];
    }

    #[DataProvider('notDescriptions')]
    public function testRefusesWhatIsNotADescription(string $text): void
    {
        self::assertNull(DocsVocabulary::parse($text));
    }
}
