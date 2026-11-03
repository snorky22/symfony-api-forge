<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:webhook:send',
    description: 'Sends a signed webhook delivery to this application, as the API would: no tunnel, no real event needed',
    help: <<<'HELP'
        Signs the payload of a file with the secret of the webhook routing, and delivers it to
        <comment>/webhook/<type></comment> of this application, in-process:

          <info>php %command.full_name% lemonsqueezy tests/Fixtures/order-created-payload.json</info>

        Values of the payload can be replaced, in dot notation; values are JSON (numbers, booleans,
        null, objects), or strings otherwise:

          <info>php %command.full_name% lemonsqueezy order.json --set meta.custom_data.user_id=42 --set data.attributes.status=refunded</info>

        With --url, the delivery is sent over HTTP instead, e.g. to a running server or a tunnel:

          <info>php %command.full_name% lemonsqueezy order.json --url=https://127.0.0.1:8000/webhook/lemonsqueezy</info>

        For APIs sending the event name in a header, give it with --event.
        HELP,
)]
final class WebhookSendCommand
{
    public function __construct(
        private readonly WebhookSender $sender,
    ) {
    }

    /**
     * @param list<string> $set
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Webhook type, i.e. the API name, e.g. "lemonsqueezy"')] string $type,
        #[Argument('File containing the JSON payload')] string $file,
        #[Option('Value to replace, as path=value, e.g. meta.custom_data.user_id=42')] array $set = [],
        #[Option('Event name, for APIs sending it in a header')] ?string $event = null,
        #[Option('Send over HTTP to this URL instead of in-process')] ?string $url = null,
    ): int {
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            $io->error(\sprintf('Cannot read file "%s".', $file));

            return Command::FAILURE;
        }

        try {
            $payload = [] === $set ? $content : self::replace($content, $set);
            if (null !== $url) {
                [$status, $body] = $this->sender->sendTo($url, $type, $payload, $event);
            } else {
                $response = $this->sender->send($type, $payload, $event);
                [$status, $body] = [$response->getStatusCode(), (string) $response->getContent()];
            }
        } catch (\InvalidArgumentException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->text(\sprintf('POST %s: HTTP %d', $url ?? $this->sender->url($type), $status));
        if ('' !== trim($body)) {
            $io->text(mb_strimwidth(trim($body), 0, 500, '…'));
        }

        $async = $this->sender->asyncTransports($type);
        match (true) {
            $status >= 200 && $status < 300 && null !== $url => $io->success('Accepted by the request parser.'),
            $status >= 200 && $status < 300 && [] !== $async => $io->success(\sprintf('Accepted, and queued on the "%s" transport: the consumer runs in the worker (messenger:consume %s).', implode('", "', $async), implode(' ', $async))),
            $status >= 200 && $status < 300 => $io->success('Accepted and consumed (no asynchronous transport for webhook events).'),
            406 === $status => $io->error('Rejected by the request parser (signature, event name): see the message above.'),
            default => $io->error('The delivery failed: see the message above and the logs.'),
        };

        return $status >= 200 && $status < 300 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $set
     *
     * @throws \JsonException|\InvalidArgumentException
     */
    private static function replace(string $json, array $set): string
    {
        $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        foreach ($set as $assignment) {
            if (!str_contains($assignment, '=')) {
                throw new \InvalidArgumentException(\sprintf('Invalid --set "%s": expected path=value.', $assignment));
            }
            [$path, $raw] = explode('=', $assignment, 2);
            try {
                $value = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $value = $raw;
            }
            $node = &$payload;
            foreach (explode('.', $path) as $key) {
                if (!\is_array($node)) {
                    $node = [];
                }
                $node = &$node[$key];
            }
            $node = $value;
            unset($node);
        }

        return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
