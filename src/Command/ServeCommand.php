<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Web\WebConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'api-forge:serve',
    description: 'Runs the local web interface of the workflow (forms for each step)',
    help: <<<'HELP'
        Starts PHP's built-in web server with the web interface of the workflow: a form for each step (describe the
        API, add its documentation examples, review the notes, generate, check), each one doing the work of the step
        and returning the next form. It works on this project's files, as the other commands do.

          <info>php %command.full_name%</info>
          <info>php %command.full_name% --port=9000</info>

        A development tool: it listens on 127.0.0.1 only, refuses to start in the prod environment, and writes
        only the examples (tests/Fixtures/<api>/) and the descriptors (config/api/<api>/). Stop it with Ctrl-C.
        HELP,
)]
final class ServeCommand
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $descriptorDir,
        private readonly string $namespace,
        private readonly string $sourceDir,
        private readonly string $configDir,
        private readonly string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Port of the server (on 127.0.0.1)')] int $port = 8765,
        #[Option('Print what would be started, and stop')] bool $dryRun = false,
    ): int {
        if ('prod' === $this->environment) {
            $io->error('The web interface is a development tool: it does not start in the prod environment.');

            return Command::FAILURE;
        }
        if ($port < 1024 || $port > 65535) {
            $io->error('The port must be between 1024 and 65535.');

            return Command::FAILURE;
        }
        $autoload = $this->projectDir.'/vendor/autoload.php';
        if (!is_file($autoload)) {
            $io->error('Cannot find vendor/autoload.php in the project.');

            return Command::FAILURE;
        }

        $script = $_SERVER['argv'][0] ?? 'bin/console';
        $config = new WebConfig(
            $this->projectDir,
            Path::makeAbsolute($this->descriptorDir, $this->projectDir),
            $this->namespace,
            $this->sourceDir,
            $this->configDir,
            [\PHP_BINARY, Path::makeAbsolute($script, (string) getcwd())],
            bin2hex(random_bytes(16)),
            ['127.0.0.1:'.$port, 'localhost:'.$port],
        );
        $public = \dirname(__DIR__).'/Web/public';
        $command = [\PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $public, $public.'/index.php'];
        $url = 'http://127.0.0.1:'.$port.'/';

        if ($dryRun) {
            $io->definitionList(['URL' => $url], ['Command' => implode(' ', $command)], ['Project' => $this->projectDir]);

            return Command::SUCCESS;
        }

        $io->success('api-forge web interface: '.$url.' (Ctrl-C to stop)');
        $environment = array_merge(getenv(), [WebConfig::ENV_NAME => json_encode(['autoload' => $autoload] + json_decode($config->toJson(), true), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)]);
        $process = proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes, $this->projectDir, $environment);
        if (!\is_resource($process)) {
            $io->error('Cannot start the PHP server.');

            return Command::FAILURE;
        }
        $code = proc_close($process);

        return 0 === $code || 130 === $code || 143 === $code ? Command::SUCCESS : Command::FAILURE;
    }
}
