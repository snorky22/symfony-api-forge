<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Rest\ResourceMaker;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'api-forge:make:resource',
    description: 'Writes a hand-written REST resource class, asking for what it needs',
    help: <<<'HELP'
        Asks for the path, the HTTP client and the actions, then writes the class with TODOs for the shapes:

          <info>php %command.full_name%</info>
          <info>php %command.full_name% Paddle\\Prices --actions=list,get,update --envelope=data --no-interaction</info>

        The class name is relative to api_forge.rest.namespace (App\API by default), and written in its directory
        (api_forge.rest.source_dir, src/API).

        When the class exists, the command offers the actions it does not have yet, and adds those chosen (use
        statements, interfaces, traits, annotations); the rest of the file is kept as it is:

          <info>php %command.full_name% Paddle\\Prices --actions=get,create</info>
        HELP,
)]
final class MakeResourceCommand
{
    private const CHOICES = [
        'list' => 'GET {path}',
        'get' => 'GET {path}/{id}',
        'create' => 'POST {path}',
        'update' => 'PATCH {path}/{id}',
        'delete' => 'DELETE {path}/{id}',
    ];

    public function __construct(
        private readonly ResourceMaker $maker,
        private readonly string $projectDir,
        private readonly string $namespace,
        private readonly string $sourceDir,
        private readonly string $configDir = 'config/packages',
    ) {
    }

    public function __invoke(
        InputInterface $input,
        SymfonyStyle $io,
        #[Argument('Class name, relative to api_forge.rest.namespace (App\API), e.g. "Paddle\Prices"')] ?string $class = null,
        #[Option('Endpoint of the resource from the docs, "https://" optional, e.g. "api.paddle.com/prices"')] ?string $endpoint = null,
        #[Option('Path of the resource, relative to the base URI (default: from the endpoint, or the class name)')] ?string $path = null,
        #[Option('Service id of the scoped HTTP client (default: from the namespace, "paddle.client")')] ?string $client = null,
        #[Option('Comma-separated actions: list, get, create, update, delete (default: list,get)')] ?string $actions = null,
        #[Option('Key wrapping the responses, e.g. "data" (default: none)')] ?string $envelope = null,
        #[Option('URL of the API docs for this resource')] ?string $docs = null,
        #[Option('Project directory to write into (default: this project)')] ?string $output = null,
    ): int {
        $interactive = $input->isInteractive();
        if (null === $class && $interactive) {
            // Autocompleted with the namespaces and classes already there (in a terminal)
            $question = new Question(\sprintf('Class name, relative to %s (e.g. Paddle\Prices)', $this->namespace));
            $question->setAutocompleterValues($this->maker->existingNames($this->sourceRoot($output)));
            $question->setValidator(self::required(...));
            $class = $io->askQuestion($question);
        }
        if (null === $class || '' === trim($class, '\\ ')) {
            $io->error('The class name is required.');

            return Command::FAILURE;
        }
        $class = trim($class, '\\ ');
        if (!preg_match('/^[A-Z]\w*(\\\\[A-Z]\w*)*$/', $class)) {
            $io->error(\sprintf('"%s" is not a class name: segments starting with a capital letter, separated by "\".', $class));

            return Command::FAILURE;
        }

        $file = $this->sourceRoot($output).'/'.str_replace('\\', '/', $class).'.php';
        if (file_exists($file)) {
            return $this->addActions($io, $interactive, $file, $this->namespace.'\\'.$class, $actions, $envelope, $endpoint, $output);
        }

        if (null === $endpoint && $interactive) {
            $endpoint = $io->ask('Endpoint of the resource, from the docs, e.g. api.paddle.com/prices (empty: none)', null, function (?string $value): ?string {
                return null === $value || '' === trim($value) ? null : $this->maker->normalizeEndpoint($value);
            });
        } elseif (null !== $endpoint) {
            try {
                $endpoint = $this->maker->normalizeEndpoint($endpoint);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }
        $defaultPath = (null === $endpoint ? null : $this->maker->pathOf($endpoint)) ?? $this->maker->defaultPath($class);

        if ($interactive) {
            $path ??= $io->ask('Path of the resource, relative to the base URI of the API', $defaultPath, self::required(...));
            $client ??= $io->ask('Service id of the scoped HTTP client', $this->maker->defaultClient($class), self::required(...));
            if (null === $actions) {
                $question = new ChoiceQuestion('Actions (comma-separated)', self::CHOICES, 'list,get');
                $question->setMultiselect(true);
                $question->setMaxAttempts(3);
                $actions = implode(',', (array) $io->askQuestion($question));
            }
            $envelope ??= $io->ask('Key wrapping the responses, e.g. "data" (empty: none)');
            $docs ??= $io->ask('URL of the API docs for this resource (empty: none)');
        }

        $path ??= $defaultPath;
        $client ??= $this->maker->defaultClient($class);
        $list = array_values(array_filter(array_map('trim', explode(',', $actions ?? 'list,get'))));

        try {
            $code = $this->maker->make($this->namespace.'\\'.$class, trim($path, '/'), $client, $list, '' === $envelope ? null : $envelope, '' === $docs ? null : $docs, $endpoint);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        (new Filesystem())->dumpFile($file, $code);
        $io->success(\sprintf('Written: %s', $file));
        if (null !== $other = self::otherCase(rtrim($output ?? $this->projectDir, '/'), trim($this->sourceDir, '/'))) {
            $io->warning(\sprintf('The directory is "%s", not "%s": the file system ignores the case here, a case-sensitive one (Linux) would not find the class. Rename the directory, or set api_forge.rest.source_dir.', $other, trim($this->sourceDir, '/')));
        }
        $this->writeClientConfiguration($io, $output, $client, $endpoint, trim($path, '/'));
        $io->text(\sprintf('Next: fill the TODOs of %s (the keys you use).', basename($file)));

        return Command::SUCCESS;
    }

    private function sourceRoot(?string $output): string
    {
        return rtrim($output ?? $this->projectDir, '/').'/'.trim($this->sourceDir, '/');
    }

    /**
     * The class exists: asks for its endpoint when it does not record one, and adds the actions it does not have
     * yet, chosen among them.
     */
    private function addActions(SymfonyStyle $io, bool $interactive, string $file, string $class, ?string $actions, ?string $envelope, ?string $endpoint, ?string $output): int
    {
        $code = $original = (string) file_get_contents($file);
        try {
            $implemented = $this->maker->implementedActions($code);
        } catch (\InvalidArgumentException $e) {
            $io->error(\sprintf('%s: %s', $file, $e->getMessage()));

            return Command::FAILURE;
        }
        $missing = array_values(array_diff(array_keys(ResourceMaker::ACTIONS), $implemented));
        $recorded = $this->maker->endpointOf($code);
        $io->text(\sprintf('%s exists, with: %s; endpoint: %s.', basename($file), [] === $implemented ? 'no action' : implode(', ', $implemented), $recorded ?? 'none'));

        // The endpoint, when the class does not record one (or when given as an option)
        try {
            $endpoint = null === $endpoint || '' === trim($endpoint) ? null : $this->maker->normalizeEndpoint($endpoint);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (null === $endpoint && null === $recorded && $interactive) {
            $endpoint = $io->ask('Endpoint of the resource, from the docs, e.g. api.paddle.com/prices (empty: none)', null, function (?string $value): ?string {
                return null === $value || '' === trim($value) ? null : $this->maker->normalizeEndpoint($value);
            });
        }
        if (null !== $endpoint) {
            $code = $this->maker->withEndpoint($code, $endpoint);
        }

        // The actions
        $list = [];
        if ([] === $missing) {
            $io->text('It has all the actions.');
        } else {
            if (null === $actions && $interactive) {
                $question = new ChoiceQuestion('Actions to add (comma-separated, empty: none)', array_intersect_key(self::CHOICES, array_flip($missing)));
                $question->setMultiselect(true);
                $question->setMaxAttempts(3);
                $question->setValidator(static fn (?string $answer): array => self::choices($answer, $missing));
                $actions = implode(',', (array) $io->askQuestion($question));
            }
            if (null === $actions) {
                $io->note(\sprintf('Give the actions to add with --actions (missing: %s).', implode(', ', $missing)));
            }
            $list = array_values(array_filter(array_map('trim', explode(',', $actions ?? ''))));
            if ([] !== $already = array_values(array_intersect($list, $implemented))) {
                $io->note(\sprintf('Already there, left as they are: %s.', implode(', ', $already)));
            }
            $list = array_values(array_diff($list, $implemented));
        }

        if ([] !== $list) {
            // The key wrapping the responses: as the class says it, else asked
            $found = $this->maker->envelopeOf($code);
            if (false !== $found) {
                $envelope = $found;
            } elseif (null === $envelope && $interactive) {
                $envelope = $io->ask('Key wrapping the responses, e.g. "data" (empty: none)');
            }
            try {
                $code = $this->maker->addActions($code, $class, $list, '' === $envelope ? null : $envelope);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $client = $this->maker->clientIn($code) ?? $this->maker->defaultClient($class);
        $endpoint ??= $recorded;
        if ($code === $original) {
            $this->writeClientConfiguration($io, $output, $client, $endpoint, $this->maker->pathIn($code));
            $io->success('Nothing to change in the class.');

            return Command::SUCCESS;
        }
        (new Filesystem())->dumpFile($file, $code);
        $changes = [];
        if ([] !== $list) {
            $changes[] = implode(', ', array_values(array_intersect(array_keys(ResourceMaker::ACTIONS), $list)));
        }
        if (null === $recorded && null !== $endpoint) {
            $changes[] = 'the endpoint';
        }
        $io->success(\sprintf('Added %s to %s', implode(' and ', $changes), $file));
        if ([] !== $list) {
            $io->text('Fill the new TODO shapes (array<string, mixed>) with the keys you use.');
        }
        $this->writeClientConfiguration($io, $output, $client, $endpoint, $this->maker->pathIn($code));

        return Command::SUCCESS;
    }

    /**
     * The answer of a multiselect question on actions, empty allowed.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function choices(?string $answer, array $allowed): array
    {
        $chosen = array_values(array_filter(array_map('trim', explode(',', (string) $answer))));
        if ([] !== $unknown = array_diff($chosen, $allowed)) {
            throw new \InvalidArgumentException(\sprintf('Unknown or present action(s): %s. Choose among: %s.', implode(', ', $unknown), implode(', ', $allowed)));
        }

        return $chosen;
    }

    /**
     * Writes the configuration of the scoped client, unless a file of the configuration directory declares it; fills
     * in the base URI of a file written without it.
     */
    private function writeClientConfiguration(SymfonyStyle $io, ?string $output, string $client, ?string $endpoint, ?string $path): void
    {
        $directory = rtrim($output ?? $this->projectDir, '/').'/'.trim($this->configDir, '/');
        $file = $directory.'/'.$this->maker->clientFile($client);
        $baseUri = null === $endpoint || null === $path ? null : $this->maker->baseUri($endpoint, $path);
        if (null !== $endpoint && null === $baseUri) {
            $io->warning(\sprintf('The endpoint "%s" does not end with the path "%s": write the base URI of the client by hand.', $endpoint, $path ?? ''));
        }

        if (null !== $declared = $this->maker->declaringFile($directory, $client)) {
            $content = (string) file_get_contents($declared);
            if (null !== $baseUri && $declared === $file && null !== $filled = $this->maker->withBaseUri($content, $baseUri)) {
                (new Filesystem())->dumpFile($file, $filled);
                $io->text(\sprintf('Base URI of the scoped client "%s" filled in: %s.', $client, $file));
            } else {
                $io->text(\sprintf('The scoped client "%s" is declared in %s.', $client, $declared));
            }

            return;
        }
        (new Filesystem())->dumpFile($file, $this->maker->clientConfiguration($client, $baseUri));
        $io->success(\sprintf('Written: %s (the scoped client "%s"%s)', $file, $client, null === $baseUri ? ', its base URI to fill in' : ''));
        $io->text(\sprintf('Set its API key in the environment, e.g. in .env.local: %s=... (and %s for another base URI, e.g. a sandbox).', $this->maker->envVariable($client), $this->maker->envVariable($client, 'BASE_URI')));
    }

    /**
     * The directory as it is on disk when its case differs from the configured one ("src/Api" for "src/API"), or null.
     */
    private static function otherCase(string $root, string $relative): ?string
    {
        $actual = [];
        foreach (explode('/', $relative) as $segment) {
            $entries = scandir($root.'/'.implode('/', $actual)) ?: [];
            $found = array_values(array_filter($entries, static fn (string $entry) => 0 === strcasecmp($entry, $segment)));
            $actual[] = \in_array($segment, $found, true) || [] === $found ? $segment : $found[0];
        }

        return implode('/', $actual) === $relative ? null : implode('/', $actual);
    }

    private static function required(?string $value): string
    {
        if (null === $value || '' === trim($value)) {
            throw new \RuntimeException('A value is required.');
        }

        return trim($value);
    }
}
