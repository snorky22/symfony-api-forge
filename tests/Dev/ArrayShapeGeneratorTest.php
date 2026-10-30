<?php

namespace App\Tests\Dev;

use App\Dev\ArrayShapeGenerator;
use PHPUnit\Framework\TestCase;

final class ArrayShapeGeneratorTest extends TestCase
{
    private function generate(string $json): string
    {
        return (new ArrayShapeGenerator())->generate(json_decode($json, false, 512, \JSON_THROW_ON_ERROR));
    }

    public function testScalarsAndNestedShape(): void
    {
        $expected = <<<'SHAPE'
            array{
                id: int,
                price: float,
                name: string,
                paid: bool,
                deleted_at: mixed,
                urls: array{
                    receipt: string,
                },
            }
            SHAPE;

        self::assertSame($expected, $this->generate('{"id": 1, "price": 9.99, "name": "x", "paid": true, "deleted_at": null, "urls": {"receipt": "u"}}'));
    }

    public function testListElementsAreMerged(): void
    {
        $expected = <<<'SHAPE'
            list<array{
                a: int|string,
                b: ?string,
                c?: int,
            }>
            SHAPE;

        self::assertSame($expected, $this->generate('[{"a": 1, "b": null, "c": 1}, {"a": "x", "b": "y"}]'));
    }

    public function testEmptyContainers(): void
    {
        self::assertSame("array{\n    o: array<string, mixed>,\n    l: list<mixed>,\n}", $this->generate('{"o": {}, "l": []}'));
    }

    public function testNullableShape(): void
    {
        self::assertSame("list<array{\n    a: int,\n}|null>", $this->generate('[{"a": 1}, null]'));
    }

    public function testKeysThatAreNotIdentifiersAreQuoted(): void
    {
        $expected = <<<'SHAPE'
            array{
                'order-items': int,
                42: int,
                'it\'s': int,
            }
            SHAPE;

        self::assertSame($expected, $this->generate(<<<'JSON'
            {"order-items": 1, "42": 2, "it's": 3}
            JSON));
    }

    public function testDocBlock(): void
    {
        $expected = <<<'DOC'
            /**
             * @phpstan-type Item array{
             *     id: int,
             * }
             */
            DOC;

        self::assertSame($expected, (new ArrayShapeGenerator())->docBlock(json_decode('{"id": 1}'), 'phpstan-type Item'));
    }
}
