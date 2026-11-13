<?php

namespace Snorky22\ApiForge\Web;

/**
 * The type files of the examples folder, for the templates: the content of one, to show it in a code box.
 */
final class TypeFiles
{
    public function __construct(
        private readonly FixtureDirectory $fixtures,
    ) {
    }

    /**
     * The content of <name>.array (or <name>-type.json), null when the file does not exist.
     */
    public function content(string $name, string $format): ?string
    {
        $file = 'json' === $format ? $name.'-type.json' : $name.'.array';

        return $this->fixtures->has($file) ? rtrim($this->fixtures->read($file)) : null;
    }
}
