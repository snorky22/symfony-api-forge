<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiGenerateCommandTest extends KernelTestCase
{
    private const EXAMPLE = __DIR__.'/../../examples/lemonsqueezy';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-generate-test-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir.'/descriptors/shop/_api.yaml', Yaml::dump(['base_uri' => 'https://shop.test/', 'format' => 'json']));
        foreach (['orders', 'products'] as $resource) {
            $filesystem->dumpFile($this->dir.'/descriptors/shop/'.$resource.'.yaml', Yaml::dump(['resource' => $resource, 'operations' => [
                'list' => ['method' => 'GET', 'path' => $resource, 'review' => ['check me']],
            ]], 10));
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function generate(array $options = [], string $api = 'shop'): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:generate'));
        $paths = 'shop' === $api
            ? ['--dir' => $this->dir.'/descriptors', '--output' => $this->dir.'/project']
            : ['--dir' => self::EXAMPLE.'/config/api', '--output' => self::EXAMPLE];
        $tester->execute(['api' => $api] + $paths + $options);

        return $tester;
    }

    public function testLemonSqueezyExampleIsUpToDate(): void
    {
        $tester = $this->generate(['--check' => true], 'lemonsqueezy');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testGeneratesChecksAndRemovesStaleFiles(): void
    {
        $tester = $this->generate();
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 review note(s)', $tester->getDisplay());
        self::assertFileExists($this->dir.'/project/src/Api/Shop/Generated/Resource/Products.php');
        self::assertFileExists($this->dir.'/project/config/packages/api_shop.yaml');

        $this->generate(['--check' => true])->assertCommandIsSuccessful();

        unlink($this->dir.'/descriptors/shop/products.yaml');
        $tester = $this->generate(['--check' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertFileExists($this->dir.'/project/src/Api/Shop/Generated/Resource/Products.php');

        $tester = $this->generate();
        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/removed\s+src\/Api\/Shop\/Generated\/Resource\/Products\.php/', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/project/src/Api/Shop/Generated/Resource/Products.php');
        self::assertStringNotContainsString('Products', file_get_contents($this->dir.'/project/src/Api/Shop/Generated/ShopApi.php'));
    }

    public function testKeepsHandWrittenFiles(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/project/src/Api/Shop/Generated/Custom.php', "<?php\n// hand-written\n");

        $this->generate()->assertCommandIsSuccessful();

        self::assertFileExists($this->dir.'/project/src/Api/Shop/Generated/Custom.php');
    }

    public function testLeavesTheClientToMakeResource(): void
    {
        // make:resource declared the client of the API: the generated file leaves it out
        (new Filesystem())->dumpFile($this->dir.'/project/config/packages/shop_client.yaml', "framework:\n    http_client:\n        scoped_clients:\n            shop.client: { base_uri: 'https://shop.test/' }\n");

        $tester = $this->generate();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $config = (string) file_get_contents($this->dir.'/project/config/packages/api_shop.yaml');
        self::assertStringContainsString('# The scoped client "shop.client" is declared in config/packages/shop_client.yaml: left to it.', $config);
        self::assertNull(Yaml::parse($config));
        // The generated resources still use it
        self::assertStringContainsString("#[Autowire(service: 'shop.client')]", (string) file_get_contents($this->dir.'/project/src/Api/Shop/Generated/Resource/Orders.php'));
    }

    public function testGivesWayWhateverTheCaseAndSeparatorsAndUsesThatClient(): void
    {
        // make:resource / make:webhook first, for an API named "my_shop" in PHP (MyShop) and "myshop" here
        $filesystem = new Filesystem();
        $filesystem->mirror($this->dir.'/descriptors/shop', $this->dir.'/descriptors/myshop');
        $filesystem->dumpFile($this->dir.'/project/config/packages/my_shop_client.yaml', "framework:\n    http_client:\n        scoped_clients:\n            my_shop.client: { base_uri: 'https://shop.test/' }\n");
        $filesystem->dumpFile($this->dir.'/project/config/packages/my_shop_webhook.yaml', "framework:\n    webhook:\n        routing:\n            my_shop: { service: App\\Parser, secret: s }\n");

        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:generate'));
        $tester->execute(['api' => 'myshop', '--dir' => $this->dir.'/descriptors', '--output' => $this->dir.'/project']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $config = (string) file_get_contents($this->dir.'/project/config/packages/api_myshop.yaml');
        self::assertStringContainsString('# The scoped client "my_shop.client" is declared in config/packages/my_shop_client.yaml: left to it.', $config);
        self::assertNull(Yaml::parse($config));
        self::assertStringContainsString("#[Autowire(service: 'my_shop.client')]", (string) file_get_contents($this->dir.'/project/src/Api/Myshop/Generated/Resource/Orders.php'));
    }

    public function testADeclarationForOneEnvironmentIsNotLeftTo(): void
    {
        // A client replaced in the tests only: the generated file still declares it
        (new Filesystem())->dumpFile($this->dir.'/project/config/packages/mocks.yaml', "when@test:\n    framework:\n        http_client:\n            scoped_clients:\n                shop.client: { base_uri: 'http://localhost/' }\n");

        $this->generate();

        self::assertArrayHasKey('shop.client', Yaml::parse((string) file_get_contents($this->dir.'/project/config/packages/api_shop.yaml'))['framework']['http_client']['scoped_clients']);
    }

    public function testNeverOverwritesAConfigFileNotWrittenByIt(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/project/config/packages/api_shop.yaml', "# mine\n");

        $tester = $this->generate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('exists and was not written by', $tester->getDisplay());
        self::assertStringNotContainsString('Invalid API descriptors', $tester->getDisplay());
        self::assertSame("# mine\n", file_get_contents($this->dir.'/project/config/packages/api_shop.yaml'));
    }

    public function testStrictFailsOnReviewNotes(): void
    {
        self::assertSame(Command::FAILURE, $this->generate(['--strict' => true])->getStatusCode());
        self::assertDirectoryDoesNotExist($this->dir.'/project');
    }

    public function testReportsInvalidDescriptors(): void
    {
        file_put_contents($this->dir.'/descriptors/shop/orders.yaml', Yaml::dump(['resource' => 'orders', 'operations' => ['get' => ['method' => 'GET', 'path' => 'orders/{id}']]], 10));

        $tester = $this->generate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('path parameters {id} are not declared', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
