<?php

namespace Snorky22\ApiForge\Web;

/**
 * Presents a review note for a human: where it applies, what kind of note it is, the field it is about, and its
 * sentences with the names and the files highlighted. It never changes what a note says.
 */
final class NoteFormatter
{
    /** Kind of note: a needle of its text => [label, Bootstrap colour, what it asks]. The first match wins. */
    private const KINDS = [
        'shows no fields in the example' => ['Example value missing', 'warning', 'give the object an example value, or accept'],
        'marks values that are null' => ['Type unknown', 'warning', 'say what the field is (Fix)'],
        'marks empty arrays' => ['Type unknown', 'warning', 'say what the list holds (Fix)'],
        'nothing is applied to' => ['Counted differently', 'secondary', 'complete the example, or accept'],
        'has the right count in the example but no place' => ['Other parent', 'secondary', 'check where the example holds it'],
        'is a free-form object in the docs' => ['Free-form object', 'info', 'make it free-form (Fix)'],
        'is not in the docs' => ['Not in the docs', 'info', 'complete the docs page, or accept'],
        'is not in the example' => ['Not in the example', 'info', 'add it to the example, or accept'],
        'in the docs (line' => ['Type differs', 'danger', 'fix the example'],
        'is not JSON' => ['Not JSON', 'danger', 'fix the file'],
        'differs from the' => ['Example differs', 'danger', 'import again with Replace, or merge'],
        'differ from the' => ['Example differs', 'danger', 'import again with Replace, or accept'],
    ];
    private const GUESS = ['Guess to check', 'primary', 'check it, then accept'];

    /**
     * Where the note applies: the descriptor and the part of it (operation, event, object), and the note without that prefix.
     *
     * @return array{group: string, file: string, part: ?string, text: string}
     */
    public static function locate(string $note, string $projectDir): array
    {
        $text = str_replace($projectDir.'/', '', $note);
        $file = '';
        if (1 === preg_match('#^(?:\S*/)?([A-Za-z0-9_.-]+\.yaml): #', $text, $m)) {
            $file = $m[1];
            $text = substr($text, \strlen($m[0]));
        }
        $part = null;
        if (1 === preg_match('/^(operation|event) "([^"]+)": /', $text, $m)) {
            $part = $m[1].' '.$m[2];
            $text = substr($text, \strlen($m[0]));
        } elseif (1 === preg_match('/^(object|doctor): /', $text, $m)) {
            $part = $m[1];
            $text = substr($text, \strlen($m[0]));
        }

        return ['group' => $file.(null !== $part ? ' › '.$part : ''), 'file' => $file, 'part' => $part, 'text' => $text];
    }

    /**
     * @return array{label: string, colour: string, asks: string}
     */
    public static function kind(string $note): array
    {
        foreach (self::KINDS as $needle => [$label, $colour, $asks]) {
            if (str_contains($note, $needle)) {
                return ['label' => $label, 'colour' => $colour, 'asks' => $asks];
            }
        }

        return ['label' => self::GUESS[0], 'colour' => self::GUESS[1], 'asks' => self::GUESS[2]];
    }

    /**
     * The field or name the note is about: its first quoted name, else its first dotted path.
     */
    public static function subject(string $text): ?string
    {
        if (1 === preg_match('/"([^"]+)"/', $text, $m)) {
            return $m[1];
        }

        return 1 === preg_match('/\b([a-z_]+(?:\[\])?(?:\.[a-z_]+(?:\[\])?)+)\b/', $text, $m) ? $m[1] : null;
    }

    /**
     * HTML of a sentence: escaped, with quoted names, paths and file names as code, and line numbers dimmed.
     */
    public static function highlight(string $text): string
    {
        $html = View::e($text);
        // A JSON pair to copy, "key": "value", stays one piece with its quotes; then "name" (quotes are escaped by now)
        $pairs = [];
        $html = (string) preg_replace_callback('/&quot;([^&]{1,80}?)&quot;: (&quot;[^&]{0,80}?&quot;|[\w.-]+)/', static function (array $m) use (&$pairs): string {
            $pairs[] = '<code>&quot;'.$m[1].'&quot;: '.$m[2].'</code>';

            return "\0".(\count($pairs) - 1)."\0";
        }, $html);
        $html = (string) preg_replace('/&quot;([^&]{1,80}?)&quot;/', '<code>$1</code>', $html);
        $html = (string) preg_replace_callback('/\0(\d+)\0/', static fn (array $m): string => $pairs[(int) $m[1]], $html);
        // file names and dotted paths in parentheses: (data.items[].price.trial_period)
        $html = (string) preg_replace('/(?<![\w>.-])([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.(?:json|docs\.md|md|yaml|curl|php|array))\b/', '<code>$1</code>', $html);
        $html = (string) preg_replace_callback('/\(([^()]*)\)/', static fn (array $m): string => '('.preg_replace('/(?<![\w>.-])([a-z_][a-z0-9_]*(?:\[\])?(?:\.[a-z_][a-z0-9_]*(?:\[\])?)+)(?![\w<])/', '<code>$1</code>', $m[1]).')', $html);

        return (string) preg_replace('/\b(lines? \d+(?:, \d+)*)\b/', '<span class="text-secondary">$1</span>', $html);
    }
}
