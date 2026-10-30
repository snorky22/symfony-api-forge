<?php

namespace Snorky22\ApiForge\Dev\Api;

use Symfony\Component\String\Inflector\EnglishInflector;

use function Symfony\Component\String\u;

/**
 * Turns descriptor names (kebab-case, snake_case, query names like "filter[user_email]") into PHP names.
 */
final class Naming
{
    public static function className(string $name): string
    {
        return u($name)->camel()->title()->toString();
    }

    public static function variableName(string $name): string
    {
        return u($name)->camel()->toString();
    }

    /**
     * Name of the type alias of a resource object: "customers" gives "Customer".
     */
    public static function aliasName(string $type): string
    {
        $words = explode('-', u($type)->snake()->replace('_', '-')->toString());
        $words[] = (new EnglishInflector())->singularize(array_pop($words))[0];

        return self::className(implode('-', $words));
    }

    /**
     * Constant of a webhook event: "order_created" or "order.created" give "ORDER_CREATED".
     */
    public static function constantName(string $name): string
    {
        return strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', u($name)->snake()->toString()), '_'));
    }

    /**
     * Handler method of a webhook event: "order_created" gives "onOrderCreated".
     */
    public static function eventMethodName(string $name): string
    {
        return 'on'.self::className(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
    }

    public static function isIdentifier(string $name): bool
    {
        return 1 === preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }
}
