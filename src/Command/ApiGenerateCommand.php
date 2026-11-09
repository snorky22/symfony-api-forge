<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Api\Generator\GenerateRunner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:generate',
    description: 'Generates the Symfony services of an API from its descriptors',
    help: <<<'HELP'
        Validates <comment>config/api/<api>/*.yaml</comment> and generates (default paths, see the api_forge configuration):

          - <comment>src/Api/<Namespace>/Generated/Resource/*.php</comment>, one service per resource
          - <comment>src/Api/<Namespace>/Generated/<Namespace>Api.php</comment>, the entry point
          - <comment>config/packages/api_<api>.yaml</comment>, the scoped HTTP client

          <info>php %command.full_name% lemonsqueezy</info>

        Generated files that no longer match a descriptor are removed. Never edit generated files:
        fix the descriptors and regenerate. Use --check in CI to verify the generated code is up to date.
        HELP,
)]
final class ApiGenerateCommand
{
    public function __construct(
        private readonly GenerateRunner $runner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Option('Descriptor directory (default: api_forge.descriptor_dir, config/api)')] ?string $dir = null,
        #[Option('Project directory to generate into (default: this project)')] ?string $output = null,
        #[Option('Fail if the descriptors still contain review notes')] bool $strict = false,
        #[Option('Do not write anything, fail if the generated files are not up to date')] bool $check = false,
    ): int {
        // The notes are known before anything is written: --strict must fail without generating
        $result = $this->runner->run($api, $dir, $output, true);
        if (!$result->isValid()) {
            $io->error(array_merge(['Invalid API descriptors:'], $result->errors));

            return Command::FAILURE;
        }
        if ([] !== $result->review) {
            $io->warning(array_merge([\sprintf('%d review note(s) left in the descriptors:', \count($result->review))], $result->review));
            if ($strict) {
                return Command::FAILURE;
            }
        }
        if (!$check) {
            $result = $this->runner->run($api, $dir, $output);
        }

        if ($result->isUpToDate()) {
            $io->success(\sprintf('The %d generated files are up to date.', $result->files));

            return Command::SUCCESS;
        }

        $io->table(['Change', 'File'], $result->changes);
        if ($check) {
            $io->error('The generated files are not up to date: run api-forge:generate.');

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d file(s) changed.', \count($result->changes)));

        return Command::SUCCESS;
    }
}
