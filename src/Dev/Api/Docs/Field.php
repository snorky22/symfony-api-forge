<?php

namespace Snorky22\ApiForge\Dev\Api\Docs;

/**
 * A field of an example, all the values seen merged (the elements of a list are one item).
 *
 * @internal
 */
final class Field
{
    /** @var array<string, self> */
    public array $children = [];
    public ?self $item = null;
    public bool $isObject = false;
    public bool $isList = false;
    /** @var array<string, true> */
    private array $scalars = [];

    public function __construct(
        public readonly string $name,
        private readonly string $path = '',
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function absorb(mixed $value): void
    {
        if ($value instanceof \stdClass) {
            $this->isObject = true;
            foreach (get_object_vars($value) as $key => $child) {
                $this->children[(string) $key] ??= new self((string) $key, ('' === $this->path ? '' : $this->path.'.').$key);
                $this->children[(string) $key]->absorb($child);
            }
        } elseif (\is_array($value)) {
            $this->isList = true;
            $this->item ??= new self('', $this->path.'[]');
            foreach ($value as $element) {
                $this->item->absorb($element);
            }
        } else {
            $this->scalars[match (true) {
                null === $value => 'null',
                \is_bool($value) => 'bool',
                \is_int($value) => 'int',
                \is_float($value) => 'float',
                default => 'string',
            }] = true;
        }
    }

    /**
     * The object whose fields follow this one in the documentation: itself, or the items of a list.
     */
    public function container(): ?self
    {
        if ($this->isObject) {
            return $this;
        }

        return $this->isList && $this->item?->isObject ? $this->item : null;
    }

    /**
     * @param \Closure(self): void $visit
     */
    public function walk(\Closure $visit): void
    {
        $visit($this);
        foreach ($this->children as $child) {
            $child->walk($visit);
        }
        $this->item?->walk($visit);
    }

    /**
     * How the example differs from the documented type, null when it agrees (or says nothing).
     */
    public function differsFrom(DocsEntry $entry): ?string
    {
        $kinds = array_keys(array_diff_key($this->scalars, ['null' => true]));
        if ('object' === $entry->type) {
            return $this->isList || [] !== $kinds ? 'another type' : null;
        }
        if ('array' === $entry->type) {
            return $this->isObject || [] !== $kinds ? 'another type' : null;
        }
        if ($this->isObject || $this->isList) {
            return 'an object or a list';
        }
        $expected = self::scalarKinds($entry->type);

        return [] !== array_diff($kinds, $expected) ? implode('|', $kinds) : null;
    }

    /**
     * @return list<string>
     */
    private static function scalarKinds(string $type): array
    {
        return ['string' => ['string'], 'integer' => ['int'], 'number' => ['int', 'float'], 'boolean' => ['bool']][$type] ?? [];
    }
}
