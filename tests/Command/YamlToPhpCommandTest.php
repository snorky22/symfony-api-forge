<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class YamlToPhpCommandTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-forge-yaml-to-php-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('api-forge:yaml-to-php'));
    }

    public function testPrintsTheArray(): void
    {
        file_put_contents($this->dir.'/a.yaml', "a: 1\nb: [x, y]\n");
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->dir.'/a.yaml']));
        self::assertSame("[\n    'a' => 1,\n    'b' => [\n        'x',\n        'y',\n    ],\n];\n", $tester->getDisplay());
    }

    public function testReadsStdin(): void
    {
        $tester = $this->tester();
        $tester->setInputs(["a: 1\n"]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame("[\n    'a' => 1,\n];\n", $tester->getDisplay());
    }

    public function testWritesAFileThatReturnsTheArrayAndKeepsExistingOnes(): void
    {
        file_put_contents($this->dir.'/a.yaml', "a: 1\n");
        $out = $this->dir.'/a.php';
        $tester = $this->tester();

        $tester->execute(['file' => $this->dir.'/a.yaml', '--output' => $out]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(['a' => 1], require $out);

        file_put_contents($out, 'mine');
        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->dir.'/a.yaml', '--output' => $out]));
        self::assertSame('mine', file_get_contents($out));
        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->dir.'/a.yaml', '--output' => $out, '--force' => true]));
        self::assertSame(['a' => 1], require $out);
    }

    public function testWarnsAboutCommentsOnStderr(): void
    {
        file_put_contents($this->dir.'/a.yaml', "# note\na: 1\n");
        $tester = $this->tester();
        $tester->execute(['file' => $this->dir.'/a.yaml'], ['capture_stderr_separately' => true]);

        self::assertStringContainsString('1 comment line(s) are not carried over', $tester->getErrorOutput());
        self::assertSame("[\n    'a' => 1,\n];\n", $tester->getDisplay());
    }

    public function testFailsOnInvalidYamlAndMissingFile(): void
    {
        file_put_contents($this->dir.'/bad.yaml', "a: [1, 2\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->dir.'/bad.yaml']));
        self::assertStringContainsString('Invalid YAML', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->dir.'/missing.yaml']));
        self::assertStringContainsString('Cannot read file', $tester->getDisplay());
    }
}
