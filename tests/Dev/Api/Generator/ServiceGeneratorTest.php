<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Generator;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Descriptor\ApiDefinition;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Descriptor\ShapeValidator;
use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates the services of a small JSON:API API, then runs them against a mock HTTP client.
 */
final class ServiceGeneratorTest extends TestCase
{
    private const CUSTOMERS = [
        'resource' => 'customers',
        'type' => 'customers',
        'object' => ['shape' => "array{\n    type: string,\n    id: string,\n    attributes: array{\n        name: string,\n    },\n}"],
        'operations' => [
            'list' => ['method' => 'GET', 'path' => 'customers', 'query' => ['filter[store_id]' => ['type' => 'int', 'example' => '1']], 'response' => ['data' => 'list']],
            'get' => ['method' => 'GET', 'path' => 'customers/{id}', 'parameters' => ['id' => []], 'response' => ['data' => 'object']],
            'create' => [
                'method' => 'POST',
                'path' => 'customers',
                'body' => ['attributes' => ['shape' => 'array{name?: string}'], 'relationships' => ['store' => 'stores']],
                'response' => ['data' => 'object'],
            ],
            'update' => [
                'method' => 'PATCH',
                'path' => 'customers/{id}',
                'parameters' => ['id' => []],
                'body' => ['attributes' => ['shape' => 'array{name?: string}']],
                'response' => ['data' => 'object'],
            ],
            'delete' => ['method' => 'DELETE', 'path' => 'customers/{id}', 'parameters' => ['id' => []]],
            'refund' => [
                'method' => 'POST',
                'path' => 'customers/{id}/refund',
                'parameters' => ['id' => []],
                'body' => ['attributes' => ['shape' => 'array{amount?: int}'], 'id' => true],
                'response' => ['data' => 'object'],
            ],
            'export' => [
                'method' => 'POST',
                'path' => 'customers/{id}/export',
                'parameters' => ['id' => []],
                'query' => ['format' => ['type' => 'string', 'required' => true]],
                'headers' => ['Accept' => 'text/csv'],
                'response' => ['shape' => 'array{url: string}'],
            ],
        ],
    ];

    private static ?array $files = null;

    /**
     * Generates the "gentest" API once, and loads its classes.
     *
     * @return array<string, string>
     */
    private static function generate(): array
    {
        if (null !== self::$files) {
            return self::$files;
        }

        $filesystem = new Filesystem();
        $dir = sys_get_temp_dir().'/service-generator-test-'.bin2hex(random_bytes(4));
        $filesystem->dumpFile($dir.'/config/api/gentest/_api.yaml', Yaml::dump([
            'base_uri' => 'https://api.test/v1/',
            'format' => 'jsonapi',
            'namespace' => 'GenTest',
            'headers' => ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json', 'X-Rate' => '100%'],
            'auth' => ['type' => 'bearer', 'env' => 'GENTEST_API_KEY'],
            'doctor' => ['probe' => 'customers.get', 'parameters' => ['id' => '%env(GENTEST_CUSTOMER_ID)%']],
        ], 10));
        $filesystem->dumpFile($dir.'/config/api/gentest/customers.yaml', Yaml::dump(self::CUSTOMERS, 10));
        $filesystem->dumpFile($dir.'/config/api/gentest/_webhooks.yaml', Yaml::dump([
            'signature' => ['header' => 'X-Signature', 'env' => 'GENTEST_WEBHOOK_SECRET'],
            'event_name' => ['path' => 'meta.event_name'],
            'meta' => ['shape' => 'array{event_name: string}'],
            'events' => [
                'customer_created' => ['resource' => 'customers', 'description' => 'A customer is created.'],
                'invoice.paid' => ['resource' => 'invoices'],
            ],
        ], 10));

        $definition = (new DescriptorLoader(new DescriptorStore($dir.'/config/api'), new ShapeValidator()))->load('gentest');
        self::$files = (new ServiceGenerator())->generate($definition, 'config/api/gentest');

        foreach (self::$files as $file => $content) {
            $filesystem->dumpFile($dir.'/'.$file, $content);
        }
        require_once $dir.'/src/Api/GenTest/Generated/Resource/Customers.php';
        require_once $dir.'/src/Api/GenTest/Generated/GenTestApi.php';
        require_once $dir.'/src/Api/GenTest/Generated/GenTestDoctor.php';
        foreach (['GenTestRequestParser', 'GenTestWebhookEvents', 'AbstractGenTestWebhookConsumer'] as $class) {
            require_once $dir.'/src/Api/GenTest/Generated/Webhook/'.$class.'.php';
        }
        $filesystem->remove($dir);

        return self::$files;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function customers(array $responses): object
    {
        self::generate();
        $client = ScopingHttpClient::forBaseUri(new MockHttpClient($responses), 'https://api.test/v1/', [
            'headers' => ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'],
        ]);

        return new \App\Api\GenTest\Generated\Resource\Customers($client);
    }

    public function testGeneratedFiles(): void
    {
        self::assertSame([
            'src/Api/GenTest/Generated/Resource/Customers.php',
            'src/Api/GenTest/Generated/GenTestApi.php',
            'src/Api/GenTest/Generated/GenTestDoctor.php',
            'src/Api/GenTest/Generated/Webhook/GenTestRequestParser.php',
            'src/Api/GenTest/Generated/Webhook/GenTestWebhookEvents.php',
            'src/Api/GenTest/Generated/Webhook/AbstractGenTestWebhookConsumer.php',
            'config/packages/api_gentest.yaml',
        ], array_keys(self::generate()));
    }

    public function testGenerationIsDeterministic(): void
    {
        $definition = new ApiDefinition('a', 'A', ['base_uri' => 'https://a.test/', 'format' => 'json', 'headers' => []], [], []);

        self::assertSame((new ServiceGenerator())->generate($definition, 'x'), (new ServiceGenerator())->generate($definition, 'x'));
    }

    public function testCustomNamespaceAndDirectories(): void
    {
        $definition = new ApiDefinition('shop', 'Shop', ['base_uri' => 'https://shop.test/', 'format' => 'json', 'headers' => []], [
            'orders' => ['resource' => 'orders', 'type' => null, 'operations' => []],
        ], []);
        $generator = new ServiceGenerator('Acme\\Integration', 'lib/Integration/', 'config/integrations');

        $files = $generator->generate($definition, 'config/api/shop');

        self::assertSame('lib/Integration/Shop/Generated', $generator->generatedDirectory($definition));
        self::assertSame([
            'lib/Integration/Shop/Generated/Resource/Orders.php',
            'lib/Integration/Shop/Generated/ShopApi.php',
            'lib/Integration/Shop/Generated/ShopDoctor.php',
            'config/integrations/api_shop.yaml',
        ], array_keys($files));
        self::assertStringContainsString("namespace Acme\\Integration\\Shop\\Generated\\Resource;\n", $files['lib/Integration/Shop/Generated/Resource/Orders.php']);
        self::assertStringContainsString("namespace Acme\\Integration\\Shop\\Generated;\n", $files['lib/Integration/Shop/Generated/ShopApi.php']);
    }

    public function testResourceClassDocumentation(): void
    {
        $code = self::generate()['src/Api/GenTest/Generated/Resource/Customers.php'];

        self::assertStringStartsWith("<?php\n\n// Generated by api-forge:generate from config/api/gentest/customers.yaml.\n", $code);
        self::assertStringContainsString(" * @phpstan-type Customer array{\n *     type: string,\n", $code);
        self::assertStringContainsString("     * @param ?int \$filterStoreId \"filter[store_id]\", e.g. \"1\"\n", $code);
        self::assertStringContainsString("     *     data: list<Customer>,\n", $code);
        self::assertStringContainsString("     * @return \\Generator<int, Customer>\n", $code);
        self::assertStringContainsString("     * @param array{store?: int|string|null} \$relationships related resource ids\n", $code);
        self::assertStringContainsString("     * @return array{url: string}\n", $code);
        self::assertStringContainsString("     * @return array<mixed>\n", $code);
        self::assertStringContainsString("#[Autowire(service: 'gentest.client')]", $code);
    }

    public function testClientConfig(): void
    {
        self::assertSame([
            'parameters' => ['env(GENTEST_API_KEY)' => '', 'env(GENTEST_WEBHOOK_SECRET)' => ''],
            'framework' => [
                'http_client' => ['scoped_clients' => ['gentest.client' => [
                    'base_uri' => 'https://api.test/v1/',
                    'auth_bearer' => '%env(GENTEST_API_KEY)%',
                    'headers' => ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json', 'X-Rate' => '100%%'],
                ]]],
                'webhook' => ['routing' => ['gentest' => [
                    'service' => 'App\\Api\\GenTest\\Generated\\Webhook\\GenTestRequestParser',
                    'secret' => '%env(GENTEST_WEBHOOK_SECRET)%',
                ]]],
            ],
        ], Yaml::parse(self::generate()['config/packages/api_gentest.yaml']));
    }

    public function testWebhookEventsDocumentation(): void
    {
        $code = self::generate()['src/Api/GenTest/Generated/Webhook/GenTestWebhookEvents.php'];

        self::assertStringContainsString(" * @phpstan-import-type Customer from \\App\\Api\\GenTest\\Generated\\Resource\\Customers\n", $code);
        self::assertStringContainsString(" * @phpstan-type CustomerCreatedPayload array{\n *     meta: WebhookMeta,\n *     data: Customer,\n * }\n", $code);
        // "invoices" is not described: its payload data is untyped
        self::assertStringContainsString(" * @phpstan-type InvoicePaidPayload array{\n *     meta: WebhookMeta,\n *     data: array<string, mixed>,\n * }\n", $code);
        self::assertSame(['customer_created', 'invoice.paid'], \App\Api\GenTest\Generated\Webhook\GenTestWebhookEvents::ALL);
        self::assertSame('invoice.paid', \App\Api\GenTest\Generated\Webhook\GenTestWebhookEvents::INVOICE_PAID);
    }

    public function testWebhookParserAndConsumer(): void
    {
        self::generate();
        $body = '{"meta": {"event_name": "customer_created"}, "data": {"type": "customers", "id": "1"}}';
        $request = new Request(server: ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 's3cret')], content: $body);

        $event = (new \App\Api\GenTest\Generated\Webhook\GenTestRequestParser())->parse($request, 's3cret');

        $consumer = new class extends \App\Api\GenTest\Generated\Webhook\AbstractGenTestWebhookConsumer {
            public array $received = [];

            protected function onCustomerCreated(array $payload, RemoteEvent $event): void
            {
                $this->received[] = $payload['data']['id'];
            }
        };
        $consumer->consume($event);
        $consumer->consume(new RemoteEvent('invoice.paid', '2', []));

        self::assertSame(['1'], $consumer->received);
    }

    public function testEntryPoint(): void
    {
        $customers = $this->customers([]);
        $api = new \App\Api\GenTest\Generated\GenTestApi($customers);

        self::assertSame($customers, $api->customers);
    }

    public function testList(): void
    {
        $response = new JsonMockResponse(['data' => [['type' => 'customers', 'id' => '1']]]);

        $document = $this->customers([$response])->list(1, ['page[size]' => 10]);

        self::assertSame('1', $document['data'][0]['id']);
        self::assertSame('https://api.test/v1/customers?page[size]=10&filter[store_id]=1', $response->getRequestUrl());
    }

    public function testIterate(): void
    {
        $customers = $this->customers([
            new JsonMockResponse(['data' => [['id' => '1']], 'links' => ['next' => 'https://api.test/v1/customers?page=2']]),
            new JsonMockResponse(['data' => [['id' => '2']]]),
        ]);

        self::assertSame(['1', '2'], array_column(iterator_to_array($customers->iterate()), 'id'));
    }

    public function testCreate(): void
    {
        $response = new JsonMockResponse(['data' => ['type' => 'customers', 'id' => '7']], ['http_code' => 201]);

        $this->customers([$response])->create(['name' => 'John'], ['store' => 1]);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(
            ['data' => ['type' => 'customers', 'attributes' => ['name' => 'John'], 'relationships' => ['store' => ['data' => ['type' => 'stores', 'id' => '1']]]]],
            json_decode($response->getRequestOptions()['body'], true),
        );
    }

    public function testUpdateIdentifiesTheResource(): void
    {
        $response = new JsonMockResponse(['data' => ['type' => 'customers', 'id' => '7']]);

        $this->customers([$response])->update(7, ['name' => 'Jane']);

        self::assertSame('https://api.test/v1/customers/7', $response->getRequestUrl());
        self::assertSame(['data' => ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Jane']]], json_decode($response->getRequestOptions()['body'], true));
    }

    public function testActionDocumentIdentifiesTheResource(): void
    {
        $response = new JsonMockResponse(['data' => ['type' => 'customers', 'id' => '7']]);

        $this->customers([$response])->refund(7, ['amount' => 100]);

        self::assertSame(['POST', 'https://api.test/v1/customers/7/refund'], [$response->getRequestMethod(), $response->getRequestUrl()]);
        self::assertSame(['data' => ['type' => 'customers', 'id' => '7', 'attributes' => ['amount' => 100]]], json_decode($response->getRequestOptions()['body'], true));
    }

    public function testDoctor(): void
    {
        $doctor = self::generate()['src/Api/GenTest/Generated/GenTestDoctor.php'];
        self::assertStringContainsString("    protected const ENV = [\n        'GENTEST_API_KEY' => 'credentials',\n        'GENTEST_WEBHOOK_SECRET' => 'webhook signing secret',\n    ];\n", $doctor);
        self::assertStringContainsString("    protected const PROBE = 'customers.get';\n", $doctor);
        self::assertStringContainsString("        #[Autowire('%env(GENTEST_CUSTOMER_ID)%')] private readonly string \$probeId,\n", $doctor);
        self::assertStringContainsString("        \$this->probeResource->get(\$this->probeId);\n", $doctor);

        $diagnose = static function (MockResponse $response, string $key = 'key'): array {
            $client = ScopingHttpClient::forBaseUri(new MockHttpClient([$response]), 'https://api.test/v1/');
            $doctor = new \App\Api\GenTest\Generated\GenTestDoctor(new \App\Api\GenTest\Generated\Resource\Customers($client), env0: $key, env1: 'secret', probeId: '7');

            return array_map(static fn ($check) => $check->level.': '.$check->message, $doctor->diagnose());
        };

        $checks = $diagnose(new JsonMockResponse(['data' => ['type' => 'customers', 'id' => '7']]));
        self::assertSame('ok: GENTEST_API_KEY (credentials) is set.', $checks[0]);
        self::assertStringStartsWith('ok: Probe customers.get: the API answers and accepts the credentials (', $checks[2]);

        $checks = $diagnose(new JsonMockResponse(['errors' => [['detail' => 'Unauthenticated.']]], ['http_code' => 401]), '');
        self::assertSame('error: GENTEST_API_KEY (credentials) is empty: set it in .env.local.', $checks[0]);
        self::assertSame('error: Probe customers.get: the API rejects the credentials (HTTP 401): check GENTEST_API_KEY.', $checks[2]);

        self::assertStringContainsString('wrong base URI or API version (https://api.test/v1/)', $diagnose(new JsonMockResponse([], ['http_code' => 404]))[2]);
        self::assertStringContainsString('the provider may be down', $diagnose(new JsonMockResponse([], ['http_code' => 503]))[2]);
        self::assertStringContainsString('is unreachable (DNS, TLS, network, timeout): Could not resolve host', $diagnose(new MockResponse('', ['error' => 'Could not resolve host']))[2]);
    }

    public function testDelete(): void
    {
        $response = new MockResponse('', ['http_code' => 204]);

        self::assertSame([], $this->customers([$response])->delete('7'));
        self::assertSame('DELETE', $response->getRequestMethod());
    }

    public function testActionWithRequiredQueryAndHeader(): void
    {
        $response = new JsonMockResponse(['url' => 'https://x.test/file.csv']);

        self::assertSame(['url' => 'https://x.test/file.csv'], $this->customers([$response])->export(7, 'csv'));
        self::assertSame('https://api.test/v1/customers/7/export?format=csv', $response->getRequestUrl());
        self::assertContains('Accept: text/csv', $response->getRequestOptions()['headers']);
    }
}
