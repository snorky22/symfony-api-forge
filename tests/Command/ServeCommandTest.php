<?php

namespace Snorky22\ApiForge\Tests\Command;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Command\ServeCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ServeCommandTest extends TestCase
{
    private function tester(string $environment = 'dev', ?string $project = null): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ServeCommand($project ?? __DIR__.'/../..', 'config/api', 'App\\Api', 'src/Api', 'config/packages', $environment));

        return new CommandTester($application->find('api-forge:serve'));
    }

    public function testDescribesWhatItWouldStart(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--port' => '9000']));
        self::assertStringContainsString('http://127.0.0.1:9000/', $tester->getDisplay());
        self::assertStringContainsString('-S 127.0.0.1:9000', $tester->getDisplay());
    }

    public function testDoesNotStartInProd(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('prod', $tester->getDisplay());
    }

    public function testRefusesAPrivilegedPortAndAMissingAutoloader(): void
    {
        self::assertSame(Command::FAILURE, $this->tester()->execute(['--dry-run' => true, '--port' => '80']));
        self::assertSame(Command::FAILURE, $this->tester('dev', sys_get_temp_dir().'/no-project')->execute(['--dry-run' => true]));
    }
}
