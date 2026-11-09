<?php

namespace Snorky22\ApiForge\Web;

/**
 * A folder of files the web interface may read and write: the documentation examples of an API
 * (tests/Fixtures/<api>) or its descriptors (config/api/<api>). Names come from the server or from the files
 * already there, never from a path.
 */
final class FixtureDirectory
{
    public const EXAMPLES = ['curl', 'json', 'php', 'array', 'md'];

    /**
     * @param list<string> $extensions the extensions of the files of this folder
     */
    public function __construct(
        public readonly string $directory,
        private readonly array $extensions = self::EXAMPLES,
    ) {
    }

    /**
     * @return list<string> the file names
     */
    public function names(): array
    {
        $names = [];
        foreach (is_dir($this->directory) ? (scandir($this->directory) ?: []) : [] as $name) {
            if ($this->isValid($name) && is_file($this->directory.'/'.$name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function has(string $name): bool
    {
        return $this->isValid($name) && is_file($this->directory.'/'.$name);
    }

    public function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    public function read(string $name): string
    {
        return $this->has($name) ? (string) file_get_contents($this->path($name)) : '';
    }

    /**
     * @throws \InvalidArgumentException the name is not one of an example, or the file exists and would change
     */
    public function write(string $name, string $content, bool $overwrite = true): string
    {
        if (!$this->isValid($name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid name for an example (letters, digits, "-", "_", "."; extensions: %s).', $name, implode(', ', $this->extensions)));
        }
        if (!$overwrite && $this->has($name) && $this->read($name) !== $content) {
            throw new \InvalidArgumentException(\sprintf('%s exists and differs: tick "overwrite" to replace it.', $name));
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \InvalidArgumentException('Cannot create the examples directory.');
        }
        if (false === file_put_contents($this->path($name), $content)) {
            throw new \InvalidArgumentException(\sprintf('Cannot write %s.', $name));
        }

        return $this->path($name);
    }

    public function isValid(string $name): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $name)
            && !str_contains($name, '..')
            && \in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), $this->extensions, true);
    }
}
