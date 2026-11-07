<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiImportCommandTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-import-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function import(string $curl, array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import'));
        $tester->execute(['api' => 'lemonsqueezy', 'curl' => self::FIXTURES.$curl, '--dir' => $this->dir] + $options);

        return $tester;
    }

    public function testImportsAndMergesExamples(): void
    {
        $this->import('curl-example-1', ['--response' => self::FIXTURES.'json-example.json'])->assertCommandIsSuccessful();
        $this->import('curl-example-2')->assertCommandIsSuccessful();
        $tester = $this->import('curl-example-3', ['--response' => self::FIXTURES.'json-example-2.json']);
        $tester->assertCommandIsSuccessful();

        self::assertSame('https://api.lemonsqueezy.com/v1/', Yaml::parseFile($this->dir.'/lemonsqueezy/_api.yaml')['base_uri']);

        $orders = Yaml::parseFile($this->dir.'/lemonsqueezy/orders.yaml');
        self::assertSame('orders', $orders['type']);
        self::assertArrayHasKey('filter[user_email]', $orders['operations']['list']['query']);
        self::assertSame(['data' => 'list'], $orders['operations']['list']['response']);
        self::assertStringStartsWith("array{\n    type: string,", $orders['object']['shape']);
        self::assertSame(['query parameters are assumed optional'], $orders['operations']['list']['review']);

        $checkouts = Yaml::parseFile($this->dir.'/lemonsqueezy/checkouts.yaml');
        self::assertSame(['POST', 'checkouts'], [$checkouts['operations']['create']['method'], $checkouts['operations']['create']['path']]);
        self::assertStringContainsString('operation "create" added', $tester->getDisplay());
    }

    public function testImportsAnExampleWrittenAsAnArray(): void
    {
        (new Filesystem())->mkdir($this->dir);
        $file = $this->dir.'/list-prices.php';
        file_put_contents($file, <<<'PHP'
            <?php return [
                'request' => 'GET https://api.paddle.com/prices',
                'response' => [
                    'data' => [[
                        'id' => 'pri_1',
                        // max 250 items; country_codes: min 1, unique
                        'unit_price_overrides' => [['country_codes' => ['FR'], 'unit_price' => ['amount' => '1000', 'currency_code' => 'EUR']]],
                    ]],
                ],
            ];
            PHP);
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import'));

        $tester->execute(['api' => 'paddle', 'curl' => $file, '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        $prices = Yaml::parseFile($this->dir.'/paddle/prices.yaml');
        self::assertSame('https://api.paddle.com/', Yaml::parseFile($this->dir.'/paddle/_api.yaml')['base_uri']);
        self::assertStringContainsString('unit_price_overrides: list<array{', $prices['operations']['list']['response']['shape']);
        self::assertStringContainsString('country_codes: list<string>', $prices['operations']['list']['response']['shape']);

        // A path is enough once the API has a base URI
        $second = $this->dir.'/get-price.php';
        file_put_contents($second, "<?php return ['request' => 'GET /prices/{id}', 'query' => ['include' => 'product']];");
        $tester->execute(['api' => 'paddle', 'curl' => $second, '--dir' => $this->dir]);
        $tester->assertCommandIsSuccessful();
        self::assertSame('prices/{id}', Yaml::parseFile($this->dir.'/paddle/prices.yaml')['operations']['get']['path']);
    }

    public function testImportsAResponseThatPointsToTypeFiles(): void
    {
        (new Filesystem())->mkdir($this->dir.'/fixtures');
        file_put_contents($this->dir.'/fixtures/money-type.php', "<?php return ['amount' => '1000', 'currency_code' => 'EUR'];");
        file_put_contents($this->dir.'/fixtures/duration-type.json', '{"interval": "month", "frequency": 1}');
        file_put_contents($this->dir.'/fixtures/list-prices.json', '{"data": [{"id": "pri_1", "unit_price": "money-type.php", "billing_cycle": "duration-type.json", "overrides": [{"country_codes": ["FR"], "unit_price": "money-type.php"}]}]}');
        file_put_contents($this->dir.'/fixtures/list-prices.curl', "curl https://api.paddle.com/prices -H 'Authorization: Bearer x'");
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import'));

        $tester->execute(['api' => 'paddle', 'curl' => $this->dir.'/fixtures/list-prices.curl', '--response' => $this->dir.'/fixtures/list-prices.json', '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        $shape = Yaml::parseFile($this->dir.'/paddle/prices.yaml')['operations']['list']['response']['shape'];
        self::assertStringContainsString('billing_cycle: array{', $shape);
        self::assertStringContainsString('frequency: int', $shape);
        self::assertStringContainsString('currency_code: string', $shape);
        self::assertStringNotContainsString('money-type', $shape);
    }

    public function testTellsWhyAResponseIsNotJson(): void
    {
        (new Filesystem())->mkdir($this->dir);
        file_put_contents($this->dir.'/r.json', "{\n  \"a\": 'x.array'\n}");
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import'));

        $tester->execute(['api' => 'lemonsqueezy', 'curl' => self::FIXTURES.'curl-example-1', '--response' => $this->dir.'/r.json', '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('response is not JSON (line 2: a single-quoted string, JSON strings use double quotes), ignored', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testRefusesAnArrayExampleThatIsNotData(): void
    {
        (new Filesystem())->mkdir($this->dir);
        file_put_contents($this->dir.'/bad.php', "<?php return ['request' => strtoupper('get')];");
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import'));

        self::assertSame(Command::FAILURE, $tester->execute(['api' => 'paddle', 'curl' => $this->dir.'/bad.php', '--dir' => $this->dir]));
        self::assertStringContainsString('read, not run', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/paddle/_api.yaml');
    }

    public function testResponseGivenTwiceIsRefused(): void
    {
        (new Filesystem())->mkdir($this->dir);
        file_put_contents($this->dir.'/a.php', "<?php return ['request' => 'GET https://api.paddle.com/prices', 'response' => ['a' => 1]];");
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import'));

        self::assertSame(Command::FAILURE, $tester->execute(['api' => 'paddle', 'curl' => $this->dir.'/a.php', '--response' => self::FIXTURES.'json-example.json', '--dir' => $this->dir]));
        self::assertStringContainsString('given twice', $tester->getDisplay());
    }

    public function testImportsObject(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import-object'));
        $tester->execute(['api' => 'lemonsqueezy', 'resource' => 'customers', 'files' => [self::FIXTURES.'customer-object.json'], '--dir' => $this->dir]);
        $tester->assertCommandIsSuccessful();
        $this->import('curl-customer-retrieve')->assertCommandIsSuccessful();

        $customers = Yaml::parseFile($this->dir.'/lemonsqueezy/customers.yaml');
        self::assertSame(['resource', 'type', 'object', 'operations'], array_keys($customers));
        self::assertSame('customers', $customers['type']);
        self::assertSame(['data' => 'object'], $customers['operations']['get']['response']);
    }

    public function testImportsObjectWrittenAsAnArray(): void
    {
        (new Filesystem())->mkdir($this->dir);
        file_put_contents($this->dir.'/price-object.php', "<?php return ['data' => ['type' => 'prices', 'id' => '1', 'attributes' => ['name' => 'x']]];");
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:import-object'));

        $tester->execute(['api' => 'lemonsqueezy', 'resource' => 'prices', 'files' => [$this->dir.'/price-object.php'], '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('prices', Yaml::parseFile($this->dir.'/lemonsqueezy/prices.yaml')['type']);
    }

    public function testFailsOnConflict(): void
    {
        $this->import('curl-example-1')->assertCommandIsSuccessful();
        $tester = $this->import('curl-example-3', ['--operation' => 'list', '--resource' => 'orders']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
