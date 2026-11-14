<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Docs;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Docs\DocsCapture;
use Snorky22\ApiForge\Dev\Api\Docs\DocsHtml;

final class DocsHtmlTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/docs-html-paddle-create-transaction';

    /**
     * @return array<string, list<string>> "parent.name" => the types found at that path
     */
    private static function paths(string $html): array
    {
        $paths = [];
        foreach ((new DocsHtml())->fields($html) as $field) {
            $paths[implode('.', [...$field['parents'], $field['name']])][] = $field['type'];
        }

        return $paths;
    }

    public function testTheNestingOfARealPageComesFromItsHtml(): void
    {
        $paths = self::paths((string) file_get_contents(self::FIXTURE.'.html'));

        // The text of this page puts "allowances" after "requires_payment_method": it is a field of the price
        self::assertSame(['array', 'array'], $paths['items.price.allowances']);
        self::assertSame(['string', 'string'], $paths['items.price.allowances.amount']);
        self::assertSame(['string | null'], $paths['checkout.url']);
        self::assertSame(['object | null', 'object | null'], $paths['items.price.trial_period.unit_price']);
        self::assertSame(['string', 'string'], $paths['items.price.trial_period.unit_price.amount']);
        // Three alternatives of an item: a catalog price, or a price with a product id, or with a product
        self::assertCount(3, $paths['items.quantity']);
        self::assertCount(1, $paths['items.price_id']);
        self::assertCount(1, $paths['items.price.product.tax_category']);
        // Sentences quoting names ("Requires <code>recur</code>. <code>null</code> if…") are not fields
        self::assertArrayNotHasKey('discount.maximum_recurring_intervals.recur', $paths);
        self::assertCount(107, (new DocsHtml())->fields((string) file_get_contents(self::FIXTURE.'.html')));
    }

    public function testTheEntriesOfTheTextGetTheirParents(): void
    {
        $entries = (new DocsCapture())->parse((string) file_get_contents(self::FIXTURE.'.md'));
        $placed = (new DocsHtml())->place($entries, (string) file_get_contents(self::FIXTURE.'.html'));

        self::assertCount(\count($entries), $placed);
        self::assertSame([], array_filter($placed, static fn ($entry) => null === $entry->parents));
        $allowances = array_values(array_filter($placed, static fn ($entry) => 'allowances' === $entry->name));
        self::assertSame(['items', 'price'], $allowances[0]->parents);
    }

    public function testASecondSectionDoesNotFallUnderTheLastFieldOfTheFirst(): void
    {
        $html = <<<'HTML'
            <section><h2>Request</h2>
              <div><div><span>items</span><span>array</span></div>
                <div><div><div><span>quantity</span><span>integer</span></div></div></div></div>
            </section>
            <section><div><h2>Response</h2><div>
              <div><div><span>id</span><span>string</span></div><p>Paddle ID, prefixed with <code>txn_</code>.</p></div>
              <div><div><span>status</span><span>string</span></div></div>
            </div></div></section>
            HTML;

        self::assertSame(['items', 'items.quantity', 'id', 'status'], array_keys(self::paths($html)));
    }

    public function testTheTextIsMatchedWhereItFitsInALargerPage(): void
    {
        $html = '<div><div><span>status</span><span>string</span></div></div>'
            .'<div><div><span>data</span><span>object</span></div><div>'
            .'<div><div><span>status</span><span>string</span></div></div>'
            .'<div><div><span>total</span><span>string</span></div></div></div></div>';
        $entries = (new DocsCapture())->parse("datarequired\n\ndataobject\n\nstatusstring\n\ntotalstring\n");
        $placed = (new DocsHtml())->place($entries, $html);

        self::assertSame(['data', 'status', 'total'], array_map(static fn ($entry) => $entry->name, $placed));
        self::assertSame([[], ['data'], ['data']], array_map(static fn ($entry) => $entry->parents, $placed));
    }
}
