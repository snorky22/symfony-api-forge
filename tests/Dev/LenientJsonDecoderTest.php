<?php

namespace App\Tests\Dev;

use App\Dev\LenientJsonDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
