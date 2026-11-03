<?php

namespace Snorky22\ApiForge\Dev\Webhook;

/**
 * The result of one check of the webhook chain.
 */
final readonly class WebhookCheck
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /**
     * @param self::OK|self::WARNING|self::ERROR $level
     */
    public function __construct(
        public string $level,
        public string $message,
    ) {
    }

    public static function ok(string $message): self
    {
        return new self(self::OK, $message);
    }

    public static function warning(string $message): self
    {
        return new self(self::WARNING, $message);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message);
    }
}
