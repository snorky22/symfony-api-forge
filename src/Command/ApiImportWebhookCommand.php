<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Api\Import\WebhookImportRunner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:import-webhook',
    description: 'Imports webhook documentation (events table, payload examples) into the API descriptors',
    help: <<<'HELP'
        Writes or updates <comment>config/api/<api>/_webhooks.yaml</comment>:

          <info>php %command.full_name% lemonsqueezy --events="tests/Fixtures/Docs Event Types • Lemon Squeezy.md"</info>
          <info>php %command.full_name% lemonsqueezy --payload=tests/Fixtures/order-created-payload.json</info>

        --events reads a Markdown table of events (event name, object sent, description).
        --payload reads example payloads (repeatable). When the payload holds an object of a resource
        (JSON:API "data"; in plain JSON, a "data" object: its resource comes from the event name,
        "transaction.completed" gives "transactions"), the object is checked against the object shape of
        the resource, or gives it when the resource has none yet; in plain JSON, the other members give
        the "envelope", and the other events of the same entity get the resource too.
        The docs page of a payload, copied as text, is read from <payload>.docs.md next to it
        (or --docs, for a single payload): optional fields, nulls, enums.
        Existing descriptor values are kept (--force: the payload replaces the shape of its event, or the
        object shape of its resource and the envelope).
        Guesses are listed under "review".
        HELP,
)]
final class ApiImportWebhookCommand
{
    public function __construct(private readonly WebhookImportRunner $runner)
    {
    }

    /**
     * @param list<string> $payload
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Option('Markdown file with the table of events')] ?string $events = null,
        #[Option('File with an example payload (repeatable): JSON, or an array (.php)')] array $payload = [],
        #[Option('Event name of the payload, when it cannot be read from it')] ?string $event = null,
        #[Option('Text capture of the docs page of the payload (default: <payload>.docs.md next to it, if any)')] ?string $docs = null,
        #[Option('Replace the shape of the event of each payload, or the object shape of its resource and the envelope (other values are kept)')] bool $force = false,
        #[Option('Descriptor directory (default: api_forge.descriptor_dir, config/api)')] ?string $dir = null,
        #[Option('When the object of the payload is already described from another example: keep it (default), merge the examples, or replace it')] ?string $object = null,
    ): int {
        try {
            $result = $this->runner->import($api, $events, $payload, $event, $docs, $force, $dir, $object);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->listing(array_merge($result['notes'], array_map(static fn (string $file) => 'written: '.$file, $result['files'])));
        if ([] !== $result['review']) {
            $io->warning(array_merge(['To review in the descriptor:'], array_map(static fn (string $note) => '- '.$note, $result['review'])));
        }

        return Command::SUCCESS;
    }
}
