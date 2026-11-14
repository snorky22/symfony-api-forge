<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Docs;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Docs\DocsAnnotator;
use Snorky22\ApiForge\Dev\Api\Docs\DocsCapture;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

final class DocsAnnotatorTest extends TestCase
{
    private function annotate(string $json): \Snorky22\ApiForge\Dev\Api\Docs\DocsAlignment
    {
        $entries = (new DocsCapture())->parse((string) file_get_contents(__DIR__.'/../../../Fixtures/docs-capture-example.md'));

        return (new DocsAnnotator())->annotate($entries, [(new LenientJsonDecoder())->decode($json, false)]);
    }

    private const COMPLETE = '{"data": [{"id": "p1", "type": "standard", "name": null, "trial_period": {"interval": "day", "unit_price": {"amount": "1", "currency_code": "USD"}}, "unit_price": {"amount": "1", "currency_code": "EUR"}, "custom_data": {}}], "meta": {"next": "https://x", "estimated_total": 1}}';

    public function testAlignsAnExampleThatHoldsWhatTheDocsDescribe(): void
    {
        $alignment = $this->annotate(self::COMPLETE);

        self::assertSame([
            'data', 'data[].id', 'data[].type', 'data[].name', 'data[].trial_period', 'data[].trial_period.interval',
            'data[].trial_period.unit_price', 'data[].trial_period.unit_price.amount', 'data[].trial_period.unit_price.currency_code',
            'data[].unit_price', 'data[].unit_price.amount', 'data[].unit_price.currency_code', 'data[].custom_data',
            'meta', 'meta.next', 'meta.estimated_total',
        ], array_keys($alignment->hints));
        // The two unit_price of the docs went to the right ones, in the order of the page
        self::assertTrue($alignment->hints['data[].unit_price.currency_code']->required);
        self::assertFalse($alignment->hints['data[].trial_period.unit_price.currency_code']->required);
        self::assertTrue($alignment->hints['data[].trial_period.unit_price']->nullable);
        self::assertSame(['custom', 'standard'], $alignment->hints['data[].type']->values);
    }

    public function testWhatTheDocsDescribeAndTheExampleDoesNotIsSetAside(): void
    {
        $alignment = $this->annotate(self::COMPLETE);

        // "allowances" is not in the example: its fields and "product" (usage_unit_id, amount, id, name…) don't count
        self::assertSame(
            ['docs: "allowances" (line 89) is not in the example: add it to the example (in the docs it sits between "custom_data" and "meta")'],
            array_values(array_filter($alignment->notes, static fn (string $note) => str_starts_with($note, 'docs: "'))),
        );
        self::assertSame('data[].id', array_keys($alignment->hints)[1]);
        self::assertSame(5, $alignment->hints['data[].id']->line);
    }

    public function testNamesThatDocsAndExampleCountDifferentlyAreNotApplied(): void
    {
        // The trial price is missing from the example: unit_price appears twice in the docs, once in the example
        $alignment = $this->annotate('{"data": [{"id": "p1", "type": "standard", "name": "x", "trial_period": {"interval": "day"}, "unit_price": {"amount": "1", "currency_code": "EUR"}, "custom_data": null}], "meta": {"next": "u"}}');

        self::assertArrayNotHasKey('data[].unit_price', $alignment->hints);
        self::assertArrayNotHasKey('data[].unit_price.amount', $alignment->hints);
        self::assertArrayHasKey('data[].id', $alignment->hints);
        self::assertArrayHasKey('meta.next', $alignment->hints);
        self::assertContains('docs: "unit_price" is described 2 time(s) (line 49, 73) and found 1 time(s) in the example: add the missing ones to the example, nothing is applied to "unit_price"', $alignment->notes);
    }

    public function testReportsWhatTheExampleHasAndTheDocsDoNot(): void
    {
        $alignment = $this->annotate('{"data": [{"id": "p1", "surprise": 1}]}');

        self::assertContains('example: "surprise" (data[].surprise) is not in the docs: complete the docs page text, or remove it from the example', $alignment->notes);
    }

    public function testReportsATypeThatDiffersFromTheDocs(): void
    {
        $alignment = $this->annotate('{"data": [{"id": 5, "custom_data": []}]}');

        self::assertContains('docs: data[].id is string in the docs (line 5), int in the example', $alignment->notes);
        self::assertContains('docs: data[].custom_data is object in the docs (line 85), another type in the example', $alignment->notes);
    }

    public function testReportsAFieldTheExampleHoldsUnderAnotherParent(): void
    {
        // The docs put "c" under "a"; the example holds it under "b", the count is right but the place is not
        $docs = "aobjectrequired\n\ncstringrequired\n\nbobjectrequired\n\ndstringrequired\n";
        $alignment = (new DocsAnnotator())->annotate((new DocsCapture())->parse($docs), [(new LenientJsonDecoder())->decode('{"a": null, "b": {"c": "1", "d": "2"}}', false)]);

        self::assertArrayNotHasKey('b.c', $alignment->hints);
        self::assertArrayHasKey('b.d', $alignment->hints);
        self::assertSame(['docs: "c" (line 3) has the right count in the example but no place in it: its parent is not the one of the docs, check where the example holds it'], array_values(array_filter($alignment->notes, static fn (string $note) => str_contains($note, '"c"'))));
    }

    public function testAnExampleThatMatchesNothingGivesNothing(): void
    {
        $alignment = (new DocsAnnotator())->annotate((new DocsCapture())->parse('foo'), [(new LenientJsonDecoder())->decode('{"a": 1}', false)]);

        self::assertSame([], $alignment->hints);
    }

    private function notes(string $docs, string $json): array
    {
        return (new DocsAnnotator())->annotate((new DocsCapture())->parse($docs), [(new LenientJsonDecoder())->decode($json, false)])->notes;
    }

    public function testAFreeFormObjectTheExampleFillsIsOneNoteNotOnePerKey(): void
    {
        $docs = "namestringrequired\n\ncustom_dataobject | null\n\nstatusstringrequired\n";
        $json = '{"name": "A", "custom_data": {"features": {"sso": true, "route": false}, "tags": ["x"]}, "status": "active"}';

        $notes = $this->notes($docs, $json);

        self::assertSame(['example: "custom_data" (custom_data) is a free-form object in the docs (no field is described), but the example gives it fields (features, tags): replace its value with {} in the example'], $notes);
    }

    public function testFieldsOfAnObjectTheExampleShowsEmptyAreOneNote(): void
    {
        $docs = "idstringrequired\n\nimport_metaobject | nullrequired\n\nexternal_idstring | null\n\nimported_fromstringrequired\n\nstatusstringrequired\n";
        $json = '{"id": "p", "import_meta": null, "status": "a"}';

        $notes = $this->notes($docs, $json);

        self::assertSame(['docs: "import_meta" (import_meta) shows no fields in the example (null or empty), but the docs describe external_id, imported_from: give it an example value'], $notes);
    }

    public function testACountThatIncludesFieldsOfAnEmptyObjectSaysSo(): void
    {
        // trial_period is null: its unit_price may be the one of line 49 or 73, the page has no indentation, so nothing
        // is applied (no guess), and the note counts both and names the object to fill
        $alignment = $this->annotate('{"data": [{"id": "p1", "type": "standard", "name": "x", "trial_period": null, "unit_price": {"amount": "1", "currency_code": "EUR"}, "custom_data": null}], "meta": {"next": "u"}}');

        self::assertArrayNotHasKey('data[].unit_price', $alignment->hints);
        self::assertContains('docs: "unit_price" is described 2 time(s) (line 49, 73) and found 1 time(s) in the example: line 49 under an object the example shows empty (see its note): give that object an example value, nothing is applied to "unit_price"', $alignment->notes);
    }

    public function testAMissingFieldSaysWhereItSitsInTheDocsAndItsValues(): void
    {
        $docs = "descriptionstring | null\n\ntypestring\n\nValues\n\n*   `custom`\n\n*   `standard`\n\ntax_categorystringrequired\n";
        $json = '{"description": "d", "tax_category": "standard"}';

        $notes = $this->notes($docs, $json);

        self::assertSame(['docs: "type" (line 3) is not in the example: add it to the example (in the docs it sits between "description" and "tax_category"; values in the docs: custom, standard)'], $notes);
    }

    public function testANameTheDocsTypeTheSameEverywhereIsComparedWhenNotPaired(): void
    {
        // The Paddle expiry_month: "integer" in the docs, a text in the example, and the field could not be paired
        $entries = (new DocsCapture())->parse("cardobject | nullrequired\n\nexpiry_monthintegerrequired\n\nexpiry_monthintegerrequired\n");
        $alignment = (new DocsAnnotator())->annotate($entries, [(new LenientJsonDecoder())->decode('{"card": {"expiry_month": "12"}}', false)]);

        self::assertContains('docs: "expiry_month" is integer everywhere in the docs (line 3, 5), string in the example (card.expiry_month): fix the example', $alignment->notes);
    }

    /**
     * A page given as "parent.name type [required]" lines: the text and the parents the HTML would give.
     */
    private function annotateByPath(string $page, string $json): \Snorky22\ApiForge\Dev\Api\Docs\DocsAlignment
    {
        $text = '';
        $parents = [];
        foreach (array_filter(array_map('trim', explode("\n", $page))) as $line) {
            [$path, $type, $required] = explode(' ', $line.' ', 3);
            $names = explode('.', $path);
            $text .= array_pop($names).$type.('' !== trim($required) ? 'required' : '')."\n\nAbout it.\n\n";
            $parents[] = $names;
        }
        $entries = (new DocsCapture())->parse($text);
        foreach ($entries as $index => $entry) {
            $entries[$index] = $entry->withParents($parents[$index]);
        }

        return (new DocsAnnotator())->annotate($entries, [(new LenientJsonDecoder())->decode($json, false)]);
    }

    public function testWithTheParentsOfTheHtmlTheAlignmentIsByPath(): void
    {
        $alignment = $this->annotateByPath(<<<'PAGE'
            amount string required
            price object required
            price.amount string
            price.unit_price object
            price.unit_price.amount string required
            PAGE, '{"amount": "1", "price": {"amount": "2", "unit_price": {"amount": "3"}}}');

        self::assertSame([], $alignment->notes);
        self::assertTrue($alignment->hints['amount']->required);
        self::assertFalse($alignment->hints['price.amount']->required);
        self::assertTrue($alignment->hints['price.unit_price.amount']->required);
    }

    public function testANameInSomeAlternativesOnlyIsOptional(): void
    {
        $alignment = $this->annotateByPath(<<<'PAGE'
            items array required
            items.quantity integer required
            items.price_id string required
            items.quantity integer required
            items.price object required
            items.price.amount string required
            PAGE, '{"items": [{"quantity": 1, "price_id": "pri_1"}, {"quantity": 2, "price": {"amount": "3"}}]}');

        self::assertTrue($alignment->hints['items[].quantity']->required);
        self::assertFalse($alignment->hints['items[].price_id']->required);
        self::assertFalse($alignment->hints['items[].price']->required);
        self::assertTrue($alignment->hints['items[].price.amount']->required);
    }

    public function testAMissingFieldSaysInWhichObjectItSits(): void
    {
        $alignment = $this->annotateByPath(<<<'PAGE'
            items array required
            items.price object required
            items.price.name string
            items.price.tax_mode string
            items.price.allowances array
            items.price.allowances.amount string
            items.price.product_id string
            checkout object
            checkout.url string
            PAGE, '{"items": [{"price": {"name": "A", "tax_mode": "internal", "product_id": "pro_1"}}]}');

        self::assertSame([
            'docs: "allowances" (line 17) is not in the example: add it to the example (in the docs it sits between "tax_mode" and "product_id", inside items[].price)',
            'docs: "checkout" (line 29) is not in the example: add it to the example (in the docs it sits after "items")',
        ], $alignment->notes);
    }

    public function testByPathAnObjectTheExampleShowsEmptyIsOneNote(): void
    {
        $alignment = $this->annotateByPath(<<<'PAGE'
            id string required
            trial_period object
            trial_period.interval string required
            trial_period.frequency integer required
            PAGE, '{"id": "pri_1", "trial_period": null, "extra": 1}');

        self::assertSame([
            'example: "extra" (extra) is not in the docs: complete the docs page text, or remove it from the example',
            'docs: "trial_period" (trial_period) shows no fields in the example (null or empty), but the docs describe interval, frequency: give it an example value',
        ], $alignment->notes);
    }

    public function testByPathTheDocsOfAnObjectHeldInDataAreFound(): void
    {
        $alignment = $this->annotateByPath(<<<'PAGE'
            id string required
            status string required
            PAGE, '{"data": {"id": "txn_1", "status": "ready"}, "meta": {"request_id": "r"}}');

        self::assertSame(['data.id', 'data.status'], array_keys($alignment->hints));
        self::assertSame([], $alignment->notes);
    }
}
