<?php

namespace Snorky22\ApiForge\Dev\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints doctor checks in a console: ✔ ok, ! warning, ✘ error.
 */
final class CheckRenderer
{
    /**
     * @param list<Check> $checks
     *
     * @return int the number of errors
     */
    public static function render(SymfonyStyle $io, array $checks): int
    {
        $errors = 0;
        foreach ($checks as $check) {
            $io->text(match ($check->level) {
                Check::OK => '<info>✔</info> ',
                Check::WARNING => '<comment>!</comment> ',
                default => '<error>✘</error> ',
            }.$check->message);
            $errors += Check::ERROR === $check->level ? 1 : 0;
        }

        return $errors;
    }
}
