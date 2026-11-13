<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Import;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Import\ValueReview;

final class ValueReviewTest extends TestCase
{
    private static function review(string $json, string $what = 'object'): array
    {
        $review = [];
        ValueReview::add([json_decode($json)], $what, $review);

        return $review;
    }

    public function testAFieldWithTwoKindsOfValues(): void
    {
        // The Paddle import_meta: an object three times, a list of text once (a wrong choice in a Fix form)
        $review = self::review('{"items": [{"import_meta": ["text"]}, {"import_meta": {"external_id": "x"}}, {"import_meta": {"external_id": "y"}}, {"import_meta": {"external_id": "z"}}]}');

        self::assertSame(['object: "import_meta" (items[].import_meta) has two kinds of values in the example: a list (1 time), an object (3 times): one of them is probably wrong'], $review);
    }

    public function testNullsAndNumbersAreNotTwoKinds(): void
    {
        self::assertSame([], self::review('{"items": [{"a": null, "b": 1}, {"a": "x", "b": 1.5}]}'));
    }

    public function testAListOfListsOfObjects(): void
    {
        // The Paddle payments: a type file holding a list, used as one item
        self::assertSame(['object: "payments" (payments) is a list of lists in the example: when its item is described in a type file (["…array"]), that file must hold one item, not a list'], self::review('{"payments": [[{"amount": "1050"}]]}'));
        // Lists of numbers (coordinates) are fine
        self::assertSame([], self::review('{"points": [[1, 2], [3, 4]]}'));
    }
}
