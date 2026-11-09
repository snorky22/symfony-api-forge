<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Web\NoteExplainer;

final class NoteExplainerTest extends TestCase
{
    private const FILES = ['body' => 'create-products.body.json', 'bodyDocs' => 'create-products.body.docs.md', 'response' => 'create-products.json', 'responseDocs' => 'create-products.docs.md'];

    public function testAFieldTheDocsHaveAndTheExampleLacks(): void
    {
        $text = NoteExplainer::explain('config/api/p/products.yaml: operation "create": body docs: "type" (line 17) is not in the example: add it to the example (in the docs it sits between "description" and "tax_category"; values in the docs: custom, standard)', self::FILES);

        self::assertStringContainsString('The docs page of the body describes "type" (line 17)', $text);
        self::assertStringContainsString('between "description" and "tax_category"', $text);
        self::assertStringContainsString('Add it to create-products.body.json, for example "type": "custom" (the docs allow custom, standard)', $text);
        self::assertStringContainsString('accept this note if you do not need the field', $text);
    }

    public function testAFieldTheExampleHasAndTheDocsLack(): void
    {
        $body = NoteExplainer::explain('x: operation "create": body example: "image_url" (image_url) is not in the docs: complete the docs page text, or remove it from the example', self::FILES);
        $response = NoteExplainer::explain('x: operation "create": example: "image_url" (data.image_url) is not in the docs: complete the docs page text, or remove it from the example', self::FILES);

        self::assertStringContainsString('open create-products.body.docs.md and paste the missing part', $body);
        self::assertStringContainsString('remove it from create-products.body.json', $body);
        self::assertStringContainsString('open create-products.docs.md and paste the missing part', $response);
        self::assertStringContainsString('remove it from create-products.json', $response);
    }

    public function testAFreeFormObjectAndAnEmptyParent(): void
    {
        $free = NoteExplainer::explain('x: operation "create": example: "custom_data" (data.custom_data) is a free-form object in the docs (no field is described), but the example gives it fields (features, tags): replace its value with {} in the example', self::FILES);
        $empty = NoteExplainer::explain('x: operation "create": docs: "import_meta" (data.import_meta) shows no fields in the example (null or empty), but the docs describe external_id, imported_from: give it an example value', self::FILES);

        self::assertStringContainsString('"custom_data" is a free-form object', $free);
        self::assertStringContainsString('Replace its value with {} in create-products.json', $free);
        self::assertStringContainsString('fields inside "import_meta" (external_id, imported_from)', $empty);
        self::assertStringContainsString('Give "import_meta" an example value in create-products.json', $empty);
    }

    public function testAnUnknownNoteIsLeftToTheAdvice(): void
    {
        self::assertNull(NoteExplainer::explain('x: query parameters are assumed optional', self::FILES));
        self::assertStringContainsString('has the type', (string) NoteExplainer::explain('docs: data[].id is string in the docs (line 5), int in the example', []) . 'has the type');
    }
}
