<?php

namespace Snorky22\ApiForge\Tests\Dev\Rest;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Rest\ResourceMaker;

final class ResourceMakerTest extends TestCase
{
    public function testDefaults(): void
    {
        $maker = new ResourceMaker();

        self::assertSame('license-keys', $maker->defaultPath('App\Api\LemonSqueezy\LicenseKeys'));
        self::assertSame('lemon_squeezy.client', $maker->defaultClient('LemonSqueezy\LicenseKeys'));
        self::assertSame('search.client', $maker->defaultClient('Search'));
        self::assertSame('Price', $maker->itemType('Paddle\Prices'));
        self::assertSame('Category', $maker->itemType('Categories'));
        self::assertSame('SearchItem', $maker->itemType('Kagi\Search'));
    }

    public function testFullResourceWithEnvelope(): void
    {
        $code = (new ResourceMaker())->make('App\Api\Unsplash\Collections', 'collections', 'unsplash.client', ['delete', 'list', 'get', 'create', 'update'], 'data', 'https://unsplash.com/documentation#collections');

        self::assertStringContainsString("namespace App\\Api\\Unsplash;\n", $code);
        self::assertStringContainsString(" * @see https://unsplash.com/documentation#collections\n", $code);
        self::assertStringContainsString(" * @implements Listable<array{data: list<Collection>, ...}>\n", $code);
        self::assertStringContainsString(" * @implements Updatable<CollectionChanges, array{data: Collection, ...}>\n", $code);
        self::assertStringContainsString('final class Collections extends AbstractRestResource implements Listable, Readable, Creatable, Updatable, Deletable', $code);
        self::assertStringContainsString("    use Creates;\n    use Deletes;\n    use Lists;\n    use Reads;\n    use Updates;\n", $code);
        self::assertStringContainsString("#[Autowire(service: 'unsplash.client')]", $code);
        self::assertStringContainsString("        return 'collections';\n", $code);
    }

    public function testNoAction(): void
    {
        $code = (new ResourceMaker())->make('Search', 'search', 'kagi.client', []);

        self::assertStringNotContainsString('namespace', $code);
        self::assertStringNotContainsString('@implements', $code);
        self::assertStringContainsString("final class Search extends AbstractRestResource\n{\n    public function __construct", $code);
    }

    public function testTheCodeIsValidPhp(): void
    {
        $code = (new ResourceMaker())->make('App\Api\Probe\Widgets', 'widgets', 'probe.client', array_keys(ResourceMaker::ACTIONS), 'data');
        $file = tempnam(sys_get_temp_dir(), 'api-forge-maker-');
        file_put_contents($file, $code);

        try {
            require $file;
        } finally {
            unlink($file);
        }

        self::assertTrue(class_exists('App\Api\Probe\Widgets', false));
    }

    public function testUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action(s): remove.');

        (new ResourceMaker())->make('Prices', 'prices', 'paddle.client', ['list', 'remove']);
    }
}
