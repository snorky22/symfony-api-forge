<?php

namespace Snorky22\ApiForge\Tests\Dev\Doctor;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Doctor\ShapeChecker;
use Snorky22\ApiForge\Dev\Doctor\ShapeDifference;

final class ShapeCheckerTest extends TestCase
{
    private const SHAPE = <<<'SHAPE'
        array{
            type: string,
            id: string,
            attributes: array{
                name: string,
                status: 'active'|'paused',
                total: int|float,
                pause: null|array{
                    mode: 'void'|'free',
                    resumes_at?: string,
                },
                tags: list<string>,
                urls: array{
                    receipt: string|null,
                },
                'order-items'?: list<array{id: int}>,
            },
        }
        SHAPE;

    /**
     * @return list<string>
     */
    private static function check(mixed $value, string $shape = self::SHAPE): array
    {
        return array_map(static fn (ShapeDifference $difference) => $difference->kind.': '.$difference, (new ShapeChecker())->check($shape, $value));
    }

    private static function order(array $attributes = []): array
    {
        return ['type' => 'orders', 'id' => '1', 'attributes' => array_replace([
            'name' => 'Lemonade', 'status' => 'active', 'total' => 199, 'pause' => null, 'tags' => ['a'], 'urls' => ['receipt' => null],
        ], $attributes)];
    }

    public function testMatchingValue(): void
    {
        self::assertSame([], self::check(self::order()));
        self::assertSame([], self::check(self::order(['total' => 1.5, 'pause' => ['mode' => 'free'], 'order-items' => [['id' => 1], ['id' => 2]]])));
    }

    public function testDifferences(): void
    {
        $order = self::order(['status' => 'archived', 'total' => '199', 'pause' => ['mode' => 'later'], 'tags' => 'a', 'urls' => [], 'color' => 'blue']);
        unset($order['attributes']['name']);

        self::assertSame([
            'missing: attributes.name: missing (required by the descriptor)',
            "value: attributes.status: 'archived' is not one of 'active'|'paused'",
            "type: attributes.total: '199', the descriptor expects int|float",
            "value: attributes.pause.mode: 'later' is not one of 'void'|'free'",
            "type: attributes.tags: 'a', the descriptor expects list<string>",
            'missing: attributes.urls.receipt: missing (required by the descriptor)',
            "new: attributes.color: new field ('blue'), not in the descriptor",
        ], self::check($order));
    }

    public function testListItemsShareTheirPath(): void
    {
        $differences = self::check([['id' => 1, 'extra' => 1], ['id' => 'x', 'extra' => 2], ['id' => 'y']], 'list<array{id: int}>');

        self::assertSame([
            'new: [].extra: new field (1), not in the descriptor',
            "type: [].id: 'x', the descriptor expects int",
        ], $differences);
    }

    public function testUnknownTypesAreNotChecked(): void
    {
        self::assertSame([], self::check(['a' => 'x'], 'array{a: Customer}'));
        self::assertSame([], self::check(['a' => 'x', 'b' => 1], 'array{a: string, ...}'));
        self::assertSame(['type: null, the descriptor expects array{a: string}'], self::check(null, 'array{a: string}'));
    }
}
