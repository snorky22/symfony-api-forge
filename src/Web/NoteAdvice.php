<?php

namespace Snorky22\ApiForge\Web;

/**
 * What to do about a review note: the usual ones of docs/workflow.md.
 */
final class NoteAdvice
{
    private const ADVICE = [
        'marks values that are null in the example' => 'The example never shows a value for the field, so its type is unknown. Use Fix below: say what it is, or choose one of your type files. (A docs page next to the example would say it too.)',
        'marks empty arrays in the example' => 'The example only has an empty list for the field. Use Fix below: say what is in the list, or that it is an object.',
        'is not in the example: add it' => 'The docs describe a field the example lacks. Add it to the example, or leave it if it is optional or early access, then delete this note.',
        'nothing is applied to' => 'The docs page and the example hold this name a different number of times. Add the missing ones to the example, then import again.',
        'has the right count in the example but no place' => 'The example holds this field under another parent than the docs. Check where it is.',
        'is not in the docs' => 'The example has a field the docs do not describe: check it, or remove it from the example.',
        'in the docs (line' => 'The example and the docs disagree on the type: fix the example.',
        'looks like a sandbox' => 'The API form sets the live host, the sandbox one and the variable that chooses. If that is done, press Accept.',
        'query parameters are assumed optional' => 'Nothing to fix if the docs mark none of these parameters required: press Accept. If one is required, set required: true on it in the descriptor (under Advanced), then Accept.',
        'guessed from the example value' => 'Check the name of the path parameter.',
        'all attributes are typed as optional' => 'Remove the "?" of the required attributes.',
        'is not JSON' => 'The text is not valid JSON: the note says where. JSON strings use double quotes.',
        'request body: every key is typed as required' => 'The example body cannot tell which keys are optional. Add the docs page of the body (as <example>.body.docs.md, or in the form), or mark them with "?" in the descriptor (under Advanced), then Accept.',
        'doctor probe' => 'The importer chose a harmless GET to check the API with. If it is fine, press Accept.',
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
