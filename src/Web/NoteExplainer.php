<?php

namespace Snorky22\ApiForge\Web;

/**
 * Says a review note as a sentence: what it means for the example, which file to change and how, or when to accept it.
 */
final class NoteExplainer
{
    /**
     * @param array<string, string> $files the files of the operation the note is about: body, bodyDocs, response, responseDocs
     */
    public static function explain(string $note, array $files): ?string
    {
        $example = static fn (bool $body): string => $files[$body ? 'body' : 'response'] ?? ($body ? 'the body example' : 'the response example');
        $docs = static fn (bool $body): string => $files[$body ? 'bodyDocs' : 'responseDocs'] ?? ($body ? 'the docs page of the body' : 'the docs page of the response');
        $what = static fn (bool $body): string => $body ? 'body' : 'response';

        // docs: "type" (line 17) is not in the example: add it to the example (in the docs it sits between "a" and "b"; values in the docs: x, y)
        if (1 === preg_match('/(body docs|docs): "([^"]+)" \(line (\d+)\) is not in the example: add it to the example \(in the docs it sits ([^;)]*)(?:; values in the docs: ([^)]*))?\)/', $note, $m)) {
            $body = 'body docs' === $m[1];
            $suggest = '' !== ($m[5] ?? '') ? ', for example "'.$m[2].'": "'.trim(explode(',', $m[5])[0]).'" (the docs allow '.$m[5].')' : '';

            return \sprintf(
                'The docs page of the %s describes "%s" (line %d), and the example does not have it, so the generated method would not know it. In the docs it sits %s. Add it to %s%s; or accept this note if you do not need the field.',
                $what($body), $m[2], $m[3], $m[4], $example($body), $suggest,
            );
        }

        // example: "image_url" (data.image_url) is not in the docs: complete the docs page text, or remove it from the example
        if (1 === preg_match('/(body example|example): "([^"]+)" \(([^)]*)\) is not in the docs:/', $note, $m)) {
            $body = 'body example' === $m[1];

            return \sprintf(
                'The %s example has "%s" (%s), and the text of the docs page does not describe it. Either the pasted text is incomplete: open %s and paste the missing part of the page. Or the field does not exist in the API: remove it from %s. Accept the note if the field is real and you do not need its docs.',
                $what($body), $m[2], $m[3], $docs($body), $example($body),
            );
        }

        // example: "custom_data" (…) is a free-form object in the docs (no field is described), but the example gives it fields (a, b): replace its value with {}
        if (1 === preg_match('/(body example|example): "([^"]+)" \(([^)]*)\) is a free-form object in the docs[^(]*\(no field is described\), but the example gives it fields \(([^)]*)\)/', $note, $m)) {
            $body = 'body example' === $m[1];

            return \sprintf(
                'The docs say "%s" is a free-form object: they describe none of its fields. The example fills it with %s, which would be typed as fields the API requires. Replace its value with {} in %s (the Fix button does it): the generated method then accepts any object.',
                $m[2], $m[4], $example($body),
            );
        }

        // docs: "import_meta" (data.import_meta) shows no fields in the example (null or empty), but the docs describe a, b: give it an example value
        if (1 === preg_match('/docs: "([^"]+)" \(([^)]*)\) shows no fields in the example \(null or empty\), but the docs describe ([^:]*): give it/', $note, $m)) {
            $body = str_contains($note, 'body docs') || str_contains($note, 'request body') || str_contains($note, 'body example');

            return \sprintf(
                'The docs describe fields inside "%s" (%s), but the example has null or an empty value there, so their types are unknown. Give "%s" an example value in %s: the Fix button offers your type files (such as one for %s). Accept the note if the field is only present sometimes and you do not need its type.',
                $m[1], $m[3], $m[1], $example($body), $m[1],
            );
        }

        // docs: data[].id is string in the docs (line 5), int in the example
        if (1 === preg_match('/docs: ([^ ]+) is (\w+) in the docs \(line (\d+)\), (.+) in the example/', $note, $m)) {
            return \sprintf('The docs say %s is of type %s (line %d); the example has %s there. Fix the example: it must show a value of the documented type.', $m[1], $m[2], $m[3], $m[4]);
        }

        return null;
    }
}
