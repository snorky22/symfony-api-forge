<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\VarExporter\VarExporter;

#[AsCommand(
    name: 'api-forge:json-to-php',
    description: 'Converts a JSON example (e.g. from API docs) to a PHP array literal and/or a PHPDoc array shape',
    help: <<<'HELP'
        Reads JSON from a file, or from stdin when no file is given:

          <info>php %command.full_name% tests/Fixtures/json-example.json</info>
          <info>pbpaste | php %command.full_name% --shape</info>

        Documentation-style JSON is accepted: comments, <comment>{...}</comment> / <comment>[...]</comment> placeholders and trailing commas are removed.
        Values that are null in the example are typed <comment>mixed</comment>, since their real type is unknown.
        HELP,
)]
final class JsonToPhpCommand
{
    public function __construct(
        private readonly LenientJsonDecoder $decoder,
        private readonly ArrayShapeGenerator $shapeGenerator,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        #[Argument('JSON file to convert (reads stdin if omitted)')] ?string $file = null,
        #[Option('Also print the PHPDoc array shape', shortcut: 's')] bool $shape = false,
        #[Option('Print only the PHPDoc array shape')] bool $shapeOnly = false,
        #[Option('PHPDoc tag for the shape, e.g. "return" or "phpstan-type Order"')] string $tag = 'var',
    ): int {
        $json = $this->readJson($input, $file);
        if (null === $json) {
            $io->error(null === $file ? 'No JSON given on stdin.' : \sprintf('Cannot read file "%s".', $file));

            return Command::FAILURE;
        }

        try {
            $objects = $this->decoder->decode($json, false);
            $array = $this->decoder->decode($json);
        } catch (\JsonException $e) {
            $io->error('Invalid JSON: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($shape || $shapeOnly) {
            $output->writeln($this->shapeGenerator->docBlock($objects, $tag), OutputInterface::OUTPUT_RAW);
        }
        if (!$shapeOnly) {
            $output->writeln(VarExporter::export($array).';', OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    private function readJson(InputInterface $input, ?string $file): ?string
    {
        if (null !== $file) {
            $json = is_file($file) ? file_get_contents($file) : false;

            return false === $json ? null : $json;
        }

        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
        if (\STDIN === $stream && stream_isatty(\STDIN)) {
            return null;
        }
        $json = stream_get_contents($stream);

        return false === $json || '' === trim($json) ? null : $json;
    }
}
