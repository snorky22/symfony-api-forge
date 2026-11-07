<?php

namespace Snorky22\ApiForge\Tests\Dev;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

final class LenientJsonDecoderTest extends TestCase
{
    public static function documentationJsonProvider(): iterable
    {
        yield 'trailing commas' => ['{"a": [1, 2,], "b": 3,}', ['a' => [1, 2], 'b' => 3]];
        yield 'object placeholders' => ['[{"a": 1}, {...}, {...},]', [['a' => 1]]];
        yield 'array placeholder' => ['{"a": [...], "b": 1}', ['a' => [], 'b' => 1]];
        yield 'bare ellipsis' => ['{"a": 1, ...}', ['a' => 1]];
        yield 'leading placeholder' => ['[{...}, {"a": 1}]', [['a' => 1]]];
        yield 'line comment' => ["{\"a\": 1, // the \"a\" value\n\"b\": 2}", ['a' => 1, 'b' => 2]];
        yield 'block comment' => ['{"a": /* id */ 1}', ['a' => 1]];
    }

    #[DataProvider('documentationJsonProvider')]
    public function testDecodesDocumentationJson(string $json, array $expected): void
    {
        self::assertSame($expected, (new LenientJsonDecoder())->decode($json));
    }

    public function testLeavesStringContentsUntouched(): void
    {
        $json = '{"a": "x, ]", "b": "{...} // not a comment", "c": "say \"hi\", ..."}';

        self::assertSame(
            ['a' => 'x, ]', 'b' => '{...} // not a comment', 'c' => 'say "hi", ...'],
            (new LenientJsonDecoder())->decode($json),
        );
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        (new LenientJsonDecoder())->decode('{"a": }');
    }

    public function testExplainsASingleQuotedString(): void
    {
        $decoder = new LenientJsonDecoder();
        $json = "{\n  // it's a comment\n  \"a\": \"it's fine\",\n  /* it's\n fine */\n  \"b\": 'x.array'\n}";

        try {
            $decoder->decode($json);
            self::fail('Not JSON');
        } catch (\JsonException $e) {
            self::assertSame('line 6: a single-quoted string, JSON strings use double quotes', $decoder->explain($json, $e));
        }
    }

    public function testExplainsOtherErrorsWithTheJsonMessage(): void
    {
        $decoder = new LenientJsonDecoder();

        try {
            $decoder->decode('{"a": }');
            self::fail('Not JSON');
        } catch (\JsonException $e) {
            self::assertSame('Syntax error', $decoder->explain('{"a": }', $e));
        }
    }
}
