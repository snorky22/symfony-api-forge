<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * Reads the nesting of the fields of a docs page from its HTML: the text of a page loses it, the HTML keeps it.
 *
 * A field header is an element whose first child element holds a name alone and whose second one starts with a type
 * ("status" then "string | null"), the way reference pages show a field. A field's block is the first element around
 * its header that holds other headers, all deeper than its own, and none before it: those headers are its fields.
 * That is containment in the page, not indentation, so a second section of the page (the response after the
 * request) does not fall under the last field of the first one.
 */
final class DocsHtml
{
    private const NAME = '/^[A-Za-z_$@][A-Za-z0-9_.$@-]*$/';
    private const TYPE = '/^(string|integer|int|number|float|double|boolean|bool|object|array|null|enum|datetime|date-time|timestamp|decimal)\b/i';

    /**
     * The HTML worth keeping: the elements and their text, without scripts, styles, images, comments and attributes
     * (a page copied from the browser carries its whole style inline, a hundred times its content).
     */
    public function clean(string $html): string
    {
        $document = self::load($html);
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//script|//style|//svg|//noscript|//template|//button|//img|//meta|//link|//title|//comment()') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//*[@*]') ?: [] as $element) {
            if ($element instanceof \DOMElement) {
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    if ('href' !== $attribute->name) {
                        $element->removeAttribute($attribute->name);
                    }
                }
            }
        }
        // The items a page fills when they are shown ("+ Show all values"): empty in a copy
        foreach (iterator_to_array($xpath->query('//li[not(normalize-space(.))]') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim((string) preg_replace('/\n\s*\n+/', "\n", $out))."\n";
    }

    /**
     * The fields of the page, in its order.
     *
     * @return list<array{name: string, type: string, parents: list<string>}>
     */
    public function fields(string $html): array
    {
        return array_map(static fn (array $field) => ['name' => $field['name'], 'type' => $field['type'], 'parents' => $field['parents']], $this->read($html));
    }

    /**
     * The page as text, the way DocsCapture reads it: each field on a line, its name, type and flags glued as a web copy
     * gives them ("starts_atstring (date-time)required"), then its own text (its values as "* `value`"), without the
     * blocks of its fields, which follow. The text of a page copied from the browser puts the name and the type on two
     * lines: this one is made from its HTML instead.
     */
    public function text(string $html): string
    {
        $fields = $this->read($html);
        $headers = new \SplObjectStorage();
        $blocks = new \SplObjectStorage();
        foreach ($fields as $field) {
            $headers[$field['element']] = true;
            $blocks[$field['block']] = true;
        }

        $text = '';
        foreach ($fields as $field) {
            $flags = '';
            $seen = 0;
            foreach ($field['element']->childNodes as $child) {
                if ($child instanceof \DOMElement && ++$seen > 2) {
                    $flags .= self::textOf($child);
                }
            }
            $lines = [];
            $line = '';
            if ($field['block'] !== $field['element']) {
                $this->lines($field['block'], $headers, $blocks, $field['block'], $lines, $line, '');
            }
            $lines[] = $line;
            $body = trim(implode("\n", array_map('rtrim', $lines)), "\n");
            $text .= $field['name'].$field['type'].$flags."\n\n".('' !== $body ? (string) preg_replace("/\n{3,}/", "\n\n", $body)."\n\n" : '');
        }

        return $text;
    }

    /**
     * The text of an element, line by line: blocks start lines, a list item is "* " (its first code in backticks), the
     * lines after the first one of an item are indented; the headers and the blocks of other fields are left out.
     *
     * @param \SplObjectStorage<\DOMElement, true> $headers
     * @param \SplObjectStorage<\DOMElement, true> $blocks
     * @param list<string>                         $lines
     */
    private function lines(\DOMNode $node, \SplObjectStorage $headers, \SplObjectStorage $blocks, \DOMElement $own, array &$lines, string &$line, string $indent): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $line .= (string) preg_replace('/\s+/u', ' ', '' === trim($line) ? ltrim($child->data) : $child->data);
                continue;
            }
            if (!$child instanceof \DOMElement || isset($headers[$child]) || ($child !== $own && isset($blocks[$child]))) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (\in_array($tag, ['script', 'style', 'svg', 'button', 'template'], true)) {
                continue;
            }
            if ('code' === $tag) {
                $line .= '`'.trim($child->textContent).'`';
                continue;
            }
            if ('br' === $tag) {
                $lines[] = $line;
                $line = $indent;
                continue;
            }
            $block = \in_array($tag, ['div', 'p', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'details', 'summary', 'dl', 'dt', 'dd', 'table', 'tr', 'pre', 'blockquote'], true);
            if (!$block) {
                $this->lines($child, $headers, $blocks, $own, $lines, $line, $indent);
                continue;
            }
            if ('' !== trim($line)) {
                $lines[] = $line;
            }
            $line = $indent;
            if ('li' === $tag) {
                $line = $indent.'* ';
                $this->lines($child, $headers, $blocks, $own, $lines, $line, $indent.'  ');
            } else {
                $this->lines($child, $headers, $blocks, $own, $lines, $line, $indent);
            }
            if ('' !== trim($line) && '* ' !== trim($line).' ') {
                $lines[] = $line;
            }
            $line = $indent;
            if (\in_array($tag, ['p', 'ul', 'ol'], true)) {
                $lines[] = '';
            }
        }
    }

    /**
     * @return list<array{name: string, type: string, parents: list<string>, element: \DOMElement, block: \DOMElement}>
     */
    private function read(string $html): array
    {
        $document = self::load($html);

        $headers = [];
        foreach ((new \DOMXPath($document))->query('//*[count(*) >= 2]') ?: [] as $element) {
            if ($element instanceof \DOMElement && null !== $header = $this->header($element)) {
                $headers[] = $header + ['element' => $element, 'depth' => self::depth($element)];
            }
        }

        $fields = [];
        foreach ($headers as $index => $header) {
            $block = $this->block($headers, $index);
            $parents = [];
            for ($i = $index - 1; $i >= 0; --$i) {
                if (self::contains($fields[$i]['block'], $header['element'])) {
                    $parents = [...$fields[$i]['parents'], $fields[$i]['name']];
                    break;
                }
            }
            $fields[] = ['name' => $header['name'], 'type' => $header['type'], 'parents' => $parents, 'element' => $header['element'], 'block' => $block];
        }

        return $fields;
    }

    /**
     * The entries of the text of the page with their parents, when the HTML has them: matched by name, in the order of
     * the page. The HTML may hold more than the text (the whole page for one of its sections): the text is matched
     * where it fits best.
     *
     * @param list<DocsEntry> $entries
     *
     * @return list<DocsEntry>
     */
    public function place(array $entries, string $html): array
    {
        $fields = $this->fields($html);
        if ([] === $entries || [] === $fields) {
            return $entries;
        }

        $best = [];
        foreach ($fields as $start => $field) {
            if ($field['name'] !== $entries[0]->name) {
                continue;
            }
            $matched = $this->match($entries, $fields, $start);
            if (\count($matched) > \count($best)) {
                $best = $matched;
            }
        }

        $placed = [];
        foreach ($entries as $index => $entry) {
            $placed[] = isset($best[$index]) ? $entry->withParents($fields[$best[$index]]['parents']) : $entry;
        }

        return $placed;
    }

    /**
     * @param list<DocsEntry>                                                    $entries
     * @param list<array{name: string, type: string, parents: list<string>}> $fields
     *
     * @return array<int, int> entry position => field position
     */
    private function match(array $entries, array $fields, int $start): array
    {
        $matched = [];
        $at = $start;
        foreach ($entries as $index => $entry) {
            // A name the HTML doesn't have at all (a line the text reads as a field) is skipped, not searched to the end
            for ($i = $at; $i < \count($fields) && $i < $at + 50; ++$i) {
                if ($fields[$i]['name'] === $entry->name) {
                    $matched[$index] = $i;
                    $at = $i + 1;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @return ?array{name: string, type: string}
     */
    private function header(\DOMElement $element): ?array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            // A header holds elements only: a sentence quoting two names ("Requires <code>recur</code>.") is not one
            if ($child instanceof \DOMText && '' !== trim($child->data)) {
                return null;
            }
            if ($child instanceof \DOMElement && \count($children) < 2) {
                $children[] = $child;
            }
        }
        // The name is an element alone (or wrapping one element), not a block holding the name, the type and more
        if ($children[0]->getElementsByTagName('*')->length > 1) {
            return null;
        }
        $name = self::textOf($children[0]);
        $type = self::textOf($children[1]);
        if (1 !== preg_match(self::NAME, $name) || 1 !== preg_match(self::TYPE, $type) || mb_strlen($type) > 80) {
            return null;
        }

        return ['name' => $name, 'type' => $type];
    }

    /**
     * The block of a field: the first element around its header holding other headers, deeper than its own.
     *
     * @param list<array{element: \DOMElement, depth: int}> $headers
     */
    private function block(array $headers, int $index): \DOMElement
    {
        $block = $headers[$index]['element'];
        for ($parent = $block->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if ($index > 0 && self::contains($parent, $headers[$index - 1]['element'])) {
                break;
            }
            $holds = false;
            for ($i = $index + 1; $i < \count($headers) && self::contains($parent, $headers[$i]['element']); ++$i) {
                if ($headers[$i]['depth'] <= $headers[$index]['depth']) {
                    break 2;
                }
                $holds = true;
            }
            $block = $parent;
            // The first element holding other headers holds the fields of this one: going further would take what follows
            if ($holds) {
                break;
            }
        }

        return $block;
    }

    private static function load(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private static function contains(\DOMNode $ancestor, \DOMNode $node): bool
    {
        for ($at = $node->parentNode; null !== $at; $at = $at->parentNode) {
            if ($at === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private static function depth(\DOMNode $node): int
    {
        $depth = 0;
        for ($at = $node->parentNode; null !== $at; $at = $at->parentNode) {
            ++$depth;
        }

        return $depth;
    }

    private static function textOf(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
