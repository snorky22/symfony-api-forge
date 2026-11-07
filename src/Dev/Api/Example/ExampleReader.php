<?php

namespace Snorky22\ApiForge\Dev\Api\Example;

use Snorky22\ApiForge\Dev\LenientJsonDecoder;

/**
 * Reads the documentation examples of an API, whatever their format: curl and JSON files are used as they
 * are, other formats (ExampleFormat, e.g. a PHP array written from a prose description) are converted.
 */
final class ExampleReader
{
    /**
     * @param iterable<ExampleFormat> $formats
     */
    public function __construct(
        private readonly iterable $formats = [],
        private readonly LenientJsonDecoder $decoder = new LenientJsonDecoder(),
    ) {
    }

    /**
     * @return list<string> the extensions of the other formats
     */
    public function extensions(): array
    {
        $extensions = [];
        foreach ($this->formats as $format) {
            array_push($extensions, ...$format->extensions());
        }

        return array_values(array_unique($extensions));
    }

    /**
     * The extensions of the files that hold a data structure: json, and the other formats.
     *
     * @return list<string>
     */
    public function dataExtensions(): array
    {
        return ['json', ...$this->extensions()];
    }

    /**
     * A documented operation: a curl command file (with the example response given separately), or an
     * example of another format holding the request and the response.
     *
     * @throws \InvalidArgumentException
     */
    public function operation(string $file, ?string $baseUri = null): Example
    {
        $content = $this->content($file);
        $format = $this->format($file);
        if (null === $format) {
            return new Example($content, null);
        }

        $resolver = new ReferenceResolver($this, \dirname($file), [realpath($file) ?: $file]);

        return $this->wrap($file, static fn () => $format->operation($content, $baseUri, $resolver->resolve(...)));
    }

    /**
     * A JSON document: a JSON file as it is (with the references to other data files replaced by their
     * content), an example of another format converted to JSON.
     *
     * @throws \InvalidArgumentException
     */
    public function json(string $file): string
    {
        $content = $this->content($file);
        $format = $this->format($file);
        $resolver = new ReferenceResolver($this, \dirname($file), [realpath($file) ?: $file]);
        if (null === $format && !$resolver->mayHold($content)) {
            return $content;
        }

        return $this->wrap($file, fn () => self::encode($resolver->resolve(null === $format ? $this->decoder->decode($content, false) : $format->document($content))));
    }

    /**
     * The data of a file (JSON or another format), its own references resolved: the content a reference
     * to this file is replaced by.
     *
     * @param list<string> $stack the files being resolved, to detect cycles
     *
     * @throws \InvalidArgumentException
     */
    public function document(string $file, array $stack = []): mixed
    {
        $content = $this->content($file);
        $format = $this->format($file);

        return $this->wrap($file, fn () => (new ReferenceResolver($this, \dirname($file), $stack ?: [realpath($file) ?: $file]))
            ->resolve(null === $format ? $this->decoder->decode($content, false) : $format->document($content)));
    }

    public function format(string $file): ?ExampleFormat
    {
        $extension = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        foreach ($this->formats as $format) {
            if (\in_array($extension, $format->extensions(), true)) {
                return $format;
            }
        }

        return null;
    }

    private function content(string $file): string
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            throw new \InvalidArgumentException(\sprintf('Cannot read file "%s".', $file));
        }

        return $content;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function wrap(string $file, \Closure $read): mixed
    {
        try {
            return $read();
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(\sprintf('%s: %s', $file, $e->getMessage()), 0, $e);
        }
    }

    private static function encode(mixed $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)."\n";
    }
}
