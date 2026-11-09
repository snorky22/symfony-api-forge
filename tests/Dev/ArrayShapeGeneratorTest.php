<?php

namespace Snorky22\ApiForge\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Docs\DocsEntry;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

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

    public function testDocumentationDecidesOptionalNullEnumAndUnknownTypes(): void
    {
        $decoder = new LenientJsonDecoder();
        $entry = static fn (string $name, string $type, bool $nullable = false, bool $required = true, ?array $values = null) => new DocsEntry($name, $type, $nullable, $required, null, $values, \count($values ?? []), [], 1);
        $docs = [
            'data' => $entry('data', 'array'),
            'data[].kind' => $entry('kind', 'string', values: ['a', "it's"]),
            'data[].name' => $entry('name', 'string', nullable: true),
            'data[].note' => $entry('note', 'string', required: false),
            'data[].custom_data' => $entry('custom_data', 'object', nullable: true),
            'data[].rate' => $entry('rate', 'number', nullable: true),
            'data[].tags' => $entry('tags', 'array'),
            'data[].count' => $entry('count', 'integer'),
        ];
        $json = '{"data": [{"kind": "a", "name": "x", "note": "n", "custom_data": null, "rate": null, "tags": [], "count": 1}, {"kind": "a", "name": "y", "note": "m", "custom_data": [], "rate": null, "tags": [], "count": 2}]}';

        $shape = (new ArrayShapeGenerator())->generate($decoder->decode($json, false), false, $docs);

        self::assertSame(<<<'SHAPE'
            array{
                data: list<array{
                    kind: 'a'|'it\'s',
                    name: ?string,
                    note?: string,
                    custom_data: array<string, mixed>|null,
                    rate: int|float|null,
                    tags: list<mixed>,
                    count: int,
                }>,
            }
            SHAPE, $shape);
    }

    public function testDocumentationMakesAKeyRequiredEvenWhenAnExampleLacksIt(): void
    {
        $docs = ['data[].a' => new DocsEntry('a', 'string', false, true, null, null, 0, [], 1)];
        $shape = (new ArrayShapeGenerator())->generate((new LenientJsonDecoder())->decode('{"data": [{"a": "x"}, {"b": 1}]}', false), false, $docs);

        self::assertStringContainsString("a: string,\n", $shape);
        self::assertStringContainsString("b?: int,\n", $shape);
    }

    public function testTheItemTypeOfAnArrayTypesAnEmptyList(): void
    {
        $docs = [
            'ids' => new DocsEntry('ids', 'array', false, true, null, null, 0, [], 1, 'string'),
            'tags' => new DocsEntry('tags', 'array', false, true, null, null, 0, [], 1),
            'rows' => new DocsEntry('rows', 'array', false, true, null, null, 0, [], 1, 'object'),
        ];

        $shape = (new ArrayShapeGenerator())->generate((new LenientJsonDecoder())->decode('{"ids": [], "tags": [], "rows": []}', false), false, $docs);

        self::assertStringContainsString('ids: list<string>,', $shape);
        self::assertStringContainsString('tags: list<mixed>,', $shape);
        self::assertStringContainsString('rows: list<array<string, mixed>>,', $shape);
    }
}
