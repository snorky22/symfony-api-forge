<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Webhook\DeliveryInbox;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:webhook:replay',
    description: 'Delivers a webhook of the inbox again, e.g. after fixing its consumer',
    help: <<<'HELP'
        Takes a delivery of the inbox (api-forge:webhook:inbox), signs its body again with the secret of the
        webhook routing, and delivers it to this application, in-process:

          <info>php %command.full_name% 20261103-081014</info>     (an id or its beginning)

        The replay is a new delivery in the inbox, marked "simulated".
        HELP,
)]
final class WebhookReplayCommand
{
    public function __construct(
        private readonly DeliveryInbox $inbox,
        private readonly WebhookSender $sender,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Id (or beginning of the id) of the delivery')] string $id,
    ): int {
        $delivery = $this->inbox->find($id);
        if (null === $delivery) {
            $io->error(\sprintf('No single delivery matches "%s": see api-forge:webhook:inbox.', $id));

            return Command::FAILURE;
        }

        try {
            $response = $this->sender->send($delivery['type'], $delivery['body'], $delivery['event']);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $status = $response->getStatusCode();
        $io->text(\sprintf('Replayed %s (%s): HTTP %d', $delivery['id'], $delivery['event'] ?? '?', $status));
        if ($status >= 200 && $status < 300) {
            $io->success([] !== $this->sender->asyncTransports($delivery['type']) ? 'Accepted, and queued: the worker consumes it (messenger:consume).' : 'Accepted and consumed.');

            return Command::SUCCESS;
        }
        $io->error('The delivery failed: '.mb_strimwidth(trim(strip_tags((string) $response->getContent())), 0, 300, '…'));

        return Command::FAILURE;
    }
}
