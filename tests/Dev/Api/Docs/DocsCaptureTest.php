<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Docs\DocsCapture;

final class DocsCaptureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{string, string, bool, bool, ?string}}>
     */
    public static function headers(): iterable
    {
        yield 'required' => ['idstringrequired', ['id', 'string', false, true, null]];
        yield 'optional' => ['estimated_totalinteger', ['estimated_total', 'integer', false, false, null]];
        yield 'null' => ['namestring | nullrequired', ['name', 'string', true, true, null]];
        yield 'null, optional' => ['unit_priceobject | null', ['unit_price', 'object', true, false, null]];
        yield 'format' => ['nextstring (uri)required', ['next', 'string', false, true, 'uri']];
        yield 'format after null' => ['external_idstring | null (uuid)', ['external_id', 'string', true, false, 'uuid']];
        yield 'badge' => ['allowancesarrayearly access', ['allowances', 'array', false, false, null]];
        yield 'a type word in the name' => ['object_arrayarrayrequired', ['object_array', 'array', false, true, null]];
        yield 'boolean' => ['has_morebooleanrequired', ['has_more', 'boolean', false, true, null]];
        yield 'number' => ['ratenumberrequired', ['rate', 'number', false, true, null]];
        yield 'spaced, required' => ['name string required', ['name', 'string', false, true, null]];
        yield 'spaced, optional' => ['type string', ['type', 'string', false, false, null]];
        yield 'spaced, null' => ['description string | null', ['description', 'string', true, false, null]];
        yield 'spaced, null and required' => ['custom_data object | null required', ['custom_data', 'object', true, true, null]];
        yield 'spaced, format' => ['created_at string (date-time) required', ['created_at', 'string', false, true, 'date-time']];
        yield 'spaced, format after null' => ['external_id string | null (uuid)', ['external_id', 'string', true, false, 'uuid']];
        yield 'spaced, badge' => ['allowances array early access', ['allowances', 'array', false, false, null]];
    }

    /**
     * @param array{string, string, bool, bool, ?string} $expected
     */
    #[DataProvider('headers')]
    public function testReadsTheHeaderOfAField(string $line, array $expected): void
    {
        $entries = (new DocsCapture())->parse($line."\n\nA description.\n");

        self::assertCount(1, $entries);
        self::assertSame($expected, [$entries[0]->name, $entries[0]->type, $entries[0]->nullable, (bool) $entries[0]->required, $entries[0]->format]);
    }

    public function testTextThatIsNotAFieldIsIgnored(): void
    {
        $entries = (new DocsCapture())->parse("## Response (200)\n\nUnique ID for this price, prefixed with `pri_`.\n\nCountry code (supported)\n\nExample: `x`\n");

        self::assertSame([], $entries);
    }

    public function testReadsTheEntriesOfACapture(): void
    {
        $entries = (new DocsCapture())->parse((string) file_get_contents(__DIR__.'/../../../Fixtures/docs-capture-example.md'));

        self::assertSame(
            ['data', 'id', 'type', 'name', 'trial_period', 'interval', 'unit_price', 'amount', 'currency_code', 'unit_price', 'amount', 'currency_code', 'custom_data', 'allowances', 'usage_unit_id', 'amount', 'product', 'id', 'name', 'meta', 'next', 'estimated_total'],
            array_map(static fn ($entry) => $entry->name, $entries),
        );
        self::assertSame(['custom', 'standard'], $entries[2]->values);
        self::assertSame('standard', $entries[2]->details['Default']);
        self::assertSame('1–150', $entries[3]->details['Length']);
        self::assertSame('^pri_[a-z\d]{26}$', $entries[1]->details['Pattern']);
        self::assertSame(['day', 'week'], $entries[5]->values);
        self::assertSame(3, $entries[0]->line);
    }

    public function testAnIncompleteListOfValuesIsNotAnEnum(): void
    {
        $entries = (new DocsCapture())->parse((string) file_get_contents(__DIR__.'/../../../Fixtures/docs-capture-example.md'));

        // "+ Show all values": the page lists a few of them
        self::assertSame('currency_code', $entries[8]->name);
        self::assertNull($entries[8]->values);
    }

    public function testLongListsOfValuesAreNotKept(): void
    {
        $values = implode("\n", array_map(static fn (int $i) => "*   `V$i`\n", range(1, 13)));
        $entries = (new DocsCapture())->parse("statusstringrequired\n\nValues\n\n$values\nnextstringrequired\n");

        self::assertNull($entries[0]->values);
        self::assertSame(13, $entries[0]->valueCount);
        self::assertSame('next', $entries[1]->name);
    }

    public function testASentenceOfTheDescriptionIsNotAField(): void
    {
        $text = "Name of this product.\n\nthe number\n\nIs a string\n\nan integer\n\nSet this boolean\n\nUnique Paddle ID\n\nRepresented as a string, this value must be a valid integer.\n";

        self::assertSame([], (new DocsCapture())->parse($text));
    }

    public function testReadsAMarkdownCaptureWithSpaces(): void
    {
        $text = "## Request body\n\nname string required\n\nName of this product.\n\nLength: 1–200\n\nPattern: `.*\\S.*`\n\ndescription string | null\n\nShort description.\n\nMax length: 2048\n\ntype string\n\nDefault: `standard`\n\nValues\n\n*   `custom`\n    \n    Non-catalog item.\n    \n*   `standard`\n    \n    Standard item.\n    \n\ntax_category string required\n";

        $entries = (new DocsCapture())->parse($text);

        self::assertSame(['name', 'description', 'type', 'tax_category'], array_map(static fn ($entry) => $entry->name, $entries));
        self::assertSame([true, false, false, true], array_map(static fn ($entry) => $entry->required, $entries));
        self::assertTrue($entries[1]->nullable);
        self::assertSame(['custom', 'standard'], $entries[2]->values);
        self::assertSame('1–200', $entries[0]->details['Length']);
        self::assertSame('2048', $entries[1]->details['Max length']);
        self::assertSame('standard', $entries[2]->details['Default']);
    }
}
