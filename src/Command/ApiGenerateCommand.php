<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\InvalidDescriptorException;
use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'api-forge:generate',
    description: 'Generates the Symfony services of an API from its descriptors',
    help: <<<'HELP'
        Validates <comment>config/api/<api>/*.yaml</comment> and generates (default paths, see the api_forge configuration):

          - <comment>src/Api/<Namespace>/Generated/Resource/*.php</comment>, one service per resource
          - <comment>src/Api/<Namespace>/Generated/<Namespace>Api.php</comment>, the entry point
          - <comment>config/packages/api_<api>.yaml</comment>, the scoped HTTP client

          <info>php %command.full_name% lemonsqueezy</info>

        Generated files that no longer match a descriptor are removed. Never edit generated files:
        fix the descriptors and regenerate. Use --check in CI to verify the generated code is up to date.
        HELP,
)]
final class ApiGenerateCommand
{
    public function __construct(
        private readonly DescriptorLoader $loader,
        private readonly ServiceGenerator $generator,
        private readonly string $projectDir,
        private readonly string $descriptorDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API name, e.g. "lemonsqueezy"')] string $api,
        #[Option('Descriptor directory (default: api_forge.descriptor_dir, config/api)')] ?string $dir = null,
        #[Option('Project directory to generate into (default: this project)')] ?string $output = null,
        #[Option('Fail if the descriptors still contain review notes')] bool $strict = false,
        #[Option('Do not write anything, fail if the generated files are not up to date')] bool $check = false,
    ): int {
        $output ??= $this->projectDir;

        try {
            $definition = $this->loader->load($api, $dir);
        } catch (InvalidDescriptorException $e) {
            $io->error(array_merge(['Invalid API descriptors:'], $e->errors));

            return Command::FAILURE;
        }

        if ([] !== $definition->review) {
            $io->warning(array_merge([\sprintf('%d review note(s) left in the descriptors:', \count($definition->review))], $definition->review));
            if ($strict) {
                return Command::FAILURE;
            }
        }

        $descriptorDir = Path::canonicalize(($dir ?? $this->descriptorDir).'/'.$api);
        if (Path::isBasePath($this->projectDir, $descriptorDir)) {
            $descriptorDir = Path::makeRelative($descriptorDir, $this->projectDir);
        }
        $files = $this->generator->generate($definition, $descriptorDir);

        $changes = [];
        foreach ($files as $file => $content) {
            $path = $output.'/'.$file;
            $current = is_file($path) ? file_get_contents($path) : null;
            if ($current === $content) {
                continue;
            }
            $changes[] = [null === $current ? 'created' : 'updated', $file];
            if (!$check) {
                $this->filesystem->dumpFile($path, $content);
            }
        }

        foreach ($this->staleFiles($output.'/'.$this->generator->generatedDirectory($definition), $output, $files) as $file) {
            $changes[] = ['removed', $file];
            if (!$check) {
                $this->filesystem->remove($output.'/'.$file);
            }
        }

        if ([] === $changes) {
            $io->success(\sprintf('The %d generated files are up to date.', \count($files)));

            return Command::SUCCESS;
        }

        $io->table(['Change', 'File'], $changes);
        if ($check) {
            $io->error('The generated files are not up to date: run api-forge:generate.');

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d file(s) changed.', \count($changes)));

        return Command::SUCCESS;
    }

    /**
     * Previously generated files (recognized by their marker) that are not generated anymore.
     *
     * @param array<string, string> $files
     *
     * @return list<string>
     */
    private function staleFiles(string $directory, string $output, array $files): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $stale = [];
        foreach ((new Finder())->files()->in($directory)->name('*.php')->contains(ServiceGenerator::MARKER)->sortByName() as $file) {
            $relative = Path::makeRelative($file->getPathname(), $output);
            if (!isset($files[$relative])) {
                $stale[] = $relative;
            }
        }

        return $stale;
    }
}
