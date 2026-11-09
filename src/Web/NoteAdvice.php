<?php

namespace Snorky22\ApiForge\Web;

/**
 * What to do about a review note: the usual ones of docs/workflow.md.
 */
final class NoteAdvice
{
    private const ADVICE = [
        'marks values that are null in the example' => 'Complete the example: give the field a real value, or point to a type file. If you have the docs page, add it as text (the page says the type).',
        'marks empty arrays in the example' => 'Write one entry in the example to type the list, or {} if it is an object.',
        'is not in the example: add it' => 'The docs describe a field the example lacks. Add it to the example, or leave it if it is optional or early access, then delete this note.',
        'nothing is applied to' => 'The docs page and the example hold this name a different number of times. Add the missing ones to the example, then import again.',
        'has the right count in the example but no place' => 'The example holds this field under another parent than the docs. Check where it is.',
        'is not in the docs' => 'The example has a field the docs do not describe: check it, or remove it from the example.',
        'in the docs (line' => 'The example and the docs disagree on the type: fix the example.',
        'looks like a sandbox' => 'Put the live host as base_uri, the sandbox one under environments, and base_uri_env to choose at runtime (the API form does it).',
        'query parameters are assumed optional' => 'Set required: true on the parameters the docs require.',
        'guessed from the example value' => 'Check the name of the path parameter.',
        'all attributes are typed as optional' => 'Remove the "?" of the required attributes.',
        'is not JSON' => 'The text is not valid JSON: the note says where. JSON strings use double quotes.',
        'doctor probe' => 'Check that the probe is harmless and cheap.',
    ];

    public static function for(string $note): ?string
    {
        foreach (self::ADVICE as $needle => $advice) {
            if (str_contains($note, $needle)) {
                return $advice;
            }
        }

        return null;
    }
}
