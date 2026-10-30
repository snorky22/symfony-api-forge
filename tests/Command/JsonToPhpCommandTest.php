<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class JsonToPhpCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('api-forge:json-to-php'));
    }

    public function testConvertsDocumentationExampleFile(): void
    {
        $tester = $this->tester();
        $tester->execute(['file' => __DIR__.'/../Fixtures/json-example.json', '--shape' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString(" *     data: list<array{\n", $display);
        self::assertStringContainsString(" *             refunded_at: mixed,\n", $display);
        self::assertStringContainsString(" *             'order-items': array{\n", $display);
        self::assertStringContainsString("            'user_email' => 'johndoe@example.com',\n", $display);
        self::assertStringEndsWith("];\n", $display);
    }

    public function testReadsStdin(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['{"a": 1,}']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame("[\n    'a' => 1,\n];\n", $tester->getDisplay());
    }

    public function testShapeOnly(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['{"a": 1}']);
        $tester->execute(['--shape-only' => true, '--tag' => 'return']);

        self::assertSame("/**\n * @return array{\n *     a: int,\n * }\n */\n", $tester->getDisplay());
    }

    public function testFailsOnInvalidJson(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['{"a": }']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Invalid JSON', $tester->getDisplay());
    }
}
