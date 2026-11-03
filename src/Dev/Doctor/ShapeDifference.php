<?php

namespace Snorky22\ApiForge\Dev\Doctor;

/**
 * A difference between a value received from an API and the array shape of its descriptor.
 */
final readonly class ShapeDifference
{
    /** A required key is absent: code reading it fails */
    public const MISSING = 'missing';
    /** A key the shape doesn't know: the API has a new field */
    public const NEW = 'new';
    /** A value of another type than the shape's */
    public const TYPE = 'type';
    /** A value outside the shape's literal values (enum) */
    public const VALUE = 'value';

    /**
     * @param self::MISSING|self::NEW|self::TYPE|self::VALUE $kind
     * @param string                                         $path in dot notation, "[]" for list items, e.g. "data[].attributes.status"
     */
    public function __construct(
        public string $kind,
        public string $path,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return ('' !== $this->path ? $this->path.': ' : '').$this->message;
    }
}
