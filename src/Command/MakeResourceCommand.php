<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Rest\ResourceMaker;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
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
        (api_forge.rest.source_dir, src/API). An existing file is never overwritten.
        HELP,
)]
final class MakeResourceCommand
{
    public function __construct(
        private readonly ResourceMaker $maker,
        private readonly string $projectDir,
        private readonly string $namespace,
        private readonly string $sourceDir,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        SymfonyStyle $io,
        #[Argument('Class name, relative to api_forge.rest.namespace (App\API), e.g. "Paddle\Prices"')] ?string $class = null,
        #[Option('Path of the resource, relative to the base URI (default: from the class name, "prices")')] ?string $path = null,
        #[Option('Service id of the scoped HTTP client (default: from the namespace, "paddle.client")')] ?string $client = null,
        #[Option('Comma-separated actions: list, get, create, update, delete (default: list,get)')] ?string $actions = null,
        #[Option('Key wrapping the responses, e.g. "data" (default: none)')] ?string $envelope = null,
        #[Option('URL of the API docs for this resource')] ?string $docs = null,
        #[Option('Project directory to write into (default: this project)')] ?string $output = null,
    ): int {
        $interactive = $input->isInteractive();
        if (null === $class && $interactive) {
            $class = $io->ask(\sprintf('Class name, relative to %s (e.g. Paddle\Prices)', $this->namespace), null, self::required(...));
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

        $file = rtrim($output ?? $this->projectDir, '/').'/'.trim($this->sourceDir, '/').'/'.str_replace('\\', '/', $class).'.php';
        if (file_exists($file)) {
            $io->error(\sprintf('%s already exists: it is never overwritten.', $file));

            return Command::FAILURE;
        }

        if ($interactive) {
            $path ??= $io->ask('Path of the resource, relative to the base URI of the API', $this->maker->defaultPath($class), self::required(...));
            $client ??= $io->ask('Service id of the scoped HTTP client', $this->maker->defaultClient($class), self::required(...));
            if (null === $actions) {
                $question = new ChoiceQuestion('Actions (comma-separated)', [
                    'list' => 'GET {path}',
                    'get' => 'GET {path}/{id}',
                    'create' => 'POST {path}',
                    'update' => 'PATCH {path}/{id}',
                    'delete' => 'DELETE {path}/{id}',
                ], 'list,get');
                $question->setMultiselect(true);
                $question->setMaxAttempts(3);
                $actions = implode(',', (array) $io->askQuestion($question));
            }
            $envelope ??= $io->ask('Key wrapping the responses, e.g. "data" (empty: none)');
            $docs ??= $io->ask('URL of the API docs for this resource (empty: none)');
        }

        $path ??= $this->maker->defaultPath($class);
        $client ??= $this->maker->defaultClient($class);
        $list = array_values(array_filter(array_map('trim', explode(',', $actions ?? 'list,get'))));

        try {
            $code = $this->maker->make($this->namespace.'\\'.$class, trim($path, '/'), $client, $list, '' === $envelope ? null : $envelope, '' === $docs ? null : $docs);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        (new Filesystem())->dumpFile($file, $code);
        $io->success(\sprintf('Written: %s', $file));
        if (null !== $other = self::otherCase(rtrim($output ?? $this->projectDir, '/'), trim($this->sourceDir, '/'))) {
            $io->warning(\sprintf('The directory is "%s", not "%s": the file system ignores the case here, a case-sensitive one (Linux) would not find the class. Rename the directory, or set api_forge.rest.source_dir.', $other, trim($this->sourceDir, '/')));
        }
        $io->text([
            'Next:',
            \sprintf(' - fill the TODOs of %s (the keys you use);', basename($file)),
            \sprintf(' - declare the scoped client "%s" if it does not exist yet (framework.http_client.scoped_clients:', $client),
            '   base_uri, auth_bearer or headers); see docs/rest-resources.md.',
        ]);

        return Command::SUCCESS;
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
