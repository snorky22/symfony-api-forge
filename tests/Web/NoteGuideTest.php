<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Web\NoteGuide;

final class NoteGuideTest extends TestCase
{
    private const FILES = ['body' => 'create-products.body.json', 'bodyDocs' => 'create-products.body.docs.md', 'response' => 'create-products.json', 'responseDocs' => 'create-products.docs.md'];

    private static function all(array $guide): string
    {
        return implode("\n", [$guide['title'], ...$guide['found'], (string) $guide['impact'], ...$guide['options']]);
    }

    public function testAFieldTheDocsHaveAndTheExampleLacks(): void
    {
        $guide = NoteGuide::describe('body docs: "type" (line 17) is not in the example: add it to the example (in the docs it sits between "description" and "tax_category"; values in the docs: custom, standard)', self::FILES);

        self::assertSame('"type" is in the docs, but missing from the current structure', $guide['title']);
        self::assertSame(['The docs describe "type" (line 17); it sits between "description" and "tax_category".', 'Its values: custom, standard.'], $guide['found']);
        self::assertStringContainsString('cannot read or send it with type help', (string) $guide['impact']);
        self::assertSame('Add "type" to create-products.body.json, for example "type": "custom", then import again.', $guide['options'][0]);
    }

    public function testAFieldTheExampleHasAndTheDocsLack(): void
    {
        $body = self::all(NoteGuide::describe('body example: "image_url" (image_url) is not in the docs: complete the docs page text, or remove it from the example', self::FILES));
        $response = self::all(NoteGuide::describe('example: "image_url" (data.image_url) is not in the docs: complete the docs page text, or remove it from the example', self::FILES));

        self::assertStringContainsString('open create-products.body.docs.md and paste the missing part', $body);
        self::assertStringContainsString('remove it from create-products.body.json', $body);
        self::assertStringContainsString('open create-products.docs.md and paste the missing part', $response);
        self::assertStringContainsString('remove it from create-products.json', $response);
    }

    public function testAFreeFormObjectAndAnEmptyParent(): void
    {
        $free = NoteGuide::describe('example: "custom_data" (data.custom_data) is a free-form object in the docs (no field is described), but the example gives it fields (features, tags): replace its value with {} in the example', self::FILES);
        $empty = NoteGuide::describe('docs: "import_meta" (data.import_meta) shows no fields in the example (null or empty), but the docs describe external_id, imported_from: give it an example value', self::FILES);

        self::assertSame('"custom_data" is free-form in the docs, but the current structure gives it fields', $free['title']);
        self::assertStringContainsString('"Make it free-form" writes {}', $free['options'][0]);
        self::assertSame('"import_meta" is empty in the current structure, so its fields have no type', $empty['title']);
        self::assertSame('The docs describe fields inside "import_meta" (data.import_meta): external_id, imported_from.', $empty['found'][0]);
        self::assertSame('Or write a real value for "import_meta" in create-products.json, then import again.', $empty['options'][1]);
    }

    public function testNamesCountedDifferentlySayWhyAndWhatToDo(): void
    {
        $emptied = NoteGuide::describe('docs: "interval" is described 2 time(s) (line 524, 545) and found 1 time(s) in the example: line 545 under an object the example shows empty (see its note): give that object an example value, nothing is applied to "interval"');
        $plain = NoteGuide::describe('docs: "unit_price" is described 2 time(s) (line 49, 73) and found 1 time(s) in the example: add the missing ones to the example, nothing is applied to "unit_price"', self::FILES);

        self::assertSame('"interval" appears 2 times in the docs, 1 in the current structure, because of an empty object', $emptied['title']);
        self::assertStringContainsString('no indentation', (string) $emptied['impact']);
        self::assertStringStartsWith('Give the empty object an example value', $emptied['options'][0]);
        self::assertSame('Add the missing "unit_price" to create-products.json (the docs lines say where), then import again.', $plain['options'][0]);
    }

    public function testCountsInTitles(): void
    {
        self::assertSame('3 fields are null in the current structure: type unknown', NoteGuide::describe('response: "mixed" marks values that are null in the example, their real type is unknown (data[].a, data[].b, data[].c)')['title']);
        self::assertSame('More than 10 fields are null in the current structure: type unknown', NoteGuide::describe('response: "mixed" marks values that are null in the example, their real type is unknown (a, b, c, d, e, f, g, h, i, j, …)')['title']);
        self::assertSame('2 other events assumed to carry the same object as "transaction.completed"', NoteGuide::describe('events: resource "transactions" set on transaction.paid, transaction.billed from the payload of "transaction.completed", by their name: check that their payloads hold the same object')['title']);
    }

    public function testEveryNoteHasATitleAndSomethingToDo(): void
    {
        foreach ([
            'query parameters are assumed optional',
            'path parameter {id} guessed from the example value "42"',
            'base URI: "https://sandbox.x.test/" looks like a sandbox: set the live host',
            'probe guessed: "photos.list" is a GET without parameters, check that it is harmless and cheap',
            'request body: every key is typed as required: mark the optional ones',
            'response is not JSON (line 2: a single-quoted string), ignored',
            'signature: default scheme (HMAC-SHA256 of the raw body, hex-encoded, in the X-Signature header), check it against the API docs',
            'resource "transactions" derived from the event name: rename the file and this value if the API calls it otherwise',
            'response: the objects of the response differ from the "transactions" object already described (from another example): the object is kept',
            'object: "import_meta" (items[].import_meta) has two kinds of values in the example: a list (1 time), an object (3 times): one of them is probably wrong',
            'object: "payments" (payments) is a list of lists in the example: when its item is described in a type file (["…array"]), that file must hold one item, not a list',
            'docs: "expiry_month" is integer everywhere in the docs (line 3, 5), string in the example (card.expiry_month): fix the example',
            'something new the guide does not know',
        ] as $note) {
            $guide = NoteGuide::describe($note);
            self::assertNotSame('', $guide['title'], $note);
            self::assertNotSame([], $guide['options'], $note);
            self::assertStringNotContainsString(': ', $guide['title'], 'a title is one plain line: '.$note);
        }
    }
}
