<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class MakeResourceCommandTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-forge-make-resource-'.bin2hex(random_bytes(4));
        // Warnings raised in the Console component (e.g. a choice question with a default it can't show) are not
        // reported by PHPUnit (restrictWarnings): make them fail here
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }, \E_WARNING | \E_NOTICE | \E_USER_WARNING);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        (new Filesystem())->remove($this->dir);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('api-forge:make:resource'));
    }

    public function testAsksWhatIsMissing(): void
    {
        $tester = $this->tester();
        // class, endpoint, path (default), client (default), actions, envelope, docs (none)
        $tester->setInputs(['Paddle\Prices', 'api.paddle.com/prices/{price_id}', '', '', 'list,get,update', 'data', '']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => $this->dir]));
        $code = (string) file_get_contents($this->dir.'/test-API/src/API/Paddle/Prices.php');
        self::assertStringContainsString("namespace App\\API\\Paddle;\n", $code);
        self::assertStringContainsString('implements Listable, Readable, Updatable', $code);
        self::assertStringContainsString("#[Autowire(service: 'paddle.client')]", $code);
        self::assertStringContainsString(" * @implements Readable<array{data: Price, ...}>\n", $code);
        self::assertStringContainsString("        return 'prices';\n", $code);
        self::assertStringNotContainsString('@see', $code);
        $client = (string) file_get_contents($this->dir.'/test-API/config/packages/paddle_client.yaml');
        self::assertStringContainsString("            paddle.client:\n                base_uri: '%env(PADDLE_BASE_URI)%'\n                auth_bearer: '%env(PADDLE_API_KEY)%'\n", $client);
        self::assertStringContainsString("    env(PADDLE_BASE_URI): 'https://api.paddle.com/'\n    env(PADDLE_API_KEY): ''\n", $client);
        self::assertStringContainsString('PADDLE_API_KEY=...', $tester->getDisplay());
    }

    public function testAWiderPathShortensTheBaseUri(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Acme\LicenseKeys', '--endpoint' => 'https://api.acme.test/v1/license-keys', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString("return 'license-keys';", (string) file_get_contents($this->dir.'/test-API/src/API/Acme/LicenseKeys.php'));
        self::assertStringContainsString("env(ACME_BASE_URI): 'https://api.acme.test/v1/'\n", (string) file_get_contents($this->dir.'/test-API/config/packages/acme_client.yaml'));

        $tester->setInputs(['v1/orders']);
        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Acme\Orders', '--endpoint' => 'api.acme.test/v1/orders', '--client' => 'shop.client', '--actions' => 'list', '--envelope' => '', '--docs' => '', '--output' => $this->dir]));
        self::assertStringContainsString("return 'v1/orders';", (string) file_get_contents($this->dir.'/test-API/src/API/Acme/Orders.php'));
        self::assertStringContainsString("env(SHOP_BASE_URI): 'https://api.acme.test/'\n", (string) file_get_contents($this->dir.'/test-API/config/packages/shop_client.yaml'));

        // A client declared already: left as it is
        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Acme\Items', '--endpoint' => 'api.acme.test/v1/items', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('The scoped client "acme.client" is declared in', $tester->getDisplay());
    }

    public function testAnEndpointWithoutHostIsRejected(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'Acme\Orders', '--endpoint' => 'orders', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('is not an endpoint URL', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/test-API/src/API/Acme/Orders.php');
    }

    public function testDefaultActions(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['Unsplash\Photos', '', '', '', '', '', '']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => $this->dir]));
        self::assertStringContainsString('[list  ] GET {path}', $tester->getDisplay());
        self::assertStringContainsString('implements Listable, Readable', (string) file_get_contents($this->dir.'/test-API/src/API/Unsplash/Photos.php'));
    }

    public function testWithoutInteractionUsesOptionsAndDefaults(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Kagi\Search', '--actions' => '', '--output' => $this->dir], ['interactive' => false]));
        $code = (string) file_get_contents($this->dir.'/test-API/src/API/Kagi/Search.php');
        self::assertStringContainsString("final class Search extends AbstractRestResource\n", $code);
        self::assertStringContainsString("#[Autowire(service: 'kagi.client')]", $code);
        self::assertStringContainsString('fill the TODOs of Search.php', $tester->getDisplay());
    }

    public function testWarnsWhenTheDirectoryOnlyDiffersByCase(): void
    {
        (new Filesystem())->mkdir($this->dir.'/test-API/src/Api');
        if (!is_dir($this->dir.'/test-API/src/API')) {
            self::markTestSkipped('The file system is case-sensitive: API and Api are two directories.');
        }
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('The directory is "test-API/src/Api", not "test-API/src/API"', $tester->getDisplay());
    }

    public function testAddsTheMissingActionsToAnExistingClass(): void
    {
        $file = $this->dir.'/test-API/src/API/Paddle/Prices.php';
        $this->tester()->execute(['class' => 'Paddle\Prices', '--actions' => 'list', '--envelope' => 'data', '--output' => $this->dir], ['interactive' => false]);
        // A shape filled in by hand, and a method of its own
        file_put_contents($file, str_replace(
            [' * @phpstan-type Price array<string, mixed>', '    protected function path()'],
            [' * @phpstan-type Price array{id: string, status: string, ...}', "    public function mine(): void\n    {\n    }\n\n    protected function path()"],
            (string) file_get_contents($file),
        ));
        $tester = $this->tester();
        // endpoint (none), actions
        $tester->setInputs(['', 'get,create']);

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Prices.php exists, with: list; endpoint: none.', $display);
        self::assertStringContainsString('[get   ] GET {path}/{id}', $display);
        self::assertStringNotContainsString('[list  ]', $display);
        $code = (string) file_get_contents($file);
        self::assertStringContainsString('implements Listable, Readable, Creatable', $code);
        self::assertStringContainsString(' * @phpstan-type Price array{id: string, status: string, ...}', $code);
        self::assertStringContainsString(' * @implements Readable<array{data: Price, ...}>', $code);
        self::assertStringContainsString("    use Creates;\n    use Lists;\n    use Reads;\n", $code);
        self::assertStringContainsString('public function mine(): void', $code);
    }

    public function testAnExistingClassWithoutEndpointIsAskedForIt(): void
    {
        $file = $this->dir.'/test-API/src/API/Paddle/Prices.php';
        $this->tester()->execute(['class' => 'Paddle\Prices', '--actions' => 'list', '--envelope' => 'data', '--output' => $this->dir], ['interactive' => false]);
        $tester = $this->tester();
        // endpoint, actions (none)
        $tester->setInputs(['api.paddle.com/prices/{price_id}', '']);

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir]));
        self::assertStringContainsString('Added the endpoint to', $tester->getDisplay());
        // The client written without its base URI when the class was made: filled in
        self::assertStringContainsString('Base URI of the scoped client "paddle.client" filled in', $tester->getDisplay());
        self::assertStringContainsString("env(PADDLE_BASE_URI): 'https://api.paddle.com/'\n", (string) file_get_contents($this->dir.'/test-API/config/packages/paddle_client.yaml'));
        $code = (string) file_get_contents($file);
        self::assertStringContainsString("/**\n * Endpoint: https://api.paddle.com/prices\n *\n * TODO:", $code);
        self::assertStringContainsString('implements Listable', $code);

        // Recorded: not asked again
        $tester->setInputs(['get']);
        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir]));
        self::assertStringContainsString('endpoint: https://api.paddle.com/prices.', $tester->getDisplay());
        self::assertStringNotContainsString('Endpoint of the resource', $tester->getDisplay());
        self::assertStringContainsString('Added get to', $tester->getDisplay());
    }

    public function testTheEndpointOfAnExistingClassAsAnOption(): void
    {
        $file = $this->dir.'/test-API/src/API/Acme/Orders.php';
        $this->tester()->execute(['class' => 'Acme\Orders', '--actions' => 'list', '--output' => $this->dir], ['interactive' => false]);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Acme\Orders', '--endpoint' => 'api.acme.test/v2/items', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString(' * Endpoint: https://api.acme.test/v2/items', (string) file_get_contents($file));
        self::assertStringContainsString('does not end with the path "orders"', $tester->getDisplay());
    }

    public function testExistingClassWithoutInteractionAddsNoAction(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/test-API/src/API/Paddle/Prices.php', $mine = "<?php\n\nfinal class Prices extends AbstractRestResource\n{\n}\n");
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('--actions', $tester->getDisplay());
        self::assertStringContainsString('Nothing to change in the class.', $tester->getDisplay());
        self::assertSame($mine, file_get_contents($this->dir.'/test-API/src/API/Paddle/Prices.php'));
    }

    public function testNothingToAdd(): void
    {
        $tester = $this->tester();
        $tester->execute(['class' => 'Paddle\Prices', '--actions' => 'list,get,create,update,delete', '--output' => $this->dir], ['interactive' => false]);
        $before = file_get_contents($this->dir.'/test-API/src/API/Paddle/Prices.php');

        $tester->setInputs(['']);
        self::assertSame(Command::SUCCESS, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir]));
        self::assertStringContainsString('It has all the actions.', $tester->getDisplay());
        self::assertStringContainsString('Nothing to change in the class.', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->dir.'/test-API/src/API/Paddle/Prices.php'));
    }

    public function testRejectsAnInvalidClassNameOrAction(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'paddle/prices', '--output' => $this->dir], ['interactive' => false]));
        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'Paddle\Prices', '--actions' => 'list,remove', '--output' => $this->dir], ['interactive' => false]));
        self::assertFileDoesNotExist($this->dir.'/test-API/src/API/Paddle/Prices.php');
    }
}
