<?php

namespace Snorky22\ApiForge\Tests\Dev;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\PhpLiteral;

final class PhpLiteralTest extends TestCase
{
    public function testReadsAFileWithCommentsAndNowdocs(): void
    {
        $php = <<<'PHP'
            <?php

            // Paddle: unit_price_overrides
            return [
                /* max 250, unique */
                'country_codes' => ['FR', 'DE'], # trailing
                'amount' => '1000',
                'ratio' => -1.5,
                'on' => true,
                'none' => null,
                'text' => <<<'TEXT'
                    a $b {$c}
                    TEXT,
                'joined' => 'a'.'b',
            ];
            PHP;

        self::assertSame([
            'country_codes' => ['FR', 'DE'],
            'amount' => '1000',
            'ratio' => -1.5,
            'on' => true,
            'none' => null,
            'text' => 'a $b {$c}',
            'joined' => 'ab',
        ], PhpLiteral::evaluate($php));
    }

    public function testReadsABareLiteral(): void
    {
        self::assertSame([1, 'a' => 2], PhpLiteral::evaluate("[1, 'a' => 2]"));
        self::assertSame('x', PhpLiteral::evaluate("'x';"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'function call' => ["<?php return ['a' => phpinfo()];", 'line 1'];
        yield 'variable' => ["<?php return [\$a];", '$a'];
        yield 'interpolation' => ['<?php return ["x $y"];', 'line 1'];
        yield 'second statement' => ["<?php return [1];\nexit;", 'line 2'];
        yield 'code before' => ["<?php \$a = [1];\nreturn \$a;", 'line 1'];
        yield 'class constant' => ['<?php return [Foo::BAR];', '::'];
        yield 'new' => ['<?php return [new stdClass];', 'new'];
        yield 'closing tag in a comment' => ["<?php return [1 // ?> <?php system('x')\n];", 'line 1'];
        yield 'long array syntax' => ['<?php return array(1);', '('];
        yield 'two returns' => ['<?php return return [1];', 'return'];
        yield 'empty' => ['<?php // nothing', 'no data'];
        yield 'syntax error' => ['<?php return [1, ;', '";"'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotALiteral(string $php, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PhpLiteral::evaluate($php);
    }

    public function testUnknownConstantIsAnErrorNotACrash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpLiteral::evaluate('[UNKNOWN_CONSTANT]');
    }
}
