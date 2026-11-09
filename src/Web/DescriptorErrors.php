<?php

namespace Snorky22\ApiForge\Web;

/**
 * The errors of the descriptor loader are those of the configuration component: exact, but written for the schema.
 * This says them the way the descriptor is written.
 */
final class DescriptorErrors
{
    public static function explain(string $error, string $projectDir): string
    {
        $error = str_replace($projectDir.'/', '', $error);

        // The value "array" is not allowed for path "resource.operations.list.query.include.type". Permissible values: "string", "int"
        if (1 === preg_match('/^(.*?): The value "([^"]*)" is not allowed for path "resource\.operations\.([^.]+)\.query\.([^.]+)\.type"\. Permissible values: (.*?)\.?$/s', $error, $m)) {
            return \sprintf('%s: the query parameter "%s" of the operation "%s" has the type "%s", which does not exist; use one of %s. A list of values is a string with the values separated by commas (include=product,price).', $m[1], $m[4], $m[3], $m[2], str_replace('"', '', $m[5]));
        }

        // A shape is a PHPDoc type: the references to files of the examples ("import_meta.array") are not types
        if (str_contains($error, 'is not a valid PHPDoc type')) {
            return $error.' A shape holds PHPDoc types (array{key: type}, list<type>, ?string, string|null). A file name such as "import_meta.array" belongs in an example (the JSON): import the example again instead of editing the shape.';
        }

        // Any other: the path of the schema as a path in the file
        return (string) preg_replace_callback('/path "([^"]+)"/', static fn (array $m) => 'path '.str_replace('.', ' › ', $m[1]), $error);
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    public static function explainAll(array $errors, string $projectDir): array
    {
        return array_map(static fn (string $error) => self::explain($error, $projectDir), $errors);
    }
}
