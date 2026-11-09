<?php

namespace Snorky22\ApiForge\Dev\Api\Generator;

use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\InvalidDescriptorException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Generates the services of an API from its descriptors: the work of api-forge:generate, for the command and for
 * the web interface.
 */
final class GenerateRunner
{
    public function __construct(
        private readonly DescriptorLoader $loader,
        private readonly ServiceGenerator $generator,
        private readonly string $projectDir,
        private readonly string $descriptorDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param ?string $dir    descriptor directory (default: the configured one)
     * @param ?string $output project directory to generate into (default: this project)
     * @param bool    $check  write nothing, only tell what would change
     */
    public function run(string $api, ?string $dir = null, ?string $output = null, bool $check = false): GenerateResult
    {
        $output ??= $this->projectDir;

        try {
            $definition = $this->loader->load($api, $dir);
        } catch (InvalidDescriptorException $e) {
            return new GenerateResult($e->errors);
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

        return new GenerateResult([], $definition->review, $changes, \count($files));
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
