<?php

namespace Snorky22\ApiForge\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\YamlToPhpConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlToPhpConverterTest extends TestCase
{
    public function testConvertsADescriptor(): void
    {
        $yaml = <<<'YAML'
            resource: prices
            operations:
                list:
                    method: GET
                    response:
                        shape: |-
                            array{
                                data: list<array{id: string}>,
                            }
            YAML;

        $result = (new YamlToPhpConverter())->convert($yaml);

        self::assertSame(<<<'PHP'
            [
                'resource' => 'prices',
                'operations' => [
                    'list' => [
                        'method' => 'GET',
                        'response' => [
                            'shape' => <<<'SHAPE'
                                array{
                                    data: list<array{id: string}>,
                                }
                                SHAPE,
                        ],
                    ],
                ],
            ]
            PHP, $result['code']);
        self::assertSame([], $result['comments']);
    }

    public function testYamlPitfallsAreWhatSymfonyParses(): void
    {
        $yaml = "a: no\nb: 1.10\nc: ~\nd: '007'\ne: 0o17\nf: [1, two, {g: h}]\nh: &x {k: v}\ni: *x\n";

        $result = (new YamlToPhpConverter())->convert($yaml);

        // The code is checked against the parsed YAML, whatever Symfony makes of it
        self::assertSame(Yaml::parse($yaml), eval('return '.$result['code'].';'));
    }

    public function testEmptyYamlIsAnEmptyArray(): void
    {
        self::assertSame('[]', (new YamlToPhpConverter())->convert('')['code']);
        self::assertSame('[]', (new YamlToPhpConverter())->convert("# nothing\n")['code']);
    }

    public function testListsCommentsThatPhpCannotCarry(): void
    {
        $yaml = "# the API\nname: x # trailing\n# a note\nshape: |-\n    # not a comment, data\n    array{}\n";

        $result = (new YamlToPhpConverter())->convert($yaml);

        self::assertSame(['# the API', '# a note'], $result['comments']);
    }

    public function testInvalidYamlAndTagsAreRefused(): void
    {
        foreach (["a: [1, 2", "a: 1\na: 2", "a: !custom x"] as $yaml) {
            try {
                (new YamlToPhpConverter())->convert($yaml);
                self::fail('Not refused: '.$yaml);
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryDescriptorOfTheRepositoryConvertsAndReadsBack(): void
    {
        $files = glob(__DIR__.'/../../examples/*/config/api/*/*.yaml') ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $result = (new YamlToPhpConverter())->convert((string) file_get_contents($file));

            self::assertSame(Yaml::parseFile($file) ?? [], eval('return '.$result['code'].';'), $file);
        }
    }
}
