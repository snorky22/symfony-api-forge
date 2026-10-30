<?php

namespace App\Command;

use App\Dev\Api\Descriptor\DescriptorStore;
use App\Dev\Api\Import\DescriptorMerger;
use App\Dev\Api\Import\WebhookImporter;
use App\Dev\Api\Import\WebhookMerger;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api:import-webhook',
    description: 'Imports webhook documentation (events table, payload examples) into the API descriptors',
    help: <<<'HELP'
        Writes or updates <comment>config/api/<api>/_webhooks.yaml</comment>:

          <info>php %command.full_name% lemonsqueezy --events="tests/Fixtures/Docs Event Types • Lemon Squeezy.md"</info>
          <info>php %command.full_name% lemonsqueezy --payload=tests/Fixtures/order-created-payload.json</info>

        --events reads a Markdown table of events (event name, object sent, description).
        --payload reads example payloads (repeatable). For JSON:API APIs, the payload data is checked
        against the object shape of its resource, or gives it when the resource has none yet.
        Existing descriptor values are kept. Guesses are listed under "review".
        HELP,
)]
final class ApiImportWebhookCommand
{
    public function __construct(
        private readonly WebhookImporter $importer,
        private readonly WebhookMerger $webhookMerger,
        private readonly DescriptorMerger $descriptorMerger,
        private readonly DescriptorStore $store,
    ) {
    }

    /**
     * @param list<string> $payload
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Option('Markdown file with the table of events')] ?string $events = null,
        #[Option('File with an example payload (repeatable)')] array $payload = [],
        #[Option('Event name of the payload, when it cannot be read from it')] ?string $event = null,
        #[Option('Descriptor directory (default: config/api)')] ?string $dir = null,
    ): int {
        if (null === $events && [] === $payload) {
            $io->error('Give --events and/or --payload.');

            return Command::FAILURE;
        }
        if (null !== $event && \count($payload) > 1) {
            $io->error('--event can only be used with a single --payload.');

            return Command::FAILURE;
        }

        $webhooks = $this->store->loadWebhooks($api, $dir);
        $format = $this->store->loadApi($api, $dir)['format'] ?? 'json';
        $notes = [];
        $files = [];

        try {
            if (null !== $events) {
                $merged = $this->webhookMerger->mergeEvents($webhooks, $api, $this->importer->importEvents($this->read($events)));
                $webhooks = $merged['webhooks'];
                $notes = array_merge($notes, $merged['notes']);
            }

            foreach ($payload as $file) {
                $description = $this->importer->importPayload($this->read($file), $format);
                $resource = null;
                $review = [];

                if (null !== $description['type']) {
                    $resource = $this->resourceOfType($api, $description['type'], $dir);
                    $descriptor = $this->store->loadResource($api, $resource, $dir);
                    if (isset($descriptor['object'])) {
                        if ($descriptor['object']['shape'] !== $description['data']) {
                            $review[] = \sprintf('payload data differs from the "%s" object shape: merge both examples into the object shape with app:api:import-object --force, or edit it', $resource);
                        }
                    } else {
                        $merged = $this->descriptorMerger->mergeObject($descriptor, $resource, $description['type'], ['shape' => $description['data']]);
                        $files[] = $this->store->saveResource($api, $resource, $merged['resource'], $dir);
                        $notes[] = \sprintf('object of the "%s" resource set from the payload', $resource);
                    }
                }

                $merged = $this->webhookMerger->mergePayload($webhooks, $api, $description, $event, $resource, $review);
                $webhooks = $merged['webhooks'];
                $notes = array_merge($notes, $merged['notes']);
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $files[] = $this->store->saveWebhooks($api, $webhooks, $dir);
        $io->listing(array_merge($notes, array_map(static fn (string $file) => 'written: '.$file, array_unique($files))));

        $review = $webhooks['review'] ?? [];
        foreach ($webhooks['events'] as $name => $definition) {
            foreach ($definition['review'] ?? [] as $note) {
                $review[] = \sprintf('event "%s": %s', $name, $note);
            }
        }
        if ([] !== $review) {
            $io->warning(array_merge(['To review in the descriptor:'], array_map(static fn (string $note) => '- '.$note, $review)));
        }

        return Command::SUCCESS;
    }

    /**
     * The resource whose JSON:API type is $type, or the resource named after the type.
     */
    private function resourceOfType(string $api, string $type, ?string $dir): string
    {
        foreach (array_keys($this->store->resourceFiles($api, $dir)) as $resource) {
            if ($type === ($this->store->loadResource($api, $resource, $dir)['type'] ?? null)) {
                return $resource;
            }
        }

        return $type;
    }

    private function read(string $file): string
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            throw new \InvalidArgumentException(\sprintf('Cannot read file "%s".', $file));
        }

        return $content;
    }
}
