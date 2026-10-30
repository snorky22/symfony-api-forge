<?php

namespace App\Dev\Api\Descriptor;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Checks that a descriptor shape is a valid PHPDoc type, as PHPStan parses it.
 */
final class ShapeValidator
{
    private readonly Lexer $lexer;
    private readonly TypeParser $typeParser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $this->typeParser = new TypeParser($config, new ConstExprParser($config));
    }

    /**
     * @return ?string the parse error, null when the type is valid
     */
    public function validate(string $type): ?string
    {
        $tokens = new TokenIterator($this->lexer->tokenize(trim($type)));
        try {
            $this->typeParser->parse($tokens);
            $tokens->consumeTokenType(Lexer::TOKEN_END);
        } catch (ParserException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
