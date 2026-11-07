<?php

namespace Snorky22\ApiForge\Dev;

use Symfony\Component\VarExporter\VarExporter;

/**
 * Writes plain data (scalars, nested arrays) as a readable PHP array literal: short syntax, lists without keys,
 * multi-line strings as nowdocs (a PHPDoc shape stays readable). Escaping of the other strings and the floats
 * is left to VarExporter.
 *
 * dump() never emits code that does not read back as the same value: that is what check() verifies.
 */
final class PhpArrayDumper
{
    private const INDENT = '    ';

    /**
     * @throws \InvalidArgumentException for an object, a resource or a closure
     */
    public function dump(mixed $data): string
    {
        return $this->value($data, 0, null);
    }

    /**
     * Whether the code, evaluated, gives back exactly the data (same keys, order and types). The code is run
     * only when its tokens are those of a literal (PhpLiteral).
     */
    public function check(string $code, mixed $data): bool
    {
        try {
            $value = PhpLiteral::evaluate($code);
        } catch (\InvalidArgumentException) {
            return false;
        }

        // NAN is never equal to itself: compare what it exports to
        return $value === $data || (\is_float($data) && is_nan($data) && \is_float($value) && is_nan($value))
            || (\is_array($data) && \is_array($value) && VarExporter::export($value) === VarExporter::export($data));
    }

    private function value(mixed $value, int $depth, ?string $key): string
    {
        if (\is_array($value)) {
            return $this->array($value, $depth);
        }
        if (\is_string($value) && null !== $nowdoc = $this->nowdoc($value, $depth, $key)) {
            return $nowdoc;
        }
        if (null === $value || \is_scalar($value)) {
            return VarExporter::export($value);
        }

        throw new \InvalidArgumentException(\sprintf('Cannot write a value of type %s as data.', get_debug_type($value)));
    }

    /**
     * @param array<mixed> $array
     */
    private function array(array $array, int $depth): string
    {
        if ([] === $array) {
            return '[]';
        }

        $list = array_is_list($array);
        $indent = str_repeat(self::INDENT, $depth + 1);
        $code = "[\n";
        foreach ($array as $key => $value) {
            $code .= $indent.($list ? '' : VarExporter::export($key).' => ').$this->value($value, $depth + 1, \is_string($key) ? $key : null).",\n";
        }

        return $code.str_repeat(self::INDENT, $depth).']';
    }

    /**
     * A multi-line string as a nowdoc, null when it can't be one without changing it (tabs, carriage returns,
     * control characters, invalid UTF-8).
     */
    private function nowdoc(string $text, int $depth, ?string $key): ?string
    {
        if (!str_contains($text, "\n") || !mb_check_encoding($text, 'UTF-8') || 1 === preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $text)) {
            return null;
        }

        $lines = explode("\n", $text);
        $marker = null !== $key && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) ? strtoupper($key) : 'TEXT';
        // A line starting with the marker would end the nowdoc early
        while ([] !== array_filter($lines, static fn (string $line) => str_starts_with(ltrim($line, ' '), $marker))) {
            $marker .= '_';
        }

        $indent = str_repeat(self::INDENT, $depth + 1);

        return "<<<'".$marker."'\n"
            .implode("\n", array_map(static fn (string $line) => '' === $line ? '' : $indent.$line, $lines))
            ."\n".$indent.$marker;
    }
}
