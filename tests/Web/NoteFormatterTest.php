<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Web\NoteFormatter;

final class NoteFormatterTest extends TestCase
{
    public function testANoteIsLocatedByItsFileAndPart(): void
    {
        $location = NoteFormatter::locate('/app/config/api/shop/_webhooks.yaml: event "transaction.completed": docs: "custom_data" (data.custom_data) shows no fields', '/app');

        self::assertSame(['group' => '_webhooks.yaml › event transaction.completed', 'file' => '_webhooks.yaml', 'part' => 'event transaction.completed', 'text' => 'docs: "custom_data" (data.custom_data) shows no fields'], $location);
        self::assertSame('prices.yaml › operation list', NoteFormatter::locate('/app/config/api/shop/prices.yaml: operation "list": response: x', '/app')['group']);
        self::assertSame('_api.yaml', NoteFormatter::locate('/app/config/api/shop/_api.yaml: base URI looks like a sandbox', '/app')['group']);
    }

    public function testKindAndSubject(): void
    {
        self::assertSame('Example value missing', NoteFormatter::kind('docs: "trial_period" (x) shows no fields in the example (null or empty)')['label']);
        self::assertSame('Counted differently', NoteFormatter::kind('docs: "status" is described 3 time(s) … nothing is applied to "status"')['label']);
        self::assertSame('Guess to check', NoteFormatter::kind('resource "transactions" derived from the event name')['label']);
        self::assertSame('trial_period', NoteFormatter::subject('docs: "trial_period" (data.items[].price.trial_period) shows no fields'));
        self::assertSame('data.items[].price', NoteFormatter::subject('marks values that are null in the example (data.items[].price)'));
    }

    public function testHighlightsNamesFilesAndLinesAndEscapes(): void
    {
        self::assertSame(
            'Add <code>id</code> to <code>webhook-x.json</code>, for example <code>&quot;type&quot;: &quot;custom&quot;</code> (<code>data.items[].price</code>, <span class="text-secondary">lines 49, 73</span>) &lt;b&gt;',
            NoteFormatter::highlight('Add "id" to webhook-x.json, for example "type": "custom" (data.items[].price, lines 49, 73) <b>'),
        );
    }
}
