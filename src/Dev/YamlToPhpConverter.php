<?php

namespace Snorky22\ApiForge\Dev;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts YAML to a PHP array literal with the same data. The result is checked before it is returned: the
 * code, evaluated, must give back exactly what the YAML parses to.
 */
final class YamlToPhpConverter
{
    public function __construct(
        private readonly PhpArrayDumper $dumper = new PhpArrayDumper(),
    ) {
    }

    /**
     * @return array{code: string, comments: list<string>} the literal (without ";"), and the full-line comments
     *                                                      of the YAML, which PHP data can't carry
     *
     * @throws ParseException       the YAML is invalid (including duplicate keys and tags)
     * @throws \RuntimeException    the data can't be written as a literal that reads back identical
     */
    public function convert(string $yaml): array
    {
        // Empty (or only comments): nothing, as the descriptor loader reads it
        $data = Yaml::parse($yaml) ?? [];

        try {
            $code = $this->dumper->dump($data);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if (!$this->dumper->check($code, $data)) {
            throw new \RuntimeException('The PHP code does not read back as the same data: not written. Please report this YAML.');
        }

        return ['code' => $code, 'comments' => $this->comments($yaml, $data)];
    }

    /**
     * The lines starting with "#", except those that are data (a "#" line of a block scalar).
     *
     * @return list<string>
     */
    private function comments(string $yaml, mixed $data): array
    {
        $strings = [];
        array_walk_recursive($data, static function ($value) use (&$strings): void {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        });

        $comments = [];
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') && [] === array_filter($strings, static fn (string $string) => str_contains($string, $line))) {
                $comments[] = $line;
            }
        }

        return $comments;
    }
}
