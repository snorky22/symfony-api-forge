<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Web\CommandRunner;
use Snorky22\ApiForge\Web\Request;
use Snorky22\ApiForge\Web\Response;
use Snorky22\ApiForge\Web\WebApp;
use Snorky22\ApiForge\Web\WebConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class WebAppTest extends TestCase
{
    private const HOST = '127.0.0.1:8765';

    private string $project;
    private WebApp $app;
    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/api-forge-web-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->project.'/config/api');

        $runner = new class($this->commands) extends CommandRunner {
            public function __construct(private array &$commands)
            {
            }

            public function run(array $command, string $cwd, int $timeout = 60): array
            {
                $this->commands[] = $command;

                return [0, "Probe prices.list: the API answers\n"];
            }
        };
        $this->app = new WebApp(new WebConfig($this->project, $this->project.'/config/api', 'App\\Api', 'src/Api', 'config/packages', ['php', 'bin/console'], 'secret', [self::HOST]), $runner);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    private function get(string $path, array $query = [], string $host = self::HOST): Response
    {
        return $this->app->handle(new Request('GET', $path, $query, [], $host));
    }

    private function post(string $path, array $data = [], ?string $token = null): Response
    {
        return $this->app->handle(new Request('POST', $path, [], $data + ['_token' => $token ?? $this->app->token()], self::HOST));
    }

    private function json(string $method, string $path, array $data = []): Response
    {
        return $this->app->handle(new Request($method, $path, 'GET' === $method ? $data : [], 'POST' === $method ? $data + ['_token' => $this->app->token()] : [], self::HOST, 'application/json'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function createApi(): void
    {
        $response = $this->post('/new', ['name' => 'shop', 'base_uri' => 'https://api.shop.test/', 'sandbox_uri' => 'https://sandbox.shop.test/', 'format' => 'json', 'auth_type' => 'bearer', 'auth_env' => 'SHOP_API_KEY', 'headers' => "Shop-Version: 1\n"]);
        self::assertSame(303, $response->status);
    }

    private function example(array $data = []): Response
    {
        return $this->post('/api/shop/example', $data + ['operation' => 'list', 'resource' => 'prices', 'request_format' => 'curl', 'request' => "curl https://api.shop.test/prices -H 'Authorization: Bearer X'", 'response_format' => 'json', 'response' => '{"data": [{"id": "p1", "name": null}]}']);
    }

    public function testRefusesAForeignHostAndAMissingToken(): void
    {
        self::assertSame(403, $this->get('/', [], 'evil.test')->status);
        self::assertSame(403, $this->post('/new', [], 'wrong')->status);
        self::assertStringContainsString('token', $this->post('/new', [], 'wrong')->body);
        self::assertSame(200, $this->get('/')->status);
    }

    public function testHomeInvitesToDescribeAnApi(): void
    {
        $response = $this->get('/');

        self::assertStringContainsString('No API yet', $response->body);
        self::assertStringContainsString('/assets/bootstrap.min.css', $response->body);
        self::assertSame(404, $this->get('/api/shop')->status);
        self::assertSame(404, $this->get('/nowhere')->status);
    }

    public function testDescribesAnApi(): void
    {
        $this->createApi();

        $api = Yaml::parseFile($this->project.'/config/api/shop/_api.yaml');
        self::assertSame('https://api.shop.test/', $api['base_uri']);
        self::assertSame(['sandbox' => 'https://sandbox.shop.test/'], $api['environments']);
        self::assertSame('SHOP_BASE_URI', $api['base_uri_env']);
        self::assertSame(['type' => 'bearer', 'env' => 'SHOP_API_KEY'], $api['auth']);
        self::assertSame(['Shop-Version' => '1'], $api['headers']);

        $dashboard = $this->get('/api/shop')->body;
        self::assertStringContainsString('Describe the API', $dashboard);
        self::assertStringContainsString('list-group-item-warning', $dashboard);
    }

    public function testChecksTheApiForm(): void
    {
        $response = $this->post('/new', ['name' => 'Bad Name', 'base_uri' => 'not a url', 'auth_env' => 'lower', 'auth_type' => 'bearer', 'format' => 'json', 'headers' => 'no colon']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('alert-danger', $response->body);
        self::assertStringContainsString('lowercase letters', $response->body);
        self::assertStringContainsString('write &quot;Name: value&quot;', $response->body);
        self::assertDirectoryDoesNotExist($this->project.'/config/api/bad name');

        $this->createApi();
        self::assertStringContainsString('exists already', $this->post('/new', ['name' => 'shop', 'base_uri' => 'https://x.test/', 'format' => 'json', 'auth_type' => 'bearer'])->body);
    }

    public function testAddsAnExampleAndImportsIt(): void
    {
        $this->createApi();

        $response = $this->example();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('prices.list', $response->body);
        self::assertStringContainsString('GET prices', $response->body);
        self::assertStringNotContainsString('are named', $response->body);
        self::assertFileExists($this->project.'/tests/Fixtures/shop/list-prices.curl');
        self::assertFileExists($this->project.'/tests/Fixtures/shop/list-prices.json');
        self::assertSame('GET', Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']['list']['method']);
        self::assertStringContainsString('note(s) to review', $response->body);
        self::assertStringContainsString('name', $response->body);

        $dashboard = $this->get('/api/shop')->body;
        self::assertStringContainsString('list-prices.json', $dashboard);
        self::assertStringContainsString('1 operation(s)', $dashboard);
    }

    public function testWarnsWhenTheNameIsNotTheOperationOfTheRequest(): void
    {
        $this->createApi();

        $response = $this->example(['request' => 'curl https://api.shop.test/prices/12', 'response' => '{"data": {"id": "p"}}']);

        self::assertStringContainsString('The files are named', $response->body);
        self::assertStringContainsString('<strong>get</strong> operation', $response->body);
    }

    public function testAddsAnArrayExampleWithItsQueryAndDocsPage(): void
    {
        $this->createApi();

        $response = $this->example([
            'operation' => 'get', 'resource' => 'prices', 'variant' => '', 'request_format' => 'array', 'response' => '',
            'request' => "['request' => 'GET /prices/{price_id}', 'query' => ['include' => 'product'], 'response' => ['data' => ['id' => 'p1', 'name' => null]]]",
            'docs' => "dataobjectrequired\n\nidstringrequired\n\nnamestring | nullrequired\n",
        ]);

        self::assertStringContainsString('prices.get', $response->body);
        $php = (string) file_get_contents($this->project.'/tests/Fixtures/shop/get-prices.php');
        self::assertStringStartsWith("<?php\n\nreturn ['request'", $php);
        self::assertFileExists($this->project.'/tests/Fixtures/shop/get-prices.docs.md');
        $operation = Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']['get'];
        self::assertSame('prices/{priceId}', $operation['path']);
        self::assertSame('product', $operation['query']['include']['example']);
        self::assertStringContainsString('name: ?string', $operation['response']['shape']);
    }

    public function testAnArrayExampleDoesNotTakeAResponseBox(): void
    {
        $this->createApi();

        $response = $this->example(['request_format' => 'array', 'request' => "['request' => 'GET /prices']", 'response' => '{"a": 1}']);

        self::assertStringContainsString('leave the response box empty', $response->body);
        self::assertFileDoesNotExist($this->project.'/tests/Fixtures/shop/list-prices.php');
    }

    public function testChecksWhatIsPastedBeforeWritingAnything(): void
    {
        $this->createApi();

        $json = $this->example(['response' => "{\n  \"a\": 'x'\n}"]);
        self::assertStringContainsString('line 2: a single-quoted string', $json->body);

        $code = $this->example(['request_format' => 'array', 'response' => '', 'request' => "['request' => phpinfo()]"]);
        self::assertStringContainsString('only literal data is accepted', $code->body);

        $names = $this->example(['operation' => '../etc', 'resource' => 'Prices']);
        self::assertStringContainsString('lowercase letters', $names->body);

        self::assertStringContainsString('Paste the request', $this->example(['request' => ''])->body);
        self::assertFileDoesNotExist($this->project.'/tests/Fixtures/shop/list-prices.curl');
        self::assertFileDoesNotExist($this->project.'/tests/Fixtures/etc');
    }

    public function testDoesNotReplaceFilesWithoutBeingAsked(): void
    {
        $this->createApi();
        $this->example();

        $again = $this->example(['response' => '{"data": [{"id": "p1", "name": "x"}]}']);
        self::assertStringContainsString('exists and differs', $again->body);
        self::assertStringContainsString('null', (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));

        $replaced = $this->example(['response' => '{"data": [{"id": "p1", "name": "x"}]}', 'overwrite' => '1', 'force' => '1']);
        self::assertStringContainsString('prices.list', $replaced->body);
        self::assertStringContainsString('"name": "x"', (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));
    }

    public function testEditsAnExampleAndImportsItAgain(): void
    {
        $this->createApi();
        $this->example();

        self::assertStringContainsString('&quot;name&quot;: null', $this->get('/api/shop/file', ['name' => 'list-prices.json'])->body);

        $saved = $this->post('/api/shop/file', ['name' => 'list-prices.json', 'content' => '{"data": [{"id": "p1", "name": "Pro"}]}']);
        self::assertStringContainsString('Saved.', $saved->body);

        $bad = $this->post('/api/shop/file', ['name' => 'list-prices.json', 'content' => '{"data": ']);
        self::assertStringContainsString('Not JSON', $bad->body);
        self::assertStringContainsString('"name": "Pro"', (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));

        $imported = $this->post('/api/shop/import', ['name' => 'list-prices.curl', 'force' => '1']);
        self::assertStringContainsString('prices.list', $imported->body);
        self::assertStringContainsString('name: string,', Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']['list']['response']['shape']);
    }

    public function testOnlyExamplesOfTheApiCanBeOpened(): void
    {
        $this->createApi();
        $this->example();
        file_put_contents($this->project.'/secret.json', '{"secret": 1}');

        foreach (['../../secret.json', '../secret.json', 'nothing.json', '/etc/passwd', 'a/b.json', '.hidden.json'] as $name) {
            self::assertSame(404, $this->get('/api/shop/file', ['name' => $name])->status, $name);
            self::assertSame(404, $this->post('/api/shop/file', ['name' => $name, 'content' => 'x'])->status, $name);
        }
        self::assertSame('{"secret": 1}', file_get_contents($this->project.'/secret.json'));
    }

    public function testAddsATypeFile(): void
    {
        $this->createApi();

        $response = $this->post('/api/shop/type', ['name' => 'duration', 'format' => 'json', 'content' => '{"interval": "month", "frequency": 1}']);

        self::assertStringContainsString('duration-type.json', $response->body);
        self::assertFileExists($this->project.'/tests/Fixtures/shop/duration-type.json');

        $array = $this->post('/api/shop/type', ['name' => 'money', 'format' => 'array', 'content' => "['amount' => '1', 'currency_code' => 'EUR']"]);
        self::assertStringContainsString('money-type.array', $array->body);

        $bad = $this->post('/api/shop/type', ['name' => 'x', 'format' => 'json', 'content' => '[']);
        self::assertStringContainsString('alert-danger', $bad->body);
    }

    public function testReviewsAndDeletesNotes(): void
    {
        $this->createApi();
        $this->example();

        $notes = $this->get('/api/shop/notes')->body;
        self::assertStringContainsString('name', $notes);
        self::assertStringContainsString('Complete the example', $notes);

        preg_match_all('/name="notes\[\]" id="n\d+" value="([^"]+)"/', $notes, $matches);
        self::assertNotEmpty($matches[1]);
        $before = count($matches[1]);
        $deleted = $this->post('/api/shop/notes', ['notes' => array_map('html_entity_decode', [$matches[1][0]])]);

        self::assertStringContainsString('1 note(s) deleted.', $deleted->body);
        preg_match_all('/name="notes\[\]"/', $deleted->body, $left);
        self::assertCount($before - 1, $left[0]);
    }

    public function testGeneratesTheCode(): void
    {
        $this->createApi();
        $this->example();

        self::assertStringContainsString('out of date', $this->get('/api/shop/generate')->body);

        $response = $this->post('/api/shop/generate');

        self::assertStringContainsString('file(s) changed', $response->body);
        self::assertFileExists($this->project.'/src/Api/Shop/Generated/ShopApi.php');
        self::assertStringContainsString('src/Api/Shop/Generated/Resource/Prices.php', $response->body);
        self::assertStringContainsString('up to date', $this->post('/api/shop/generate')->body);

        $dashboard = $this->get('/api/shop')->body;
        self::assertStringContainsString('Generate the code', $dashboard);
    }

    public function testRunsTheDoctorThroughTheConsole(): void
    {
        $this->createApi();

        self::assertStringContainsString('Run the doctor', $this->get('/api/shop/doctor')->body);
        $response = $this->post('/api/shop/doctor');

        self::assertStringContainsString('The doctor found no problem.', $response->body);
        self::assertStringContainsString('Probe prices.list: the API answers', $response->body);
        self::assertSame(['php', 'bin/console', 'api-forge:doctor', 'shop', '--no-ansi', '--no-interaction'], $this->commands[0]);
    }

    public function testEscapesWhatItShows(): void
    {
        $this->createApi();
        $response = $this->example(['request' => "curl https://api.shop.test/prices/<script>alert(1)</script>", 'response' => '{"x": "<b>"}']);

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
    }

    public function testTheTokenIsPerRun(): void
    {
        $other = new WebApp(new WebConfig($this->project, $this->project.'/config/api', 'App\\Api', 'src/Api', 'config/packages', ['php'], 'another-secret', [self::HOST]));

        self::assertNotSame($this->app->token(), $other->token());
        self::assertSame($this->app->token(), $this->app->token());
    }

    public function testTheEditorReadsAndSavesThroughJson(): void
    {
        $this->createApi();
        $this->example();

        $read = $this->json('GET', '/api/shop/file', ['name' => 'list-prices.json', 'area' => 'examples']);
        self::assertSame('application/json', $read->headers['Content-Type']);
        $data = $this->decode($read);
        self::assertTrue($data['ok']);
        self::assertSame('json', $data['mode']);
        self::assertStringContainsString('"name": null', $data['content']);

        $saved = $this->json('POST', '/api/shop/file', ['name' => 'list-prices.json', 'area' => 'examples', 'content' => '{"data": [{"id": "p1"}]}']);
        self::assertSame(200, $saved->status);
        self::assertSame(['ok' => true, 'message' => 'Saved.', 'warnings' => []], $this->decode($saved));
        self::assertSame("{\"data\": [{\"id\": \"p1\"}]}\n", file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));

        $refused = $this->json('POST', '/api/shop/file', ['name' => 'list-prices.json', 'area' => 'examples', 'content' => "{\n  \"a\": 'x'\n}"]);
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('line 2: a single-quoted string', $this->decode($refused)['errors'][0]);
        self::assertStringContainsString('"id": "p1"', (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));

        $unknown = $this->json('POST', '/api/shop/file', ['name' => 'nothing.json', 'area' => 'examples', 'content' => '{}']);
        self::assertSame(404, $unknown->status);
        self::assertFalse($this->decode($unknown)['ok']);
    }

    public function testTheEditorOpensAsAFragmentForThePage(): void
    {
        $this->createApi();
        $this->example(['docs' => "dataarrayrequired\n\nidstringrequired\n"]);

        $fragment = $this->get('/api/shop/file', ['name' => 'list-prices.docs.md', 'area' => 'examples', 'partial' => '1', 'line' => '3']);

        self::assertSame(200, $fragment->status);
        self::assertStringNotContainsString('<html', $fragment->body);
        self::assertStringContainsString('data-af-panel', $fragment->body);
        self::assertStringContainsString('data-line="3"', $fragment->body);
        self::assertStringContainsString('data-mode="markdown"', $fragment->body);
        // Inserted in a page that may be a form (the notes): no nested form
        self::assertStringNotContainsString('<form', $fragment->body);

        $page = $this->get('/api/shop/file', ['name' => 'list-prices.docs.md'])->body;
        self::assertStringContainsString('<form method="post" action="/api/shop/file"', $page);
        self::assertStringContainsString('<button class="btn btn-primary btn-sm">Save</button>', $page);
    }

    public function testEditsDescriptorsToo(): void
    {
        $this->createApi();
        $this->example();

        $read = $this->decode($this->json('GET', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors']));
        self::assertSame('yaml', $read['mode']);
        self::assertStringContainsString('resource: prices', $read['content']);

        $content = str_replace('required: false', 'required: true', $read['content']);
        $ok = $this->json('POST', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors', 'content' => $content]);
        self::assertTrue($this->decode($ok)['ok']);

        $bad = $this->json('POST', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors', 'content' => "resource: [prices\n"]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Not YAML', $this->decode($bad)['errors'][0]);

        // Saved, but the loader finds it invalid: a warning, not a refusal
        $warned = $this->decode($this->json('POST', '/api/shop/file', ['name' => '_api.yaml', 'area' => 'descriptors', 'content' => "format: json\n"]));
        self::assertTrue($warned['ok']);
        self::assertNotEmpty($warned['warnings']);

        foreach (['../shop/prices.yaml', 'prices.php', 'nothing.yaml', 'a/b.yaml'] as $name) {
            self::assertSame(404, $this->json('GET', '/api/shop/file', ['name' => $name, 'area' => 'descriptors'])->status, $name);
        }
        $this->get('/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors']);
        self::assertSame(404, $this->json('GET', '/api/shop/file', ['name' => 'list-prices.json', 'area' => 'descriptors'])->status);
    }

    public function testNotesLinkToTheFilesToEdit(): void
    {
        $this->createApi();
        $this->example(['response' => '{"data": [{"id": "p1", "name": null}]}', 'docs' => "dataarrayrequired\n\nidstringrequired\n\nnamestring | nullrequired\n\nsurprisestringrequired\n"]);

        $notes = $this->get('/api/shop/notes')->body;

        self::assertStringContainsString('href="/api/shop/file?name=prices.yaml&amp;area=descriptors"', $notes);
        self::assertStringContainsString('href="/api/shop/file?name=list-prices.json&amp;area=examples"', $notes);
        self::assertMatchesRegularExpression('#name=list-prices\.docs\.md&amp;area=examples&amp;line=\d+"#', $notes);
        self::assertStringContainsString('data-af-open', $notes);
    }

    public function testPagesWithAnEditorLoadAceAndOthersDoNot(): void
    {
        $this->createApi();

        self::assertStringNotContainsString('ace.js', $this->get('/')->body);
        $example = $this->get('/api/shop/example')->body;
        self::assertStringContainsString('/assets/ace/ace.js', $example);
        self::assertStringContainsString('/assets/editor.js', $example);
        self::assertStringContainsString('data-af-code="sh" data-af-mode-from="request_format"', $example);
        self::assertStringContainsString('<textarea', $example);
    }

    public function testTheEditorAssetsAreShipped(): void
    {
        $public = __DIR__.'/../../src/Web/public/assets';

        foreach (['editor.js', 'editor.css', 'bootstrap.min.css', 'ace/ace.js', 'ace/theme-github.js', 'ace/theme-cobalt.js', 'ace/mode-json.js', 'ace/mode-php.js', 'ace/mode-markdown.js', 'ace/mode-sh.js', 'ace/mode-yaml.js', 'ace/worker-json.js', 'ace/worker-yaml.js', 'ace/ext-searchbox.js', 'ace/ext-settings_menu.js', 'ace/ext-whitespace.js', 'ace/LICENSE-ace.txt'] as $file) {
            self::assertFileExists($public.'/'.$file);
        }
    }
}
