<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Webhook\WebhookCheck;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:webhook:check',
    description: 'Checks the webhook chain of the application: routes, parser, secret, consumer, Messenger, public URL',
    help: <<<'HELP'
        Checks each webhook type of framework.webhook.routing, or the given ones:

          <info>php %command.full_name%</info>
          <info>php %command.full_name% lemonsqueezy</info>

        With --ping, also sends a signed ping (event "api_forge_ping", ignored by the consumers) to a public
        URL, e.g. the tunnel registered in the API's dashboard, to check the tunnel, the path and the secret:

          <info>php %command.full_name% lemonsqueezy --ping=https://abcd.ngrok-free.app/webhook/lemonsqueezy</info>
        HELP,
)]
final class WebhookCheckCommand
{
    public function __construct(
        private readonly WebhookDiagnostics $diagnostics,
        private readonly WebhookSender $sender,
    ) {
    }

    /**
     * @param list<string> $types
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Webhook types, i.e. API names (default: all the routed types)')] array $types = [],
        #[Option('Public URL to ping, e.g. a tunnel (requires one type)')] ?string $ping = null,
    ): int {
        $types = [] !== $types ? $types : $this->sender->types();
        if ([] === $types) {
            $io->warning('No webhook type routed by api-forge (framework.webhook.routing).');

            return Command::FAILURE;
        }
        if (null !== $ping && 1 !== \count($types)) {
            $io->error('--ping requires exactly one webhook type.');

            return Command::FAILURE;
        }

        $errors = 0;
        foreach ($types as $type) {
            $checks = $this->diagnostics->check($type);
            if (null !== $ping) {
                $checks[] = $this->diagnostics->ping($type, $ping);
            }
            $io->section($type);
            $errors += self::render($io, $checks);
        }

        if ($errors > 0) {
            $io->error(\sprintf('%d problem(s) found.', $errors));

            return Command::FAILURE;
        }
        $io->success('The webhook chain is ready.');

        return Command::SUCCESS;
    }

    /**
     * @param list<WebhookCheck> $checks
     *
     * @return int the number of errors
     */
    public static function render(SymfonyStyle $io, array $checks): int
    {
        $errors = 0;
        foreach ($checks as $check) {
            $io->text(match ($check->level) {
                WebhookCheck::OK => '<info>✔</info> ',
                WebhookCheck::WARNING => '<comment>!</comment> ',
                default => '<error>✘</error> ',
            }.$check->message);
            $errors += WebhookCheck::ERROR === $check->level ? 1 : 0;
        }

        return $errors;
    }
}
