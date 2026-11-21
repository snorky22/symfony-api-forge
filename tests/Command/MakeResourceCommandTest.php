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
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('api-forge:make:resource'));
    }

    public function testAsksWhatIsMissing(): void
    {
        $tester = $this->tester();
        // class, path (default), client (default), actions, envelope, docs (none)
        $tester->setInputs(['Paddle\Prices', '', '', 'list,get,update', 'data', '']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => $this->dir]));
        $code = (string) file_get_contents($this->dir.'/test-API/src/API/Paddle/Prices.php');
        self::assertStringContainsString("namespace App\\API\\Paddle;\n", $code);
        self::assertStringContainsString('implements Listable, Readable, Updatable', $code);
        self::assertStringContainsString("#[Autowire(service: 'paddle.client')]", $code);
        self::assertStringContainsString(" * @implements Readable<array{data: Price, ...}>\n", $code);
        self::assertStringContainsString("        return 'prices';\n", $code);
        self::assertStringNotContainsString('@see', $code);
    }

    public function testDefaultActions(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['Unsplash\Photos', '', '', '', '', '']);

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

    public function testNeverOverwrites(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/test-API/src/API/Paddle/Prices.php', 'mine');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'Paddle\Prices', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame('mine', file_get_contents($this->dir.'/test-API/src/API/Paddle/Prices.php'));
    }

    public function testRejectsAnInvalidClassNameOrAction(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'paddle/prices', '--output' => $this->dir], ['interactive' => false]));
        self::assertSame(Command::FAILURE, $tester->execute(['class' => 'Paddle\Prices', '--actions' => 'list,remove', '--output' => $this->dir], ['interactive' => false]));
        self::assertFileDoesNotExist($this->dir.'/test-API/src/API/Paddle/Prices.php');
    }
}
