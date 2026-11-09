<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Web\JsonText;

final class JsonTextTest extends TestCase
{
    public function testReplacesAnObjectAndKeepsTheRestOfTheFile(): void
    {
        $json = <<<'JSON'
            {
              "name": "A",   // a comment
              "custom_data": {
                "features": {"sso": true, "text": "a } and \" b"},
                "tags": ["x", "y"]
              },
              "status": "active"
            }
            JSON;

        $new = JsonText::replaceValue($json, 'custom_data', '{}', '{');

        self::assertSame(str_replace(
            "{\n    \"features\": {\"sso\": true, \"text\": \"a } and \\\" b\"},\n    \"tags\": [\"x\", \"y\"]\n  }",
            '{}',
            "{\n  \"name\": \"A\",   // a comment\n  \"custom_data\": {\n    \"features\": {\"sso\": true, \"text\": \"a } and \\\" b\"},\n    \"tags\": [\"x\", \"y\"]\n  },\n  \"status\": \"active\"\n}",
        ), $new);
        self::assertJson((string) preg_replace('#//[^\n]*#', '', (string) $new));
        self::assertSame('{}', json_decode((string) preg_replace('#//[^\n]*#', '', (string) $new))->custom_data instanceof \stdClass ? '{}' : '?');
    }

    public function testReplacesScalarsListsAndNestedKeys(): void
    {
        self::assertSame('{"a": 1, "b": "x"}', JsonText::replaceValue('{"a": 1, "b": null}', 'b', '"x"'));
        self::assertSame('{"a": [1], "b": 2}', JsonText::replaceValue('{"a": [], "b": 2}', 'a', '[1]'));
        self::assertSame('{"x": {"deep": {"k": "v"}}}', JsonText::replaceValue('{"x": {"deep": {"k": 12.5}}}', 'k', '"v"'));
        self::assertSame("{\"a\": true,\n \"b\": 2}", JsonText::replaceValue("{\"a\": 1,\n \"b\": 2}", 'a', 'true'));
    }

    public function testOnlyKeysAreReplacedNotValuesAndOnlyOfTheExpectedKind(): void
    {
        // The string "k" as a value, then the key "k"
        self::assertSame('{"a": "k", "k": {}}', JsonText::replaceValue('{"a": "k", "k": {"z": 1}}', 'k', '{}'));
        // The first "k" is a scalar, the expected kind is an object: the next one is replaced
        self::assertSame('{"k": 1, "n": {"k": {}}}', JsonText::replaceValue('{"k": 1, "n": {"k": {"z": 1}}}', 'k', '{}', '{'));
        self::assertNull(JsonText::replaceValue('{"k": 1}', 'k', '{}', '{'));
        self::assertNull(JsonText::replaceValue('{"a": 1}', 'missing', '{}'));
    }
}
