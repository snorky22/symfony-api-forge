<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiGenerateCommandTest extends KernelTestCase
{
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
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:api:generate'));
        $tester->execute(['api' => $api] + ('shop' === $api ? ['--dir' => $this->dir.'/descriptors', '--output' => $this->dir.'/project'] : []) + $options);

        return $tester;
    }

    public function testCommittedLemonSqueezyCodeIsUpToDate(): void
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
