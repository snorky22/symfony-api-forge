<?php

namespace App\Command;

use App\Dev\Api\Descriptor\DescriptorStore;
use App\Dev\Api\Import\DescriptorMerger;
use App\Dev\Api\Import\EndpointImporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api:import-object',
    description: 'Imports a resource object example (e.g. "The customer object" in API docs) into a resource descriptor',
    help: <<<'HELP'
        Sets the "object" shape of <comment>config/api/<api>/<resource>.yaml</comment>, used by all its operations:

          <info>php %command.full_name% lemonsqueezy customers tests/Fixtures/customer-object.json</info>

        Each example may be a resource object, or a full JSON:API document whose data is used
        (an API response, a webhook payload). Several examples are merged into one shape:
          <info>php %command.full_name% lemonsqueezy orders tests/Fixtures/order-object.json tests/Fixtures/order-created-payload.json --force</info>

        An existing object shape is kept, unless --force is given.
        HELP,
)]
final class ApiImportObjectCommand
{
    public function __construct(
        private readonly EndpointImporter $importer,
        private readonly DescriptorMerger $merger,
        private readonly DescriptorStore $store,
    ) {
    }

    /**
     * @param list<string> $files
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Argument('Resource name, e.g. "customers"')] string $resource,
        #[Argument('Files containing example objects')] array $files,
        #[Option('Replace an existing object shape')] bool $force = false,
        #[Option('Descriptor directory (default: config/api)')] ?string $dir = null,
    ): int {
        $json = [];
        foreach ($files as $file) {
            $content = is_file($file) ? file_get_contents($file) : false;
            if (false === $content) {
                $io->error(\sprintf('Cannot read file "%s".', $file));

                return Command::FAILURE;
            }
            $json[] = $content;
        }

        try {
            [$type, $object] = $this->importer->importObject($json);
            $merged = $this->merger->mergeObject($this->store->loadResource($api, $resource, $dir), $resource, $type, $object, $force);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->text($this->store->saveResource($api, $resource, $merged['resource'], $dir));
        $io->listing([] === $merged['notes'] ? ['resource object already defined, kept (use --force to replace it)'] : $merged['notes']);
        if (isset($merged['resource']['object']['review'])) {
            $io->warning(array_merge(['To review in the descriptor:'], array_map(static fn (string $note) => '- '.$note, $merged['resource']['object']['review'])));
        }

        return Command::SUCCESS;
    }
}
