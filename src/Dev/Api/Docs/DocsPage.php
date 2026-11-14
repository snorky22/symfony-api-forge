<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * A docs page as captured: its text (<example>.docs.md), and its HTML when it was kept (<example>.docs.html), which
 * gives the nesting of the fields.
 */
final class DocsPage
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $html = null,
    ) {
    }

    /**
     * The page of a text capture, with the HTML next to it if there is one; null when the file doesn't exist.
     */
    public static function fromFile(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }
        $text = file_get_contents($file);
        if (false === $text) {
            throw new \InvalidArgumentException(\sprintf('Cannot read file "%s".', $file));
        }
        $html = self::htmlFile($file);

        return new self($text, is_file($html) ? (string) file_get_contents($html) : null);
    }

    /**
     * Where the HTML of a text capture is kept: x.docs.md => x.docs.html.
     */
    public static function htmlFile(string $file): string
    {
        return (string) preg_replace('/\.md$/', '', $file).'.html';
    }

    /**
     * @return list<DocsEntry>
     */
    public function entries(DocsCapture $capture = new DocsCapture(), DocsHtml $html = new DocsHtml()): array
    {
        $entries = $capture->parse($this->text);

        return null !== $this->html ? $html->place($entries, $this->html) : $entries;
    }
}
