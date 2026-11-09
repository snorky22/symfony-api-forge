<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Web\DescriptorErrors;

final class DescriptorErrorsTest extends TestCase
{
    public function testSaysAQueryParameterTypeThePlainWay(): void
    {
        $error = '/p/config/api/shop/prices.yaml: The value "array" is not allowed for path "resource.operations.list.query.include.type". Permissible values: "string", "int", "float", "bool".';

        self::assertSame(
            'config/api/shop/prices.yaml: the query parameter "include" of the operation "list" has the type "array", which does not exist; use one of string, int, float, bool. A list of values is a string with the values separated by commas (include=product,price).',
            DescriptorErrors::explain($error, '/p'),
        );
    }

    public function testExplainsAShapeThatHoldsAFileName(): void
    {
        $error = '/p/config/api/shop/products.yaml: operation "list": "response.shape" is not a valid PHPDoc type: Unexpected token ":", expected \'}\' at offset 15 on line 2';

        $explained = DescriptorErrors::explain($error, '/p');

        self::assertStringStartsWith('config/api/shop/products.yaml: operation "list": "response.shape" is not a valid PHPDoc type', $explained);
        self::assertStringContainsString('belongs in an example', $explained);
    }

    public function testShowsOtherPathsAsPathsInTheFile(): void
    {
        self::assertSame(
            'a.yaml: Unrecognized option "x" under path resource › operations',
            DescriptorErrors::explain('a.yaml: Unrecognized option "x" under path "resource.operations"', '/p'),
        );
    }
}
