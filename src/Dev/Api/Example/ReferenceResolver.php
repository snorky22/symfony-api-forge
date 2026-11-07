<?php

namespace Snorky22\ApiForge\Dev\Api\Example;

/**
 * Replaces, in an example, the values that name another data file by that file's content: the way API
 * documentation points to a type described elsewhere ("the Duration object"). A reference is a string that is
 * a relative file name with a data extension (json, or the extension of an ExampleFormat such as php),
 * resolved from the directory of the file that holds it. References nest, cycles are refused.
 *
 *     "trial_period": "duration-type.json",
 *     "unit_price_overrides": ["unit-price-override-type.php"]
 *
 * A string that must stay a file name is written with a leading backslash ("\export.json").
 */
final class ReferenceResolver
{
    /**
     * @param list<string> $stack the files being resolved, to detect cycles
     */
    public function __construct(
        private readonly ExampleReader $reader,
        private readonly string $directory,
        private readonly array $stack = [],
    ) {
    }

    /**
     * @throws \InvalidArgumentException a reference to a missing or unreadable file, or a cycle
     */
    public function resolve(mixed $data): mixed
    {
        if (\is_string($data)) {
            return $this->resolveString($data);
        }
        if (\is_array($data)) {
            return array_map($this->resolve(...), $data);
        }
        if ($data instanceof \stdClass) {
            return (object) array_map($this->resolve(...), (array) $data);
        }

        return $data;
    }

    /**
     * Whether a JSON text may hold a reference: a quoted string ending with a data extension (cheap check, to
     * leave the other documents untouched).
     */
    public function mayHold(string $json): bool
    {
        return 1 === preg_match('/\.(?:'.implode('|', array_map('preg_quote', $this->reader->dataExtensions())).')\s*"/i', $json);
    }

    private function resolveString(string $value): string|array|\stdClass
    {
        if (str_starts_with($value, '\\')) {
            return substr($value, 1);
        }
        if (!$this->isReference($value)) {
            return $value;
        }

        $file = $this->directory.'/'.$value;
        if (!is_file($file)) {
            throw new \InvalidArgumentException(\sprintf('"%s" names a data file that does not exist (looked for %s). To keep it as a string, write "\\%s".', $value, $file, $value));
        }
        $real = realpath($file) ?: $file;
        if (\in_array($real, $this->stack, true)) {
            throw new \InvalidArgumentException(\sprintf('Cycle between data files: %s.', implode(' → ', array_map('basename', [...$this->stack, $real]))));
        }

        return $this->reader->document($file, [...$this->stack, $real]);
    }

    private function isReference(string $value): bool
    {
        return 1 === preg_match('/^(?![a-z][a-z0-9+.-]*:)(?!\/)[^\s\\\\:*?"<>|]+\.(?:'.implode('|', array_map('preg_quote', $this->reader->dataExtensions())).')$/i', $value);
    }
}
