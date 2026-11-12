<?php

namespace Snorky22\ApiForge\Web;

/**
 * Says a review note for a human, whatever the API and its docs: a title (the issue in a line), what api-forge found,
 * what it means for the generated code, and what the user can do. Plain text: the page highlights the names and files.
 */
final class NoteGuide
{
    /**
     * @param string                $text  the note, without its descriptor prefix (NoteFormatter::locate())
     * @param array<string, string> $files the example files of the note: body, bodyDocs, response, responseDocs
     *
     * @return array{title: string, found: list<string>, impact: ?string, options: list<string>}
     */
    public static function describe(string $text, array $files = []): array
    {
        $body = 1 === preg_match('/^(body docs|body example|request body)/', $text);
        $example = $files[$body ? 'body' : 'response'] ?? ($body ? 'the body example' : 'the example');
        $docs = $files[$body ? 'bodyDocs' : 'responseDocs'] ?? ($body ? 'the docs page of the body' : 'the docs page');
        $m = [];
        $match = static function (string $pattern) use ($text, &$m): bool {
            return 1 === preg_match($pattern, $text, $m);
        };
        $list = static fn (string $names): string => implode(', ', array_map('trim', explode(',', $names)));

        return match (true) {
            $match('/"([^"]+)" \(([^)]*)\) shows no fields in the example \(null or empty\), but the docs describe ([^:]*): give it/') => self::note(
                \sprintf('"%s" is empty in the example, so its fields have no type', $m[1]),
                [\sprintf('The docs describe fields inside "%s" (%s): %s.', $m[1], $m[2], $list($m[3])), 'The example has null, or an empty value, there.'],
                \sprintf('The generated type says "%s" is any array, or null. Your code gets no autocompletion and no check for %s.', $m[1], $list($m[3])),
                [\sprintf('Fix below: say what "%s" is (one of your type files, or a free-form object).', $m[1]), \sprintf('Or write a real value for "%s" in %s, then import again.', $m[1], $example), \sprintf('Or accept: fine if your code never reads "%s".', $m[1])],
            ),
            $match('/"mixed" marks values that are null in the example, their real type is unknown(?: \(([^)]*)\))?/') => self::note(
                \sprintf('%s null in the example: type unknown', self::count($m[1] ?? '', 'field is', 'fields are')),
                ['' !== ($m[1] ?? '') ? \sprintf('These fields are null in the example: %s.', $m[1]) : 'Some fields are null in the example.', 'An example cannot tell what a null field holds when it is set.'],
                'They are typed "mixed": the generated code accepts anything there, and your IDE cannot help.',
                ['Fix below: say what each one is.', \sprintf('Or paste the docs page next to the example (%s): it gives the types.', $docs), 'Or accept: fine for fields your code never reads.'],
            ),
            $match('/"list<mixed>" marks empty arrays in the example, they may be empty objects(?: \(([^)]*)\))?/') => self::note(
                \sprintf('%s empty in the example: content unknown', self::count($m[1] ?? '', 'list is', 'lists are')),
                ['' !== ($m[1] ?? '') ? \sprintf('These keys hold an empty list in the example: %s.', $m[1]) : 'Some keys hold an empty list in the example.', 'An empty list cannot tell what it holds; in PHP, it may also be an empty object.'],
                'They are typed "list<mixed>": no help for their items.',
                ['Fix below: say what the list holds, or that it is an object.', 'Or accept: fine if your code never reads them.'],
            ),
            $match('/docs: "([^"]+)" is described (\d+) time\(s\) \(line ([^)]*)\) and found (\d+) time\(s\) in the example: line ([\d, ]+) under an object the example shows empty/') => self::note(
                \sprintf('"%s" appears %d times in the docs, %d in the example, because of an empty object', $m[1], (int) $m[2], (int) $m[4]),
                [\sprintf('The docs describe "%s" %d times (lines %s).', $m[1], (int) $m[2], $m[3]), \sprintf('The example holds it %d time(s).', (int) $m[4]), \sprintf('Line %s is inside an object the example leaves empty (it has its own note, "… is empty in the example").', $m[5])],
                \sprintf('A copied page has no indentation: api-forge cannot tell which "%s" is which, so it applies none of the docs to it (optional, null, values). Its type still comes from the example.', $m[1]),
                ['Give the empty object an example value (its own note): the counts then match, and the docs apply.', 'Or accept: the type of the example is kept.'],
            ),
            $match('/docs: "([^"]+)" is described (\d+) time\(s\) \(line ([^)]*)\) and found (\d+) time\(s\) in the example/') => self::note(
                \sprintf('"%s" appears %d times in the docs, %d in the example', $m[1], (int) $m[2], (int) $m[4]),
                [\sprintf('The docs describe "%s" %d times (lines %s).', $m[1], (int) $m[2], $m[3]), \sprintf('The example holds it %d time(s).', (int) $m[4])],
                \sprintf('api-forge cannot pair them, so it applies none of the docs to "%s" (optional, null, values). Its type still comes from the example.', $m[1]),
                [(int) $m[2] > (int) $m[4] ? \sprintf('Add the missing "%s" to %s (the docs lines say where), then import again.', $m[1], $example) : \sprintf('Paste the missing part of the docs page into %s (it describes fewer "%s" than the example has).', $docs, $m[1]), 'Or accept: the type of the example is kept.'],
            ),
            $match('/docs: "([^"]+)" \(line (\d+)\) has the right count in the example but no place in it/') => self::note(
                \sprintf('"%s" sits under another parent in the example than in the docs', $m[1]),
                [\sprintf('The docs describe "%s" at line %s, under a parent the example does not have there.', $m[1], $m[2])],
                'Its docs (optional, null, values) are not applied.',
                [\sprintf('Check where %s holds "%s", and move it if the example is wrong.', $example, $m[1]), 'Or accept.'],
            ),
            $match('/example: "([^"]+)" \(([^)]*)\) is a free-form object in the docs[^(]*\(no field is described\), but the example gives it fields \(([^)]*)\)/') => self::note(
                \sprintf('"%s" is free-form in the docs, but the example gives it fields', $m[1]),
                [\sprintf('The docs describe none of the fields of "%s" (%s): any object is allowed.', $m[1], $m[2]), \sprintf('The example fills it with %s.', $m[3])],
                \sprintf('The generated type would require %s: real data with other keys would not match it.', $m[3]),
                ['Fix below: "Make it free-form" writes {} in the example and imports it again.', \sprintf('Or accept, if %s are always there.', $m[3])],
            ),
            $match('/example: "([^"]+)" \(([^)]*)\) is not in the docs/') => self::note(
                \sprintf('"%s" is in the example, but not in the docs page', $m[1]),
                [\sprintf('The example has "%s" (%s).', $m[1], $m[2]), 'The copied docs page does not describe it.'],
                'It is typed from the example only: nothing says whether it is optional, null, or one of fixed values.',
                [\sprintf('If the copied text is incomplete: open %s and paste the missing part.', $docs), \sprintf('If "%s" does not exist in the API: remove it from %s.', $m[1], $example), 'Or accept: the type of the example is kept.'],
            ),
            $match('/docs: "([^"]+)" \(line ([^)]*)\) is not in the example: add it to the example \(in the docs it sits ([^;)]*)(?:; values in the docs: ([^)]*))?\)/') => self::note(
                \sprintf('"%s" is in the docs, but missing from the example', $m[1]),
                array_values(array_filter([\sprintf('The docs describe "%s" (line %s); it sits %s.', $m[1], $m[2], $m[3]), '' !== ($m[4] ?? '') ? \sprintf('Its values: %s.', $m[4]) : null])),
                \sprintf('The generated type does not know "%s": your code cannot read or send it with type help.', $m[1]),
                [\sprintf('Add "%s" to %s%s, then import again.', $m[1], $example, '' !== ($m[4] ?? '') ? \sprintf(', for example "%s": "%s"', $m[1], trim(explode(',', $m[4])[0])) : ''), 'Or accept: fine if you do not need it (optional, early access, rarely sent).'],
            ),
            $match('/docs: ([^ ]+) is (\S+) in the docs \(line (\d+)\), (.+) in the example/') => self::note(
                \sprintf('%s is %s in the docs, %s in the example', $m[1], $m[2], $m[4]),
                [\sprintf('The docs say %s is %s (line %s).', $m[1], $m[2], $m[3]), \sprintf('The example has %s there.', $m[4])],
                'The generated type follows the example, and may be wrong.',
                [\sprintf('Fix the value in %s, so that it has the documented type, then import again.', $example)],
            ),
            $match('/the objects of (.+?) differ from the "([^"]+)" object already described(?: \(from ([^)]*)\))?/') => self::note(
                \sprintf('This example\'s "%s" objects differ from the object already described', $m[2]),
                array_values(array_filter([\sprintf('%s holds "%s" objects.', 'the response' === $m[1] ? 'The response' : $m[1], $m[2]), \sprintf('The object was described first from %s; the two differ.', '' !== ($m[3] ?? '') ? $m[3] : 'another example')])),
                \sprintf('The "%s" object keeps its description, shared by its operations and events: this example is not used for it.', $m[2]),
                ['Merge: describe the object from both examples (keys missing from one become optional).', 'Replace: describe it from this example only.', 'Or accept: the object stays as it is.'],
            ),
            $match('/payload data differs from the "([^"]+)" object shape/') => self::note(
                \sprintf('The payload\'s object differs from the "%s" object already described', $m[1]),
                [\sprintf('The object of this payload does not match the object of "%s" (from another example).', $m[1])],
                \sprintf('The "%s" object, used by its events and operations, keeps its old shape.', $m[1]),
                ['Import the payload again with "Replace what is described" ticked, to replace it.', 'Or merge the examples into the object shape: api-forge:import-object --force with both files.'],
            ),
            $match('/resource "([^"]+)" derived from the event name/') => self::note(
                \sprintf('The object of the payload was named "%s", after the event', $m[1]),
                ['The payload holds an object (its "data").', \sprintf('api-forge named its resource "%s", from the entity in the event name, and described the object in %s.yaml.', $m[1], $m[1])],
                \sprintf('The events of this entity, and the operations of "%s", share this one description of the object.', $m[1]),
                [\sprintf('If the API calls the object otherwise, rename %s.yaml and its "resource" value.', $m[1]), 'Otherwise accept.'],
            ),
            $match('/events: resource "([^"]+)" set on (.+) from the payload of "([^"]+)", by their name/') => self::note(
                \sprintf('%s assumed to carry the same object as "%s"', self::count($m[2], 'other event', 'other events'), $m[3]),
                [\sprintf('Only "%s" has a payload example.', $m[3]), \sprintf('These events have the same entity in their name: %s.', $m[2]), \sprintf('api-forge assumed they send the same object, and pointed them to the "%s" object (%s.yaml).', $m[1], $m[1])],
                \sprintf('All these events share one description of the object: improving it improves them all, and there is nothing to write per event.'),
                ['Accept if the API docs say these events send this object (they usually do, when their name starts with the same entity).', 'If one of them sends something else, add a payload for that event: it gets its own shape.'],
            ),
            $match('/the resource "([^"]+)" has no object shape: the payload data of (.+) is untyped/') => self::note(
                \sprintf('Events of "%s" are untyped: "%s" has no object yet', $m[1], $m[1]),
                [\sprintf('%s point to the resource "%s", which has no object shape.', ucfirst($m[2]), $m[1])],
                'Their payloads are typed as any array.',
                ['Add a payload example for one of these events (Webhooks page): it gives the object.'],
            ),
            $match('/^signature: default scheme/') => self::note(
                'The signature scheme is a default, not checked against the docs',
                ['api-forge cannot read how an API signs its webhooks from an example: it wrote HMAC-SHA256 of the body, in X-Signature.'],
                'If the API signs otherwise, every real delivery is rejected (HTTP 406).',
                ['Read the "verify signatures" page of the API docs, and set "signature" in _webhooks.yaml (header, what is signed, encoding).', 'Then accept.'],
            ),
            $match('/^event_name: set where the event name is/') => self::note(
                'Where the event name is, is not known yet',
                ['No payload has shown where the API puts the event name.'],
                'Deliveries cannot be routed to the handler of their event.',
                ['Add a payload example: the event name is read from it.', 'Or set "event_name" in _webhooks.yaml ("path" in the payload, or "header").'],
            ),
            $match('/^events: resources were derived from the object names/') => self::note(
                'The resources of the events come from the events table',
                ['Each event got the resource named in the "object" column of the table.'],
                'Their payload types use the object of that resource.',
                ['Check a few of them, then accept.'],
            ),
            $match('/^meta: the shape comes from one example/') => self::note(
                'The "meta" of the payloads comes from one example',
                ['Other events may send more keys in "meta".'],
                'Those keys would be missing from the generated type.',
                ['Accept, or import more payloads: their keys are added.'],
            ),
            $match('/^query parameters are assumed optional/') => self::note(
                'The query parameters are assumed optional',
                ['The example cannot tell which query parameters are required.'],
                'The generated method takes them all as optional arguments.',
                ['Accept if the docs mark none required.', 'Otherwise set "required: true" on the required ones in the descriptor (Advanced), then accept.'],
            ),
            $match('/path parameter \{([^}]+)\} guessed from the example value "([^"]+)"/') => self::note(
                \sprintf('Path parameter {%s} guessed from "%s"', $m[1], $m[2]),
                [\sprintf('The URL of the example has "%s" where a value goes; api-forge named the parameter {%s}.', $m[2], $m[1])],
                \sprintf('The generated method takes an argument $%s.', $m[1]),
                ['Rename it in the descriptor if the docs call it otherwise, then accept.'],
            ),
            $match('/base URI: "([^"]+)" looks like a sandbox/') => self::note(
                'The base URI looks like a sandbox',
                [\sprintf('The example calls %s.', $m[1])],
                'The generated client would call the sandbox in production.',
                ['Edit the API (step 1): live host, sandbox host, and the variable that chooses at runtime.', 'Then accept.'],
            ),
            $match('/probe guessed: "([^"]+)"/') => self::note(
                \sprintf('The doctor will check the API with "%s"', $m[1]),
                [\sprintf('api-forge chose "%s": a GET without parameters.', $m[1])],
                'The doctor calls it on every run.',
                ['Accept if it is harmless and cheap (no side effect, small answer).', 'Otherwise choose another operation for doctor.probe in _api.yaml.'],
            ),
            $match('/request body: every key is typed as required/') => self::note(
                'Every key of the request body is required, for now',
                ['The example body cannot tell which keys are optional.'],
                'The generated method would demand every key.',
                [\sprintf('Paste the docs page of the body (%s): it marks the optional keys.', $docs), 'Or mark them with "?" in the descriptor (Advanced), then accept.'],
            ),
            $match('/request body: all attributes are typed as optional/') => self::note(
                'Every attribute of the request body is optional, for now',
                ['The example cannot tell which attributes are required.'],
                'The generated method would accept a body without its required attributes.',
                ['Remove the "?" of the required attributes in the descriptor, then accept.'],
            ),
            $match('/(response|request body) is not JSON \(([^)]*)\)/') => self::note(
                \sprintf('The %s example is not JSON', 'response' === $m[1] ? 'response' : 'body'),
                [\sprintf('Reading it failed: %s.', $m[2])],
                'It is ignored: nothing is typed from it.',
                [\sprintf('Fix %s (JSON strings use double quotes), then import again.', $example)],
            ),
            $match('/no field of the documentation capture matches the example/') => self::note(
                'The docs page matched no field of the example',
                ['None of the names of the copied page are keys of the example.'],
                'Nothing of the docs is applied.',
                [\sprintf('Check that %s is the page of this example (not another endpoint or event).', $docs), 'Copy the field list of the page, not only its text.'],
            ),
            $match('/^no response example/') => self::note(
                'No response example',
                ['The operation has no example of its response.'],
                'Its method returns an untyped array.',
                ['Add the response of the docs (the endpoint screens), then import again.'],
            ),
            $match('/curl option "([^"]+)" was ignored/') => self::note(
                \sprintf('The curl option %s was ignored', $m[1]),
                ['api-forge does not use this option.'],
                null,
                ['Accept, unless it changes the request (then describe it in the descriptor).'],
            ),
            $match('/the type of the relationship "([^"]+)" is unknown/') => self::note(
                \sprintf('The type of the relationship "%s" is unknown', $m[1]),
                ['The example body names the relationship without its type.'],
                'Its argument is untyped.',
                ['Set its resource in the descriptor (Advanced), then accept.'],
            ),
            default => self::note(ucfirst((string) preg_replace('/^[a-z ]+: /', '', $text)), [], null, ['Check the descriptor (Advanced), then accept.']),
        };
    }

    /**
     * @param list<string> $found
     * @param list<string> $options
     *
     * @return array{title: string, found: list<string>, impact: ?string, options: list<string>}
     */
    private static function note(string $title, array $found, ?string $impact, array $options): array
    {
        return ['title' => $title, 'found' => $found, 'impact' => $impact, 'options' => $options];
    }

    /**
     * "3 fields are" from a list of names ("a, b, c"; "…" counts as more).
     */
    private static function count(string $names, string $one, string $many): string
    {
        $items = array_filter(array_map('trim', explode(',', $names)), static fn (string $item) => '' !== $item);
        $more = \in_array('…', $items, true);
        $n = \count(array_diff($items, ['…']));

        return 0 === $n ? 'Some '.$many : ($more ? 'More than '.$n.' '.$many : (1 === $n ? '1 '.$one : $n.' '.$many));
    }
}
