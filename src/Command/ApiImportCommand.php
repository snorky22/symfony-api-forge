<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;
use Snorky22\ApiForge\Dev\Api\Import\DescriptorMerger;
use Snorky22\ApiForge\Dev\Api\Import\EndpointImporter;
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
        private readonly CurlParser $curlParser,
        private readonly EndpointImporter $importer,
        private readonly DescriptorMerger $merger,
        private readonly DescriptorStore $store,
        private readonly ExampleReader $examples,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Argument('File containing the curl command, or an example of another format (.php: request and response as an array)')] string $curl,
        #[Option('File containing the example JSON response (or an array, .php)')] ?string $response = null,
        #[Option('Resource name (default: first path segment)')] ?string $resource = null,
        #[Option('Operation name (default: inferred from method and path)')] ?string $operation = null,
        #[Option('Replace an existing operation with the same name')] bool $force = false,
        #[Option('Descriptor directory (default: api_forge.descriptor_dir, config/api)')] ?string $dir = null,
    ): int {
        try {
            $existingApi = $this->store->loadApi($api, $dir);
            $example = $this->examples->operation($curl, $existingApi['base_uri'] ?? null);
            if (null === $example->curl) {
                throw new \InvalidArgumentException(\sprintf('"%s" holds no request: add a "request" ("METHOD /path").', $curl));
            }
            if (null !== $response && null !== $example->response) {
                throw new \InvalidArgumentException(\sprintf('The response is given twice: "response" in %s and --response.', $curl));
            }
            $request = $this->curlParser->parse($example->curl);
            $responseJson = null !== $response ? $this->examples->json($response) : $example->response;
            $endpoint = $this->importer->import(
                $request,
                $api,
                $responseJson,
                isset($existingApi['base_uri']) ? [$existingApi['base_uri'], ...array_values($existingApi['environments'] ?? [])] : null,
                $resource,
                $operation,
                $existingApi['format'] ?? null,
            );
            $merged = $this->merger->merge($existingApi, $this->store->loadResource($api, $endpoint->resource, $dir), $endpoint, $force);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $files = [
            $this->store->saveApi($api, $merged['api'], $dir),
            $this->store->saveResource($api, $endpoint->resource, $merged['resource'], $dir),
        ];

        $saved = $merged['resource']['operations'][$endpoint->operationName];
        $io->definitionList(
            ['Resource' => $endpoint->resource],
            ['Operation' => $endpoint->operationName],
            ['Endpoint' => $saved['method'].' '.$saved['path']],
            ['Files' => implode("\n", $files)],
        );
        $io->listing($merged['notes']);
        if (isset($saved['review'])) {
            $io->warning(array_merge(['To review in the descriptor:'], array_map(static fn (string $note) => '- '.$note, $saved['review'])));
        }

        return Command::SUCCESS;
    }
}
