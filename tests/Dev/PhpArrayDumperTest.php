<?php

namespace Snorky22\ApiForge\Tests\Dev;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\PhpArrayDumper;

final class PhpArrayDumperTest extends TestCase
{
    public function testReadableLayout(): void
    {
        $code = (new PhpArrayDumper())->dump([
            'resource' => 'prices',
            'tags' => ['a', 'b'],
            'empty' => [],
            'operations' => ['list' => ['method' => 'GET', 'paginated' => true, 'size' => 10, 'ratio' => 1.5, 'none' => null]],
            'shape' => "array{\n    id: string,\n}\n",
        ]);

        self::assertSame(<<<'PHP'
            [
                'resource' => 'prices',
                'tags' => [
                    'a',
                    'b',
                ],
                'empty' => [],
                'operations' => [
                    'list' => [
                        'method' => 'GET',
                        'paginated' => true,
                        'size' => 10,
                        'ratio' => 1.5,
                        'none' => null,
                    ],
                ],
                'shape' => <<<'SHAPE'
                    array{
                        id: string,
                    }

                    SHAPE,
            ]
            PHP, $code);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function roundTrips(): iterable
    {
        $dumper = new PhpArrayDumper();
        yield 'scalars' => [['int' => -3, 'float' => 0.1, 'big' => 1e100, 'negzero' => -0.0, 'true' => true, 'false' => false, 'null' => null]];
        yield 'infinities' => [['inf' => \INF, 'minus' => -\INF]];
        yield 'nan' => [['nan' => \NAN, 'list' => [\NAN]]];
        yield 'integer keys, gaps and order' => [[3 => 'a', 1 => 'b', 'x' => 'c', 0 => 'd']];
        yield 'numeric string keys become integers, as in PHP' => [['123' => 'a', '-5' => 'b', '0123' => 'c']];
        yield 'quotes and backslashes' => [["it's", 'a\\b', "tab\t", 'dollar $x {$y} ${z}', "\\' \\\\"]];
        yield 'unicode' => [['é' => 'ça', 'emoji' => "🙂\nsecond"]];
        yield 'text with a final newline' => [["a\nb\n"]];
        yield 'text with blank and indented lines' => [["a\n\n    b\n  \nc"]];
        yield 'text with carriage returns and tabs, not a nowdoc' => [["a\r\nb", "a\n\tb"]];
        yield 'text with control characters and invalid UTF-8' => [["a\0\nb", "a\n\xff"]];
        yield 'text starting or ending with a blank line' => [["\na\n", "\n"]];
        yield 'marker inside the text' => [['shape' => "a\nSHAPE\nb", 'text' => "TEXT\nSHAPE_\n  SHAPE x"]];
        yield 'marker-like key' => [['1a b' => "x\ny", '' => "x\ny"]];
        yield 'php inside the text' => [["<?php echo 1;\n?>\n__halt_compiler();"]];
        yield 'deep' => [['a' => ['b' => ['c' => ['d' => ['e' => "x\ny"]]]]]];
        yield 'root scalar' => ["plain"];
        yield 'root null' => [null];
        yield 'empty' => [[]];
        unset($dumper);
    }

    #[DataProvider('roundTrips')]
    public function testCodeReadsBackAsTheSameData(mixed $data): void
    {
        $dumper = new PhpArrayDumper();
        $code = $dumper->dump($data);

        self::assertTrue($dumper->check($code, $data), $code);
    }

    public function testRejectsWhatIsNotData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PhpArrayDumper())->dump(['a' => new \stdClass()]);
    }

    public function testCheckRefusesCodeThatIsNotALiteral(): void
    {
        $dumper = new PhpArrayDumper();

        self::assertFalse($dumper->check("['a' => phpinfo()]", ['a' => 1]));
        self::assertFalse($dumper->check('[1]; exit', [1]));
        self::assertFalse($dumper->check('"$x"', ''));
        self::assertFalse($dumper->check("[1\n", [1]));
        self::assertFalse($dumper->check('[2]', [1]));
        self::assertFalse($dumper->check("['1' => 'a']", ['1' => 'b']));
        self::assertFalse($dumper->check('[1, 2]', [2 => 1]));
    }
}
