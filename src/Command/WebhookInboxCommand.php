<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Webhook\DeliveryInbox;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:webhook:inbox',
    description: 'Lists the webhook deliveries received in development, with their outcome; --show to see one',
    help: <<<'HELP'
        The last deliveries received on /webhook/<type>, most recent first: event, origin (provider,
        simulated by api-forge:webhook:send, ping of the doctor), outcome (consumed, queued, rejected, failed)
        and its reason:

          <info>php %command.full_name%</info>
          <info>php %command.full_name% lemonsqueezy --limit=50</info>
          <info>php %command.full_name% --show=20261103-081014</info>     (an id or its beginning)

        Replay one with api-forge:webhook:replay.
        HELP,
)]
final class WebhookInboxCommand
{
    public function __construct(
        private readonly DeliveryInbox $inbox,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Webhook type, i.e. the API name (default: all)')] ?string $type = null,
        #[Option('Number of deliveries listed')] int $limit = 20,
        #[Option('Id (or beginning of the id) of a delivery to show: headers and body')] ?string $show = null,
    ): int {
        if (null !== $show) {
            $delivery = $this->inbox->find($show);
            if (null === $delivery) {
                $io->error(\sprintf('No single delivery matches "%s".', $show));

                return Command::FAILURE;
            }
            $io->definitionList(
                ['Id' => $delivery['id']],
                ['Received' => $delivery['received_at']],
                ['Type' => $delivery['type']],
                ['Event' => ($delivery['event'] ?? '?').(null !== $delivery['event_id'] ? ' ('.$delivery['event_id'].')' : '')],
                ['Origin' => $delivery['origin']],
                ['Outcome' => $delivery['status'].' (HTTP '.$delivery['http_status'].')'.(null !== $delivery['reason'] ? ': '.$delivery['reason'] : '')],
            );
            $io->section('Headers');
            $io->text(array_map(static fn (string $name, string $value) => $name.': '.$value, array_keys($delivery['headers']), $delivery['headers']));
            $io->section('Body');
            $body = json_decode($delivery['body'], true);
            $io->writeln(null !== $body ? json_encode($body, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : $delivery['body']);

            return Command::SUCCESS;
        }

        $deliveries = $this->inbox->latest($type, $limit);
        if ([] === $deliveries) {
            $io->text('No delivery received yet.');

            return Command::SUCCESS;
        }
        $io->table(['Id', 'Received', 'Type', 'Event', 'Origin', 'Outcome'], array_map(static fn (array $delivery) => [
            $delivery['id'],
            (new \DateTimeImmutable($delivery['received_at']))->format('Y-m-d H:i:s'),
            $delivery['type'],
            $delivery['event'] ?? '?',
            $delivery['origin'],
            $delivery['status'].(null !== $delivery['reason'] ? ': '.mb_strimwidth($delivery['reason'], 0, 60, '…') : ''),
        ], $deliveries));

        return Command::SUCCESS;
    }
}
