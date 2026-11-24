<?php

namespace Snorky22\ApiForge\Tests\Dev\Rest;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Rest\ResourceMaker;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

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

    public function testAddingActionsGivesWhatMakeWrites(): void
    {
        $maker = new ResourceMaker();
        $code = $maker->make('App\API\Paddle\Prices', 'prices', 'paddle.client', ['list'], 'data');

        self::assertSame(['list'], $maker->implementedActions($code));
        self::assertSame('data', $maker->envelopeOf($code));
        self::assertSame(
            $maker->make('App\API\Paddle\Prices', 'prices', 'paddle.client', ['list', 'get', 'create', 'update', 'delete'], 'data'),
            $maker->addActions($code, 'App\API\Paddle\Prices', ['delete', 'update', 'get', 'create'], 'data'),
        );
    }

    public function testAddingToAClassWithoutAction(): void
    {
        $maker = new ResourceMaker();
        $code = $maker->make('App\API\Kagi\Search', 'search', 'kagi.client', []);

        self::assertSame([], $maker->implementedActions($code));
        self::assertFalse($maker->envelopeOf($code));
        self::assertSame($maker->make('App\API\Kagi\Search', 'search', 'kagi.client', ['get']), $maker->addActions($code, 'App\API\Kagi\Search', ['get']));
    }

    public function testAddingKeepsWhatWasWrittenByHand(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App\API\Paddle;

            use App\Contract\Sellable;
            use Snorky22\ApiForge\Core\Rest\AbstractRestResource;
            use Snorky22\ApiForge\Core\Rest\Readable;
            use Snorky22\ApiForge\Core\Rest\Reads;

            /**
             * @phpstan-type Price array{
             *     id: string,
             *     status: string,
             *     ...
             * }
             *
             * @implements Readable<Price>
             */
            final class Prices extends AbstractRestResource implements Readable, Sellable
            {
                use Reads;

                protected function path(): string
                {
                    return 'prices';
                }
            }

            PHP;
        $maker = new ResourceMaker();

        self::assertNull($maker->envelopeOf($code));
        $added = $maker->addActions($code, 'App\API\Paddle\Prices', ['list', 'get']);

        self::assertStringContainsString("use App\\Contract\\Sellable;\nuse Snorky22\\ApiForge\\Core\\Rest\\AbstractRestResource;\nuse Snorky22\\ApiForge\\Core\\Rest\\Listable;\nuse Snorky22\\ApiForge\\Core\\Rest\\Lists;\n", $added);
        self::assertStringContainsString(" *     ...\n * }\n *\n * @implements Readable<Price>\n * @implements Listable<list<Price>>\n */", $added);
        self::assertStringContainsString('final class Prices extends AbstractRestResource implements Listable, Readable, Sellable', $added);
        self::assertStringContainsString("    use Lists;\n    use Reads;\n\n    protected function path()", $added);
    }

    public function testAddingNeedsAnAbstractRestResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not extend AbstractRestResource');

        (new ResourceMaker())->addActions("<?php\n\nfinal class Search extends AbstractResource\n{\n}\n", 'Search', ['list']);
    }

    public function testExistingNamesToSuggest(): void
    {
        $dir = sys_get_temp_dir().'/api-forge-names-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($dir.'/Paddle/Prices.php', '<?php');
        (new Filesystem())->dumpFile($dir.'/Paddle/Products.php', '<?php');
        (new Filesystem())->dumpFile($dir.'/Kagi/Search.php', '<?php');
        (new Filesystem())->dumpFile($dir.'/Paddle/notes.txt', '');

        try {
            self::assertSame(['Kagi\\', 'Kagi\\Search', 'Paddle\\', 'Paddle\\Prices', 'Paddle\\Products'], (new ResourceMaker())->existingNames($dir));
            self::assertSame([], (new ResourceMaker())->existingNames($dir.'/none'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testEndpoints(): void
    {
        $maker = new ResourceMaker();

        self::assertSame('https://api.paddle.com/prices', $maker->normalizeEndpoint(' api.paddle.com/prices '));
        self::assertSame('https://api.paddle.com/prices', $maker->normalizeEndpoint('https://api.paddle.com/prices/{price_id}?include=product#x'));
        self::assertSame('https://api.unsplash.com/collections', $maker->normalizeEndpoint('https://api.unsplash.com/collections/:id'));
        self::assertSame('https://api.example.com:8443/v1/license-keys', $maker->normalizeEndpoint('HTTPS://API.Example.com:8443/v1/license-keys/'));
        self::assertSame('prices', $maker->pathOf('https://api.paddle.com/prices'));
        self::assertSame('license-keys', $maker->pathOf('https://api.example.com/v1/license-keys'));
        self::assertNull($maker->pathOf('https://kagi.com'));
        self::assertSame('https://api.example.com/v1/', $maker->baseUri('https://api.example.com/v1/license-keys', 'license-keys'));
        self::assertSame('https://api.example.com/', $maker->baseUri('https://api.example.com/v1/license-keys', 'v1/license-keys'));
        self::assertNull($maker->baseUri('https://api.example.com/v1/license-keys', 'keys'));
        self::assertSame('LEMON_SQUEEZY_API_KEY', $maker->envVariable('lemon_squeezy.client'));
    }

    public function testTheEndpointRecordedInTheClass(): void
    {
        $maker = new ResourceMaker();
        $code = $maker->make('App\API\Paddle\Prices', 'prices', 'paddle.client', ['list'], 'data', 'https://developer.paddle.com/api-reference/prices');

        self::assertNull($maker->endpointOf($code));
        self::assertSame('prices', $maker->pathIn($code));
        self::assertSame('paddle.client', $maker->clientIn($code));
        $with = $maker->withEndpoint($code, 'https://api.paddle.com/prices');
        self::assertSame($maker->make('App\API\Paddle\Prices', 'prices', 'paddle.client', ['list'], 'data', 'https://developer.paddle.com/api-reference/prices', 'https://api.paddle.com/prices'), $with);
        self::assertSame('https://api.paddle.com/prices', $maker->endpointOf($with));
        self::assertSame('https://sandbox-api.paddle.com/prices', $maker->endpointOf($maker->withEndpoint($with, 'https://sandbox-api.paddle.com/prices')));
        self::assertSame("<?php\n\n/**\n * Endpoint: https://x.test/a\n */\nfinal class A extends AbstractRestResource\n{\n}\n", $maker->withEndpoint("<?php\n\nfinal class A extends AbstractRestResource\n{\n}\n", 'https://x.test/a'));
    }

    public function testClientConfiguration(): void
    {
        $maker = new ResourceMaker();
        $placeholder = $maker->clientConfiguration('lemon_squeezy.client', null);

        self::assertSame('lemon_squeezy_client.yaml', $maker->clientFile('lemon_squeezy.client'));
        self::assertSame([
            'parameters' => ['env(LEMON_SQUEEZY_BASE_URI)' => 'https://api.example.com/', 'env(LEMON_SQUEEZY_API_KEY)' => ''],
            'framework' => ['http_client' => ['scoped_clients' => ['lemon_squeezy.client' => [
                'base_uri' => '%env(LEMON_SQUEEZY_BASE_URI)%',
                'auth_bearer' => '%env(LEMON_SQUEEZY_API_KEY)%',
            ]]]],
        ], Yaml::parse($placeholder));
        self::assertStringContainsString('# TODO: the base URI of the API', $placeholder);
        $filled = (string) $maker->withBaseUri($placeholder, 'https://api.lemonsqueezy.com/v1/');
        self::assertSame($maker->clientConfiguration('lemon_squeezy.client', 'https://api.lemonsqueezy.com/v1/'), $filled);
        self::assertNull($maker->withBaseUri($filled, 'https://elsewhere.test/'));
    }

    public function testTheFileDeclaringAClient(): void
    {
        $dir = sys_get_temp_dir().'/api-forge-clients-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($dir.'/framework.yaml', "framework:\n    http_client:\n        scoped_clients:\n            'kagi.client':  # search\n                base_uri: 'https://kagi.com/api/v0/'\n");
        (new Filesystem())->dumpFile($dir.'/prod/paddle.yml', "framework:\n    http_client:\n        scoped_clients:\n            paddle.client:\n                base_uri: x\n");
        $maker = new ResourceMaker();

        try {
            self::assertSame($dir.'/framework.yaml', $maker->declaringFile($dir, 'kagi.client'));
            // config/packages/prod/: that environment only, not a declaration of the client
            self::assertNull($maker->declaringFile($dir, 'paddle.client'));
            self::assertNull($maker->declaringFile($dir, 'unsplash.client'));
            self::assertNull($maker->declaringFile($dir.'/none', 'kagi.client'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testAnEndpointNeedsAHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ResourceMaker())->normalizeEndpoint('prices');
    }

    public function testUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action(s): remove.');

        (new ResourceMaker())->make('Prices', 'prices', 'paddle.client', ['list', 'remove']);
    }
}
