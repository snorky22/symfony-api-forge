<?php

namespace Snorky22\ApiForge\Dev\Api\Descriptor;

final class InvalidDescriptorException extends \InvalidArgumentException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid API descriptors:\n- ".implode("\n- ", $errors));
    }
}
