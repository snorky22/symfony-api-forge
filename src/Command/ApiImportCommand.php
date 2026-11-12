<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Api\Import\ImportRunner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:import',
    description: 'Imports an endpoint from a documented curl command (and example response) into the API descriptors',
    help: <<<'HELP'
        Writes or updates <comment>config/api/<api>/_api.yaml</comment> and <comment>config/api/<api>/<resource>.yaml</comment>:

          <info>php %command.full_name% lemonsqueezy tests/Fixtures/curl-example-1 --response=tests/Fixtures/json-example.json</info>
          <info>php %command.full_name% lemonsqueezy tests/Fixtures/curl-example-2</info>

        Existing descriptor values are kept, so hand-made fixes survive re-imports. Importing another
        example of the same operation adds its query parameters. Guesses are listed under "review".
        Note that YAML comments in the updated files are not preserved.
        HELP,
)]
final class ApiImportCommand
{
    public function __construct(
        private readonly ImportRunner $runner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Argument('File containing the curl command, or an example of another format (.php: request and response as an array)')] string $curl,
        #[Option('File containing the example JSON response (or an array, .php)')] ?string $response = null,
        #[Option('Text capture of the documentation of the response: optional fields, nulls, enums (default: <example>.docs.md next to the example, if any)')] ?string $docs = null,
        #[Option('Resource name (default: first path segment)')] ?string $resource = null,
        #[Option('Operation name (default: inferred from method and path)')] ?string $operation = null,
        #[Option('Replace an existing operation with the same name')] bool $force = false,
        #[Option('Descriptor directory (default: api_forge.descriptor_dir, config/api)')] ?string $dir = null,
        #[Option('When the objects of the response are already described from another example: keep it (default), merge the examples, or replace it (as --force)')] ?string $object = null,
    ): int {
        try {
            $result = $this->runner->import($api, $curl, $response, $docs, $resource, $operation, $force, $dir, $object);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Resource' => $result->resource],
            ['Operation' => $result->operation],
            ['Endpoint' => $result->endpoint],
            ['Files' => implode("\n", $result->files)],
        );
        $io->listing($result->notes);
        if ([] !== $result->review) {
            $io->warning(array_merge(['To review in the descriptor:'], array_map(static fn (string $note) => '- '.$note, $result->review)));
        }

        return Command::SUCCESS;
    }
}
