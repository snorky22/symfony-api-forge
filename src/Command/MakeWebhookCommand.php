<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\ConfigDeclarations;
use Snorky22\ApiForge\Dev\Rest\ResourceMaker;
use Snorky22\ApiForge\Dev\Webhook\WebhookMaker;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(
    name: 'api-forge:make:webhook',
    description: 'Writes the hand-written webhooks of an API (request parser, consumer, routing), asking for what they need',
    help: <<<'HELP'
        Asks for the API, the signature scheme and the events, then writes, in the API's directory (src/API/<Api>/Webhook):
        the request parser (constants only), the consumer (one method per event, TODOs), and the routing of the
        webhook type with its secret (config/packages/<type>_webhook.yaml):

          <info>php %command.full_name%</info>
          <info>php %command.full_name% Paddle --scheme=paddle --events=transaction.completed --no-interaction</info>

        Run it again on the same API to add events to the consumer. Existing files are never overwritten.
        HELP,
)]
final class MakeWebhookCommand
{
    public function __construct(
        private readonly WebhookMaker $maker,
        private readonly ResourceMaker $resources,
        private readonly WebhookSender $sender,
        private readonly RouterInterface $router,
        private readonly string $projectDir,
        private readonly string $namespace,
        private readonly string $sourceDir,
        private readonly string $configDir = 'config/packages',
        private readonly string $generatedConfigDir = 'config/packages',
    ) {
    }

    public function __invoke(
        InputInterface $input,
        SymfonyStyle $io,
        #[Argument('API, relative to api_forge.rest.namespace (App\API), e.g. "Paddle"')] ?string $api = null,
        #[Option('Signature scheme: paddle, lemonsqueezy or custom')] ?string $scheme = null,
        #[Option('Comma-separated events to handle, e.g. "transaction.completed"')] ?string $events = null,
        #[Option('Webhook type, the end of the URL /webhook/{type} (default: from the API, "paddle")')] ?string $type = null,
        #[Option('Project directory to write into (default: this project)')] ?string $output = null,
    ): int {
        $interactive = $input->isInteractive();
        $root = rtrim($output ?? $this->projectDir, '/');
        $source = $root.'/'.trim($this->sourceDir, '/');

        if (null === $api && $interactive) {
            $question = new Question(\sprintf('API, relative to %s (e.g. Paddle)', $this->namespace));
            $question->setAutocompleterValues(array_values(array_map(static fn (string $name): string => rtrim($name, '\\'), array_filter($this->resources->existingNames($source), static fn (string $name): bool => str_ends_with($name, '\\')))));
            $question->setValidator(static fn (?string $value): string => null === $value || '' === trim($value, '\\ ') ? throw new \InvalidArgumentException('A value is required.') : trim($value, '\\ '));
            $api = $io->askQuestion($question);
        }
        $api = trim((string) $api, '\\ ');
        if (!preg_match('/^[A-Z]\w*(\\\\[A-Z]\w*)*$/', $api)) {
            $io->error('' === $api ? 'The API is required, e.g. "Paddle".' : \sprintf('"%s" is not a namespace: segments starting with a capital letter, separated by "\".', $api));

            return Command::FAILURE;
        }

        $short = basename(str_replace('\\', '/', $api));
        $namespace = $this->namespace.'\\'.$api.'\\Webhook';
        $parserClass = $namespace.'\\'.$short.'RequestParser';
        $consumerClass = $namespace.'\\'.$short.'WebhookConsumer';
        $directory = $source.'/'.str_replace('\\', '/', $api).'/Webhook';
        $parserFile = $directory.'/'.$short.'RequestParser.php';
        $consumerFile = $directory.'/'.$short.'WebhookConsumer.php';
        $filesystem = new Filesystem();

        // The consumer exists: add events to it (its type is the one of its attribute)
        if (file_exists($consumerFile)) {
            $code = (string) file_get_contents($consumerFile);
            if (null === $type && preg_match("/#\\[AsRemoteEventConsumer\\(\\s*(?:name:\\s*)?'([^']+)'/", $code, $m)) {
                $type = $m[1];
            }
            $type ??= $this->declaredType($io, $root, $api) ?? $this->maker->defaultType($api);
            try {
                $present = $this->maker->eventsOf($code);
            } catch (\InvalidArgumentException $e) {
                $io->error(\sprintf('%s: %s', $consumerFile, $e->getMessage()));

                return Command::FAILURE;
            }
            $io->text(\sprintf('%s exists, with: %s.', basename($consumerFile), [] === $present ? 'no event' : implode(', ', $present)));
            $suggested = array_values(array_diff($this->knownEvents(file_exists($parserFile) ? (string) file_get_contents($parserFile) : ''), $present));
            $list = $this->events($io, $interactive, $events, $suggested, 'Events to add (comma-separated, empty: none)');
            if ([] !== $already = array_values(array_intersect($list, $present))) {
                $io->note(\sprintf('Already there, left as they are: %s.', implode(', ', $already)));
            }
            $added = array_values(array_diff($list, $present));
            if ([] !== $added) {
                $filesystem->dumpFile($consumerFile, $this->maker->addEvents($code, $added));
                $io->success(\sprintf('Added %s to %s', implode(', ', $added), $consumerFile));
            } else {
                $io->success('Nothing to add to the consumer.');
            }
            if (file_exists($parserFile)) {
                $this->routing($io, $root, $type, $parserClass, null);
            } else {
                $io->warning(\sprintf('%s is missing: the routing of the webhook type "%s" is not written (it would name a class that does not exist). Write the parser, or remove the consumer and run the command again.', $parserFile, $type));
            }
            $this->checks($io, $root, $type);

            return Command::SUCCESS;
        }
        $type ??= $this->declaredType($io, $root, $api) ?? $this->maker->defaultType($api);

        // A new consumer: the scheme, then the events
        if (null === $scheme && $interactive) {
            $scheme = (string) $io->askQuestion(new ChoiceQuestion('Signature scheme', array_map(static fn (array $preset): string => $preset['label'], WebhookMaker::SCHEMES)));
        }
        if (null === $scheme || !isset(WebhookMaker::SCHEMES[$scheme])) {
            $io->error(\sprintf('Give the signature scheme with --scheme: %s.', implode(', ', array_keys(WebhookMaker::SCHEMES))));

            return Command::FAILURE;
        }
        $preset = WebhookMaker::SCHEMES[$scheme];
        $constants = $preset['constants'];
        if ('custom' === $scheme) {
            if (!$interactive) {
                $io->error('The "custom" scheme asks questions: run the command interactively (or choose a scheme, then edit the constants of the parser).');

                return Command::FAILURE;
            }
            $constants = $this->askConstants($io);
        }
        $list = $this->events($io, $interactive, $events, $preset['events'], 'Events to handle (comma-separated, empty: none for now)');

        try {
            $parser = $this->maker->parser($parserClass, $type, $constants, $preset['docs']);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (file_exists($parserFile)) {
            $io->text(\sprintf('%s exists: left as it is.', $parserFile));
        } else {
            $filesystem->dumpFile($parserFile, $parser);
            $io->success(\sprintf('Written: %s (scheme "%s")', $parserFile, $scheme));
        }
        $filesystem->dumpFile($consumerFile, $this->maker->consumer($consumerClass, $type, $list));
        $io->success(\sprintf('Written: %s%s', $consumerFile, [] === $list ? '' : ' ('.implode(', ', $list).')'));
        $this->routing($io, $root, $type, $parserClass, 'paddle' === $scheme ? 'Paddle: the secret key of the notification destination (Paddle > Events > Notifications).' : null);
        $this->checks($io, $root, $type);

        $event = $list[0] ?? 'test.event';
        $sample = $this->maker->samplePayload($constants, $event);
        // An event name read from a header is given to webhook:send
        $eventOption = null !== ($constants['EVENT_NAME_HEADER'] ?? null) ? \sprintf(' --event=%s', $event) : '';
        $io->text([
            'Next:',
            \sprintf(' - set the secret in .env.local: %s=...', $this->maker->secretVariable($type)),
            ' - fill the TODOs of the consumer;',
            ' - try a delivery signed like the provider, locally:',
            \sprintf("     printf '%%s' '%s' > var/%s-event.json", $sample, $type),
            \sprintf('     bin/console api-forge:webhook:send %s var/%s-event.json%s   (then api-forge:webhook:inbox)', $type, $type, $eventOption),
            \sprintf(' - for real deliveries, the provider needs a public URL to /webhook/%s (a tunnel in development).', $type),
        ]);

        return Command::SUCCESS;
    }

    /**
     * The events to handle: given, asked (autocompleted with the provider's), or none.
     *
     * @param list<string> $suggested
     *
     * @return list<string>
     */
    private function events(SymfonyStyle $io, bool $interactive, ?string $events, array $suggested, string $label): array
    {
        if (null === $events && $interactive) {
            if ([] !== $suggested) {
                $io->text(\sprintf('Known events: %s', implode(', ', $suggested)));
            }
            $question = new Question($label);
            $question->setAutocompleterValues($suggested);
            $events = (string) $io->askQuestion($question);
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $events)), static fn (string $event): bool => '' !== $event)));
    }

    /**
     * The events of the schemes whose header the parser uses, to suggest.
     *
     * @return list<string>
     */
    private function knownEvents(string $parser): array
    {
        foreach (WebhookMaker::SCHEMES as $preset) {
            $header = $preset['constants']['SIGNATURE_HEADER'] ?? null;
            if (\is_string($header) && str_contains($parser, "'".$header."'")) {
                return $preset['events'];
            }
        }

        return [];
    }

    /**
     * The constants of a "custom" scheme, asked.
     *
     * @return array<string, mixed>
     */
    private function askConstants(SymfonyStyle $io): array
    {
        $required = static fn (?string $value): string => null === $value || '' === trim($value) ? throw new \InvalidArgumentException('A value is required.') : trim($value);
        $constants = ['SIGNATURE_HEADER' => $io->ask('Signature header, e.g. X-Signature', null, $required)];
        if ($io->confirm('Does the header hold a timestamp and the signature, e.g. t=…,v1=… or ts=…;h1=…?', false)) {
            $constants['SIGNATURE_PARTS'] = [
                'separator' => $io->ask('Separator of the parts', ';'),
                'timestamp' => $io->ask('Key of the timestamp', 'ts'),
                'signature' => $io->ask('Key of the signature', 'h1'),
            ];
            $constants['SIGNATURE_CONTENT'] = $io->ask('Signed content ({timestamp}, {body})', '{timestamp}:{body}');
            $constants['SIGNATURE_TOLERANCE'] = (int) $io->ask('Seconds a timestamp may differ from now', '300');
        } else {
            $constants['SIGNATURE_PREFIX'] = (string) $io->ask('Prefix of the signature, e.g. sha256= (empty: none)', '');
        }
        $constants['SIGNATURE_ALGORITHM'] = $io->ask('HMAC algorithm', 'sha256');
        $constants['SIGNATURE_ENCODING'] = $io->choice('Encoding of the signature', ['hex', 'base64'], 'hex');
        $name = (string) $io->ask('Event name: its path in the payload (e.g. type, meta.event_name), or header:<Name>', null, $required);
        $constants[str_starts_with($name, 'header:') ? 'EVENT_NAME_HEADER' : 'EVENT_NAME_PATH'] = preg_replace('/^header:/', '', $name);
        $id = trim((string) $io->ask('Event id, to skip an event delivered twice: its path, or header:<Name> (empty: a hash of the body)', ''));
        if ('' !== $id) {
            $constants[str_starts_with($id, 'header:') ? 'EVENT_ID_HEADER' : 'EVENT_ID_PATH'] = preg_replace('/^header:/', '', $id);
        }
        $constants['SUCCESS_STATUS'] = (int) $io->ask('Status of the response to an accepted delivery (some APIs want 200)', '202');

        return $constants;
    }

    /**
     * Writes the routing of the webhook type, unless a file of the configuration directory routes it already. A file
     * written by api-forge:generate doesn't count: make:webhook has priority, the generator leaves the type to it.
     */
    private function routing(SymfonyStyle $io, string $root, string $type, string $parserClass, ?string $hint): void
    {
        $directory = $root.'/'.trim($this->configDir, '/');
        $file = $directory.'/'.$type.'_webhook.yaml';
        $declarations = new ConfigDeclarations();
        $all = $declarations->everywhere($declarations->webhooks($this->configDirectories($root)));
        $own = $declarations->handWrittenEverywhere($all)[$type] ?? [];
        $declared = array_values(array_filter($all[$type] ?? [], static fn (array $entry): bool => $entry['generated']));
        if ([] !== $own || file_exists($file)) {
            $io->text(\sprintf('The webhook type "%s" is routed already (%s).', $type, $own[0]['file'] ?? $file));

            return;
        }
        (new Filesystem())->dumpFile($file, $this->maker->routing($type, $parserClass, $hint));
        $io->success(\sprintf('Written: %s (the webhook type "%s", its secret %s)', $file, $type, $this->maker->secretVariable($type)));
        foreach ($declared as $generated) {
            $io->warning(\sprintf('%s (api-forge:generate) routes "%s" too: generate it again, it leaves the type to %s.', $generated['file'], $type, basename($file)));
        }
    }

    /**
     * The webhook type routed already for an API, whatever the case and separators ("LemonSqueezy" and
     * "lemonsqueezy"), or null.
     */
    private function declaredType(SymfonyStyle $io, string $root, string $api): ?string
    {
        $declarations = new ConfigDeclarations();
        $type = $declarations->matching($declarations->everywhere($declarations->webhooks($this->configDirectories($root))), basename(str_replace('\\', '/', $api)))[0] ?? null;
        if (null !== $type) {
            $io->text(\sprintf('The webhook type "%s" is routed already for this API: it is the default.', $type));
        }

        return $type;
    }

    /**
     * The configuration directories where a webhook type may be routed: the one of this command, and the one of the
     * generated code.
     *
     * @return list<string>
     */
    private function configDirectories(string $root): array
    {
        return array_values(array_unique([$root.'/'.trim($this->configDir, '/'), $root.'/'.trim($this->generatedConfigDir, '/')]));
    }

    /**
     * The route of the webhooks, and the asynchronous processing.
     */
    private function checks(SymfonyStyle $io, string $root, string $type): void
    {
        if (null === $this->router->getRouteCollection()->get('_webhook_controller')) {
            $file = \dirname($root.'/'.trim($this->configDir, '/')).'/routes/webhook.yaml';
            if (!file_exists($file)) {
                (new Filesystem())->dumpFile($file, "# The webhook endpoint of Symfony: POST /webhook/{type}\nwebhook:\n    resource: '@FrameworkBundle/Resources/config/routing/webhook.php'\n    prefix: /webhook\n");
                $io->success(\sprintf('Written: %s (the route /webhook/{type})', $file));
            }
        }
        if ([] === $this->sender->asyncTransports($type)) {
            $io->note([
                'Events are consumed during the request: the provider waits for your code (Paddle: 5 seconds, then it retries).',
                'To answer at once, queue them (config/packages/messenger.yaml): routing: Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage: async, then run messenger:consume async.',
            ]);
        }
    }
}
