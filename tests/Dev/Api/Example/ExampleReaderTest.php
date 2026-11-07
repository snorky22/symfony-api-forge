<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Example;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;
use Symfony\Component\Filesystem\Filesystem;

final class ExampleReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/example-reader-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir.'/types');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->dir.'/'.$name, $content);

        return $this->dir.'/'.$name;
    }

    private function reader(): ExampleReader
    {
        return new ExampleReader([new PhpArrayExample()]);
    }

    public function testJsonWithoutReferenceIsUntouched(): void
    {
        $json = "{\n  // a comment\n  \"a\": null, \"b\": {}, \"c\": 1.0, \"u\": \"https://x.test/data.jsonl\"\n}";
        $file = $this->write('plain.json', $json);

        self::assertSame($json, $this->reader()->json($file));
    }

    public function testReferencesToJsonAndArrayFilesAreReplacedByTheirContent(): void
    {
        $this->write('duration-type.json', '{"interval": "month", "frequency": 1}');
        $this->write('money-type.php', "<?php return ['amount' => '1000', 'currency_code' => 'EUR']; // Money");
        $this->write('unit-price-override-type.php', "<?php return ['country_codes' => ['FR'], 'unit_price' => 'money-type.php'];");
        $file = $this->write('list-prices.json', '{"data": [{"billing_cycle": "duration-type.json", "trial_period": null, "unit_price_overrides": ["unit-price-override-type.php"], "empty": {}}]}');

        $data = json_decode($this->reader()->json($file), true);

        self::assertSame(['interval' => 'month', 'frequency' => 1], $data['data'][0]['billing_cycle']);
        self::assertSame([['country_codes' => ['FR'], 'unit_price' => ['amount' => '1000', 'currency_code' => 'EUR']]], $data['data'][0]['unit_price_overrides']);
        self::assertNull($data['data'][0]['trial_period']);
        self::assertStringContainsString('"empty": {}', $this->reader()->json($file));
    }

    public function testReferencesInAnArrayFileAndInASubdirectory(): void
    {
        $this->write('types/duration-type.json', '{"interval": "month"}');
        $file = $this->write('prices.php', "<?php return ['data' => ['types/duration-type.json']];");

        self::assertSame(['data' => [['interval' => 'month']]], json_decode($this->reader()->json($file), true));
    }

    public function testReferencesInAnOperationExample(): void
    {
        $this->write('price-type.json', '{"id": "pri_1"}');
        $file = $this->write('create-price.php', "<?php return ['request' => 'POST https://api.test/prices', 'body' => ['price' => 'price-type.json'], 'response' => ['data' => 'price-type.json']];");

        $example = $this->reader()->operation($file);

        self::assertStringContainsString('"id": "pri_1"', (string) $example->curl);
        self::assertSame(['data' => ['id' => 'pri_1']], json_decode((string) $example->response, true));
    }

    public function testAnArrayFileIsABareLiteralWithoutPhpTag(): void
    {
        $this->write('unit_price_overrides.array', "[\n    // one override\n    'country_codes' => ['AD', 'AE'],\n    'unit_price' => ['amount' => '100', 'currency_code' => 'USD'],\n]\n");
        $file = $this->write('list-prices.json', '{"data": [{"unit_price_overrides": ["unit_price_overrides.array"]}, {"unit_price_overrides": []}]}');

        $data = json_decode($this->reader()->json($file), true);

        self::assertSame('USD', $data['data'][0]['unit_price_overrides'][0]['unit_price']['currency_code']);
        self::assertSame([], $data['data'][1]['unit_price_overrides']);
    }

    public function testBackslashKeepsAFileNameAsAString(): void
    {
        $file = $this->write('a.json', '{"file": "\\\\export.json", "other": "report.json.gz"}');

        self::assertSame(['file' => 'export.json', 'other' => 'report.json.gz'], json_decode($this->reader()->json($file), true));
    }

    public function testStringsThatAreNotRelativeFileNamesAreNotReferences(): void
    {
        $file = $this->write('a.json', '{"url": "https://x.test/a.json", "abs": "/etc/a.json", "sp": "a b.json", "win": "C:a.json"}');

        self::assertSame(['url' => 'https://x.test/a.json', 'abs' => '/etc/a.json', 'sp' => 'a b.json', 'win' => 'C:a.json'], json_decode($this->reader()->json($file), true));
    }

    public function testParentDirectoryTypesAreShared(): void
    {
        (new Filesystem())->mkdir($this->dir.'/paddle');
        $this->write('money-type.json', '{"amount": "1"}');
        $file = $this->write('paddle/a.json', '{"m": "../money-type.json"}');

        self::assertSame(['m' => ['amount' => '1']], json_decode($this->reader()->json($file), true));
    }

    public function testMissingFileIsRefusedWithTheWayOut(): void
    {
        $file = $this->write('a.json', '{"trial": "nowhere-type.json"}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('To keep it as a string, write "\\nowhere-type.json"');

        $this->reader()->json($file);
    }

    public function testCyclesAreRefused(): void
    {
        $this->write('category-type.json', '{"parent": "category-type.json"}');
        $this->write('a-type.json', '{"b": "b-type.json"}');
        $this->write('b-type.json', '{"a": "a-type.json"}');

        foreach (['category-type.json' => 'category-type.json → category-type.json', 'a-type.json' => 'a-type.json → b-type.json → a-type.json'] as $name => $chain) {
            try {
                $this->reader()->json($this->write('root.json', '{"x": "'.$name.'"}'));
                self::fail('Cycle accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($chain, $e->getMessage());
            }
        }
    }

    public function testUnreadableReferencedFileNamesTheFile(): void
    {
        $this->write('broken-type.php', "<?php return [phpinfo()];");
        $file = $this->write('a.json', '{"x": "broken-type.php"}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('broken-type.php');

        $this->reader()->json($file);
    }

    public function testExtensions(): void
    {
        self::assertSame(['php', 'array'], $this->reader()->extensions());
        self::assertSame(['json', 'php', 'array'], $this->reader()->dataExtensions());
        self::assertSame(['json'], (new ExampleReader())->dataExtensions());
    }
}
