<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\YamlToPhpConverter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;

#[AsCommand(
    name: 'api-forge:yaml-to-php',
    description: 'Converts a YAML file (e.g. a descriptor) to a PHP array literal',
    help: <<<'HELP'
        Reads YAML from a file, or from stdin when no file is given, and prints the same data as a PHP array:
        short syntax, lists without keys, multi-line strings (a PHPDoc shape) as nowdocs.

          <info>php %command.full_name% config/api/paddle/prices.yaml</info>
          <info>php %command.full_name% config/api/paddle/prices.yaml --output=prices.php</info>

        The code is checked before it is printed: evaluated, it must give back exactly the data of the YAML
        (keys, order, types). Otherwise nothing is written and the command fails.

        Comments of the YAML can't be carried over: the lines concerned are listed on stderr.
        <comment>--output</comment> writes a file that returns the array (<comment><?php return [...];</comment>) and refuses to
        overwrite an existing file without <comment>--force</comment>.
        HELP,
)]
final class YamlToPhpCommand
{
    public function __construct(
        private readonly YamlToPhpConverter $converter,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $out,
        SymfonyStyle $io,
        #[Argument('YAML file to convert (reads stdin if omitted)')] ?string $file = null,
        #[Option('Write a PHP file returning the array, instead of printing it', shortcut: 'o')] ?string $output = null,
        #[Option('Overwrite the output file if it exists')] bool $force = false,
    ): int {
        $yaml = $this->readYaml($input, $file);
        if (null === $yaml) {
            $io->error(null === $file ? 'No YAML given on stdin.' : \sprintf('Cannot read file "%s".', $file));

            return Command::FAILURE;
        }
        if (null !== $output && is_file($output) && !$force) {
            $io->error(\sprintf('"%s" exists: use --force to overwrite it.', $output));

            return Command::FAILURE;
        }

        try {
            $result = $this->converter->convert($yaml);
        } catch (ParseException $e) {
            $io->error('Invalid YAML: '.$e->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $result['comments']) {
            $io->getErrorStyle()->warning(array_merge(
                [\sprintf('%d comment line(s) are not carried over to PHP data:', \count($result['comments']))],
                array_map(static fn (string $comment) => '  '.$comment, \array_slice($result['comments'], 0, 10)),
                \count($result['comments']) > 10 ? ['  …'] : [],
            ));
        }

        if (null === $output) {
            $out->writeln($result['code'].';', OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if (false === @file_put_contents($output, "<?php\n\nreturn ".$result['code'].";\n")) {
            $io->error(\sprintf('Cannot write "%s".', $output));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Written %s.', $output));

        return Command::SUCCESS;
    }

    private function readYaml(InputInterface $input, ?string $file): ?string
    {
        if (null !== $file) {
            $yaml = is_file($file) ? file_get_contents($file) : false;

            return false === $yaml ? null : $yaml;
        }

        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
        if (\STDIN === $stream && stream_isatty(\STDIN)) {
            return null;
        }
        $yaml = stream_get_contents($stream);

        return false === $yaml || '' === trim($yaml) ? null : $yaml;
    }
}
