<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Guide;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:guide',
    description: 'The essentials to proceed: the workflow, the conventions, and the state of an API with its next step',
    help: <<<'HELP'
        Without argument: the workflow, the naming of the documentation examples, the APIs found.
        With an API: its state (examples, descriptors, review notes, doctor probe, webhooks, generated code)
        and the next step.

          <info>php %command.full_name%</info>
          <info>php %command.full_name% paddle</info>
        HELP,
)]
final class GuideCommand
{
    public function __construct(
        private readonly Guide $guide,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "paddle"')] ?string $api = null,
        #[Option('Descriptor directory (default: api_forge.descriptor_dir, config/api)')] ?string $dir = null,
        #[Option('Project directory the services are generated into (default: this project)')] ?string $output = null,
        #[Option('Directory of the documentation examples (default: tests/Fixtures/<api>)')] ?string $fixtures = null,
    ): int {
        if (null !== $api) {
            $status = $this->guide->status($api, $dir, $output, $fixtures);
            $io->title($api);
            $io->definitionList(...array_map(static fn (array $row) => [$row[0] => $row[1]], $status['state']));
            $io->section('Next step');
            $io->text(explode("\n", $status['next']));

            return Command::SUCCESS;
        }

        $io->title('api-forge');
        $io->section('Workflow');
        $io->table(['Step', 'Command / file'], Guide::STEPS);
        $io->section('Documentation examples, in '.Guide::FIXTURES_DIR.'/');
        $io->table(['File', 'Content', 'Examples'], Guide::FIXTURES);
        $io->section('APIs');
        $apis = $this->guide->apis($dir);
        $io->text([] !== $apis ? array_map(static fn (string $api) => '- '.$api.' (php bin/console api-forge:guide '.$api.')', $apis) : 'None yet: import a first documented request.');

        return Command::SUCCESS;
    }
}
