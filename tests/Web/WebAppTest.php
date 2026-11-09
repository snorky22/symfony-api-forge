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

        self::assertStringContainsString('leave their boxes empty', $response->body);
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
        self::assertStringContainsString('Use Fix below', $notes);

        preg_match_all('/name="notes\[\]" id="n\d+" value="([^"]+)"/', $notes, $matches);
        self::assertNotEmpty($matches[1]);
        $before = count($matches[1]);
        $deleted = $this->post('/api/shop/notes', ['notes' => array_map('html_entity_decode', [$matches[1][0]])]);

        self::assertStringContainsString('1 note(s) deleted.', $deleted->body);
        preg_match_all('/type="checkbox" name="notes\[\]"/', $deleted->body, $left);
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
        self::assertStringContainsString('data-af-notes', $notes);
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

    public function testTheDoctorPageSaysWhatItRunsAndNeedsNoCheckbox(): void
    {
        $this->createApi();

        $page = $this->get('/api/shop/doctor')->body;

        self::assertStringContainsString('<code>php bin/console api-forge:doctor shop</code>', $page);
        self::assertStringContainsString('<code>SHOP_API_KEY</code>', $page);
        self::assertStringContainsString('<code>SHOP_BASE_URI</code>', $page);
        self::assertStringNotContainsString('type="checkbox"', $page);
        self::assertStringContainsString('Run the doctor', $page);
    }

    public function testTheGeneratePageSaysWhatItRuns(): void
    {
        $this->createApi();

        self::assertStringContainsString('<code>php bin/console api-forge:generate shop</code>', $this->get('/api/shop/generate')->body);
    }

    public function testTheEditorKnowsWhichExampleToImportWhenAFileIsSaved(): void
    {
        $this->createApi();
        $this->example(['docs' => "dataarrayrequired\n\nidstringrequired\n"]);
        $this->post('/api/shop/type', ['name' => 'money', 'format' => 'json', 'content' => '{"amount": "1"}']);

        $target = static fn (string $html): string => 1 === preg_match('/data-import="([^"]*)"/', $html, $m) ? $m[1] : 'none';
        $panel = fn (string $name) => $this->get('/api/shop/file', ['name' => $name, 'area' => 'examples', 'partial' => '1'])->body;

        self::assertSame('list-prices.curl', $target($panel('list-prices.curl')));
        self::assertSame('list-prices.curl', $target($panel('list-prices.json')));
        self::assertSame('list-prices.curl', $target($panel('list-prices.docs.md')));
        self::assertSame('', $target($panel('money-type.json')));
        self::assertSame('', $target($this->get('/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors', 'partial' => '1'])->body));
    }

    public function testImportsAnExampleAgainThroughJsonWithItsVariants(): void
    {
        $this->createApi();
        $this->example();
        $this->example(['variant' => 'include', 'request_format' => 'array', 'response' => '', 'request' => "['request' => 'GET /prices', 'query' => ['include' => 'product']]"]);
        $this->post('/api/shop/file', ['name' => 'list-prices.json', 'area' => 'examples', 'content' => '{"data": [{"id": "p1", "name": "Pro"}]}']);

        $response = $this->json('POST', '/api/shop/import', ['name' => 'list-prices.curl', 'force' => '1', 'family' => '1']);

        self::assertSame(200, $response->status);
        $data = $this->decode($response);
        self::assertTrue($data['ok']);
        self::assertSame(['list-prices.curl', 'list-prices-include.php'], array_column($data['results'], 'name'));
        self::assertSame('prices.list', $data['results'][0]['operation']);
        $operation = Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']['list'];
        self::assertStringContainsString('name: string,', $operation['response']['shape']);
        // The replacement did not drop the query parameter of the variant
        self::assertSame('product', $operation['query']['include']['example']);

        $unknown = $this->json('POST', '/api/shop/import', ['name' => 'nothing.curl']);
        self::assertSame(404, $unknown->status);
        self::assertFalse($this->decode($unknown)['ok']);
    }

    public function testDescriptorErrorsAreSaidThePlainWay(): void
    {
        $this->createApi();
        $this->example();
        $read = $this->decode($this->json('GET', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors']));
        $broken = str_replace("        path: prices\n", "        path: prices\n        query:\n            include:\n                type: array\n                required: false\n", $read['content']);
        self::assertNotSame($read['content'], $broken);

        $saved = $this->decode($this->json('POST', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors', 'content' => $broken]));

        self::assertTrue($saved['ok']);
        self::assertStringContainsString('the query parameter "include" of the operation "list" has the type "array", which does not exist; use one of string, int, float, bool', $saved['warnings'][0]);
        self::assertStringNotContainsString($this->project, $saved['warnings'][0]);
        self::assertStringContainsString('has the type &quot;array&quot;', $this->get('/api/shop')->body);
        self::assertStringContainsString('has the type &quot;array&quot;', $this->post('/api/shop/generate')->body);
    }

    private function exampleWithUnknowns(): void
    {
        $this->createApi();
        $this->post('/api/shop/type', ['name' => 'meta', 'format' => 'json', 'content' => '{"origin": "paddle", "ref": "x1"}']);
        $this->example(['response' => '{"data": [{"id": "p1", "meta": null, "tags": [], "note": null}]}']);
    }

    public function testNotesOfUnknownTypesOfferAFixForEachKey(): void
    {
        $this->exampleWithUnknowns();

        $notes = $this->get('/api/shop/notes')->body;

        self::assertStringContainsString('action="/api/shop/fix"', $notes);
        self::assertStringContainsString('<code>data[].meta</code> is', $notes);
        self::assertStringContainsString('<code>data[].tags</code> is', $notes);
        self::assertStringContainsString('value="type:meta-type.json"', $notes);
        self::assertStringContainsString('a list of meta-type.json', $notes);
        self::assertStringContainsString('<option value="object">', $notes);
        self::assertStringContainsString('in <code>list-prices.json</code>', $notes);
        // The descriptor is an advanced edit, under a fold; the examples come first
        self::assertStringContainsString('<summary class="text-secondary">Advanced: the descriptor</summary>', $notes);
        self::assertLessThan(strpos($notes, 'Advanced: the descriptor'), strpos($notes, 'Edit the examples:'));
        // No form inside another one: the checkboxes belong to the delete form
        self::assertStringContainsString('form="notes-delete"', $notes);
    }

    public function testFixesAnUnknownKeyByWritingItInTheExampleAndImportingAgain(): void
    {
        $this->exampleWithUnknowns();

        $object = $this->post('/api/shop/fix', ['kind' => 'null', 'key' => 'meta', 'file' => 'list-prices.json', 'choice' => 'type:meta-type.json']);
        self::assertStringContainsString('Fixed &quot;meta&quot;', $object->body);
        self::assertStringContainsString('"meta": "meta-type.json"', (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));

        $list = $this->post('/api/shop/fix', ['kind' => 'list', 'key' => 'tags', 'file' => 'list-prices.json', 'choice' => 'list-string']);
        self::assertStringContainsString('Fixed &quot;tags&quot;', $list->body);

        $shape = Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']['list']['response']['shape'];
        self::assertStringContainsString('meta: array{', $shape);
        self::assertStringContainsString('origin: string', $shape);
        self::assertStringContainsString('tags: list<string>', $shape);
        self::assertStringContainsString('note: mixed', $shape);

        // The note of what is fixed is gone, the one of the key left remains, with its fix
        $notes = $this->get('/api/shop/notes')->body;
        self::assertStringNotContainsString('<code>data[].meta</code> is', $notes);
        self::assertStringContainsString('<code>data[].note</code> is', $notes);

        $this->post('/api/shop/fix', ['kind' => 'null', 'key' => 'note', 'file' => 'list-prices.json', 'choice' => 'string']);
        self::assertStringContainsString('note: string', Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']['list']['response']['shape']);
        // Only the note about the probe is left, and it has no fix: accept it
        $left = $this->get('/api/shop/notes')->body;
        self::assertStringContainsString('probe guessed', $left);
        self::assertStringNotContainsString('marks values that are null', $left);
        self::assertStringNotContainsString('action="/api/shop/fix"', $left);
    }

    public function testTheFixRefusesWhatItCannotDoAndTouchesNothingElse(): void
    {
        $this->exampleWithUnknowns();
        $before = (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json');
        file_put_contents($this->project.'/secret.json', '{"meta": null}');

        $cases = [
            ['kind' => 'null', 'key' => 'meta', 'file' => 'list-prices.json', 'choice' => ''],
            ['kind' => 'null', 'key' => 'meta', 'file' => 'list-prices.json', 'choice' => 'type:../../secret.json'],
            ['kind' => 'null', 'key' => 'meta', 'file' => 'list-prices.json', 'choice' => 'type:nothing.json'],
            ['kind' => 'null', 'key' => 'meta', 'file' => '../../secret.json', 'choice' => 'string'],
            ['kind' => 'null', 'key' => 'meta', 'file' => 'list-prices.curl', 'choice' => 'string'],
            ['kind' => 'null', 'key' => 'a b"', 'file' => 'list-prices.json', 'choice' => 'string'],
            ['kind' => 'other', 'key' => 'meta', 'file' => 'list-prices.json', 'choice' => 'string'],
            ['kind' => 'null', 'key' => 'id', 'file' => 'list-prices.json', 'choice' => 'string'],
        ];
        foreach ($cases as $case) {
            $response = $this->post('/api/shop/fix', $case);
            self::assertStringContainsString('alert-danger', $response->body, json_encode($case));
        }

        self::assertSame($before, file_get_contents($this->project.'/tests/Fixtures/shop/list-prices.json'));
        self::assertSame('{"meta": null}', file_get_contents($this->project.'/secret.json'));
        self::assertSame(303, $this->get('/api/shop/fix')->status);
    }

    public function testExamplesThatAreInTheFolderAreImportedFromTheDashboard(): void
    {
        $this->createApi();
        mkdir($this->project.'/tests/Fixtures/shop', 0777, true);
        file_put_contents($this->project.'/tests/Fixtures/shop/list-prices.curl', 'curl https://api.shop.test/prices');
        file_put_contents($this->project.'/tests/Fixtures/shop/list-prices.json', '{"data": [{"id": "p1"}]}');
        file_put_contents($this->project.'/tests/Fixtures/shop/get-account.curl', 'curl https://api.shop.test/account');

        $dashboard = $this->get('/api/shop')->body;

        // Not the blank "Add an example" form: the examples found are offered
        self::assertStringContainsString('2 example(s) found, not imported yet', $dashboard);
        self::assertStringContainsString('href="/api/shop#examples"', $dashboard);
        self::assertStringContainsString('Import them', $dashboard);
        // Once in the list of endpoints, once in the list of files, for each of the two examples
        self::assertSame(4, substr_count($dashboard, 'not imported</span>'));
        self::assertStringContainsString('action="/api/shop/import-all"', $dashboard);

        $result = $this->post('/api/shop/import-all');

        self::assertStringContainsString('prices.list', $result->body);
        self::assertStringContainsString('account.list', $result->body);
        self::assertSame(['list'], array_keys(Yaml::parseFile($this->project.'/config/api/shop/prices.yaml')['operations']));
        $after = $this->get('/api/shop')->body;
        self::assertStringNotContainsString('not imported yet', $after);
        self::assertStringContainsString('2 operation(s)', $after);
        self::assertSame(303, $this->get('/api/shop/import-all')->status);
    }

    public function testAnInvalidDescriptorCanBeMadeAgainFromItsExample(): void
    {
        $this->createApi();
        $this->example();
        $read = $this->decode($this->json('GET', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors']));
        $broken = preg_replace('/shape: \|-\n(\s+)array\{/', "shape: |-\n$1array{\n$1    id: [\"id.array\"],", $read['content'], 1);
        self::assertNotSame($read['content'], $broken);
        $this->json('POST', '/api/shop/file', ['name' => 'prices.yaml', 'area' => 'descriptors', 'content' => $broken]);

        $dashboard = $this->get('/api/shop')->body;

        self::assertStringContainsString('id="problems"', $dashboard);
        self::assertStringContainsString('is not a valid PHPDoc type', $dashboard);
        self::assertStringContainsString('Import list-prices.curl again', $dashboard);
        self::assertStringContainsString('a descriptor is invalid: see below', $dashboard);
        self::assertStringContainsString('href="/api/shop#problems"', $dashboard);

        $fixed = $this->post('/api/shop/import', ['name' => 'list-prices.curl', 'force' => '1', 'family' => '1']);

        self::assertStringContainsString('prices.list', $fixed->body);
        $after = $this->get('/api/shop')->body;
        self::assertStringNotContainsString('id="problems"', $after);
        self::assertStringNotContainsString('a descriptor is invalid', $after);
    }

    public function testTheImportResultStacksItsListsSoTheEditorsOpenFullWidth(): void
    {
        $this->createApi();

        $result = $this->example();

        self::assertStringNotContainsString('col-md-6', $result->body);
        self::assertGreaterThan(strpos($result->body, 'Examples written'), strpos($result->body, 'Descriptors</h2>'));
        // The address of the import, loaded again, does not say "unknown example"
        self::assertSame(303, $this->get('/api/shop/import')->status);
    }

    public function testEachNoteCanBeAcceptedWithOneButton(): void
    {
        $this->createApi();
        $this->example();

        $notes = $this->get('/api/shop/notes')->body;
        self::assertGreaterThanOrEqual(1, substr_count($notes, '>Accept</button>'));

        preg_match('/<input type="hidden" name="notes\[\]" value="([^"]+)">/', $notes, $m);
        $accepted = $this->post('/api/shop/notes', ['notes' => [html_entity_decode($m[1])]]);

        self::assertStringContainsString('1 note(s) deleted.', $accepted->body);
        self::assertStringNotContainsString(substr(html_entity_decode($m[1]), -30), $this->get('/api/shop/notes')->body);
    }

    public function testTheDoctorExplainsWhenTheApplicationDoesNotKnowTheApi(): void
    {
        $this->createApi();
        $runner = new class extends CommandRunner {
            public function run(array $command, string $cwd, int $timeout = 60): array
            {
                return [1, "shop\n----\n\n API\n ✘ No doctor for \"shop\"; known APIs: lemonsqueezy.\n"];
            }
        };
        $app = new WebApp(new WebConfig($this->project, $this->project.'/config/api', 'App\\Api', 'src/Api', 'config/packages', ['php', 'bin/console'], 'secret', [self::HOST]), $runner);

        $response = $app->handle(new Request('POST', '/api/shop/doctor', [], ['_token' => $app->token()], self::HOST));

        self::assertStringContainsString('does not know this API', $response->body);
        self::assertStringContainsString('config/packages/api_shop.yaml', $response->body);
        self::assertStringContainsString('No doctor for', $response->body);
        self::assertStringNotContainsString('does not know this API', $this->post('/api/shop/doctor')->body);
    }

    public function testAddsAPostWithItsBodyAndItsResponse(): void
    {
        $this->createApi();
        $body = '{"name": "Analytics", "tax_category": "standard", "description": "Insights", "custom_data": null}';
        $bodyDocs = "namestringrequired\n\ntax_categorystringrequired\n\ndescriptionstring | null\n\ncustom_dataobject | null\n";

        $response = $this->example([
            'operation' => 'create', 'resource' => 'products',
            'request' => "curl -X POST https://api.shop.test/products -H 'Authorization: Bearer X'",
            'body' => $body, 'body_docs' => $bodyDocs,
            'response' => '{"data": {"id": "pro_1", "name": "Analytics"}}',
        ]);

        self::assertStringContainsString('products.create', $response->body);
        foreach (['create-products.curl', 'create-products.body.json', 'create-products.body.docs.md', 'create-products.json'] as $file) {
            self::assertFileExists($this->project.'/tests/Fixtures/shop/'.$file);
        }
        $operation = Yaml::parseFile($this->project.'/config/api/shop/products.yaml')['operations']['create'];
        self::assertSame('POST', $operation['method']);
        // The docs page of the body made the optional keys optional
        self::assertStringContainsString('name: string,', $operation['body']['shape']);
        self::assertStringContainsString('tax_category: string,', $operation['body']['shape']);
        self::assertStringContainsString('description?: ?string,', $operation['body']['shape']);
        self::assertStringContainsString('custom_data?:', $operation['body']['shape']);
        self::assertStringNotContainsString('every key of the request body', implode(' ', $operation['review'] ?? []));
    }

    public function testABodyWithoutItsDocsPageAsksToMarkTheOptionalKeys(): void
    {
        $this->createApi();

        $this->example(['operation' => 'create', 'resource' => 'products', 'request' => 'curl -X POST https://api.shop.test/products', 'body' => '{"name": "A"}', 'response' => '{"data": {"id": "p"}}']);

        $notes = $this->get('/api/shop/notes')->body;
        self::assertStringContainsString('request body: every key is typed as required', $notes);
        self::assertStringContainsString('body.docs.md', $notes);
    }

    public function testTheBodyIsNotGivenTwice(): void
    {
        $this->createApi();

        $response = $this->example(['operation' => 'create', 'resource' => 'products', 'request' => "curl -X POST https://api.shop.test/products -d '{\"a\": 1}'", 'body' => '{"name": "A"}']);

        self::assertStringContainsString('The request body is given twice', $response->body);
        self::assertFileDoesNotExist($this->project.'/tests/Fixtures/shop/create-products.body.json');
    }

    public function testSavingABodyFileImportsTheExampleItBelongsTo(): void
    {
        $this->createApi();
        $this->example(['operation' => 'create', 'resource' => 'products', 'request' => 'curl -X POST https://api.shop.test/products', 'body' => '{"name": "A"}', 'response' => '{"data": {"id": "p"}}']);

        $panel = fn (string $name) => $this->get('/api/shop/file', ['name' => $name, 'area' => 'examples', 'partial' => '1'])->body;
        $target = static fn (string $html): string => 1 === preg_match('/data-import="([^"]*)"/', $html, $m) ? $m[1] : 'none';

        self::assertSame('create-products.curl', $target($panel('create-products.body.json')));
        self::assertSame('create-products.curl', $target($panel('create-products.json')));
    }

    /**
     * @return array<string, string>
     */
    private function redirectQuery(Response $response): array
    {
        self::assertSame(303, $response->status);
        parse_str((string) parse_url($response->headers['Location'], \PHP_URL_QUERY), $query);

        return $query;
    }

    public function testAGetEndpointGoesThroughTheFourSteps(): void
    {
        $this->createApi();

        self::assertStringContainsString('New endpoint', $this->get('/api/shop/endpoint')->body);
        $created = $this->post('/api/shop/endpoint', ['method' => 'GET', 'path' => '/products']);
        self::assertSame(['base' => 'list-products', 'step' => 'request'], $this->redirectQuery($created));
        self::assertSame("curl -X GET 'https://api.shop.test/products'\n", file_get_contents($this->project.'/tests/Fixtures/shop/list-products.curl'));

        // Request: no body box for a GET
        $request = $this->get('/api/shop/endpoint', ['base' => 'list-products', 'step' => 'request'])->body;
        self::assertStringContainsString('<code>GET</code> https://api.shop.test/products', $request);
        self::assertStringContainsString('/assets/ace/ace.js', $request);
        self::assertStringContainsString('name="query"', $request);
        self::assertStringNotContainsString('name="body"', $request);
        $saved = $this->post('/api/shop/endpoint', ['base' => 'list-products', 'step' => 'request', 'query' => "include=product\nstatus=active, archived"]);
        self::assertSame(['base' => 'list-products', 'step' => 'response'], $this->redirectQuery($saved));
        self::assertStringContainsString("products?include=product&status=active%2C%20archived", (string) file_get_contents($this->project.'/tests/Fixtures/shop/list-products.curl'));
        self::assertStringContainsString('include=product', $this->get('/api/shop/endpoint', ['base' => 'list-products', 'step' => 'request'])->body);

        // Response
        $this->post('/api/shop/endpoint', ['base' => 'list-products', 'step' => 'response', 'response' => '{"data": [{"id": "pro_1", "name": "A"}]}', 'docs' => "dataarrayrequired\n\nidstringrequired\n\nnamestringrequired\n"]);
        self::assertFileExists($this->project.'/tests/Fixtures/shop/list-products.json');
        self::assertFileExists($this->project.'/tests/Fixtures/shop/list-products.docs.md');

        // Import
        $check = $this->get('/api/shop/endpoint', ['base' => 'list-products', 'step' => 'import'])->body;
        self::assertStringContainsString('list-products.docs.md', $check);
        $result = $this->post('/api/shop/endpoint', ['base' => 'list-products', 'step' => 'import']);
        self::assertStringContainsString('products.list', $result->body);
        $operation = Yaml::parseFile($this->project.'/config/api/shop/products.yaml')['operations']['list'];
        self::assertSame('product', $operation['query']['include']['example']);
        self::assertStringContainsString('name: string,', $operation['response']['shape']);

        $dashboard = $this->get('/api/shop')->body;
        self::assertStringContainsString('<code>GET</code> /products', $dashboard);
        self::assertStringNotContainsString('no response yet', $dashboard);
    }

    public function testAPostEndpointAsksForItsBodyAndItsDocs(): void
    {
        $this->createApi();

        self::assertSame(['base' => 'create-products', 'step' => 'request'], $this->redirectQuery($this->post('/api/shop/endpoint', ['method' => 'POST', 'path' => '/products'])));
        $request = $this->get('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'request'])->body;
        self::assertStringContainsString('name="body"', $request);
        self::assertStringContainsString('name="body_docs"', $request);

        $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'request', 'query' => '', 'body' => '{"name": "A", "description": null}', 'body_docs' => "namestringrequired\n\ndescriptionstring | null\n"]);
        self::assertFileExists($this->project.'/tests/Fixtures/shop/create-products.body.json');
        self::assertFileExists($this->project.'/tests/Fixtures/shop/create-products.body.docs.md');
        $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'response', 'response' => '{"data": {"id": "pro_1"}}', 'docs' => '']);
        $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'import']);

        $operation = Yaml::parseFile($this->project.'/config/api/shop/products.yaml')['operations']['create'];
        self::assertSame('POST', $operation['method']);
        self::assertStringContainsString('name: string,', $operation['body']['shape']);
        self::assertStringContainsString('description?: ?string,', $operation['body']['shape']);
    }

    public function testThePathDecidesTheOperation(): void
    {
        $this->createApi();

        $one = $this->redirectQuery($this->post('/api/shop/endpoint', ['method' => 'GET', 'path' => 'products/{id}']));
        $delete = $this->redirectQuery($this->post('/api/shop/endpoint', ['method' => 'DELETE', 'path' => '/products/{id}']));
        $patch = $this->redirectQuery($this->post('/api/shop/endpoint', ['method' => 'PATCH', 'path' => '/products/{id}']));
        $variant = $this->redirectQuery($this->post('/api/shop/endpoint', ['method' => 'GET', 'path' => '/products', 'variant' => 'include']));

        self::assertSame(['get-products', 'delete-products', 'update-products', 'list-products-include'], [$one['base'], $delete['base'], $patch['base'], $variant['base']]);
        self::assertStringContainsString('name="body"', $this->get('/api/shop/endpoint', ['base' => 'update-products', 'step' => 'request'])->body);
        self::assertStringNotContainsString('name="body"', $this->get('/api/shop/endpoint', ['base' => 'delete-products', 'step' => 'request'])->body);
    }

    public function testTheEndpointStepsCheckWhatTheyGet(): void
    {
        $this->createApi();

        foreach ([
            ['method' => 'FETCH', 'path' => '/products'],
            ['method' => 'GET', 'path' => ''],
            ['method' => 'GET', 'path' => '/products; rm -rf'],
            ['method' => 'GET', 'path' => "/products'"],
            ['method' => 'GET', 'path' => '/products', 'variant' => 'Bad Variant'],
        ] as $case) {
            self::assertStringContainsString('alert-danger', $this->post('/api/shop/endpoint', $case)->body, json_encode($case));
        }
        self::assertDirectoryDoesNotExist($this->project.'/tests/Fixtures/shop');

        $this->post('/api/shop/endpoint', ['method' => 'POST', 'path' => '/products']);
        self::assertStringContainsString('exists already', $this->post('/api/shop/endpoint', ['method' => 'POST', 'path' => '/products'])->body);

        self::assertStringContainsString('Query parameter &quot;oops&quot;', $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'request', 'query' => 'oops', 'body' => ''])->body);
        self::assertStringContainsString('Body: Not JSON', $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'request', 'query' => '', 'body' => '{"a": '])->body);
        self::assertStringContainsString('Response: Not JSON', $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'response', 'response' => "{'a': 1}", 'docs' => ''])->body);

        foreach (['nothing', '../x', 'a b'] as $base) {
            self::assertSame(404, $this->get('/api/shop/endpoint', ['base' => $base, 'step' => 'request'])->status, $base);
        }
        self::assertSame(404, $this->get('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'oops'])->status);
    }

    public function testTheFirstEndpointOfAnApiWithoutBaseUriNeedsTheFullUrl(): void
    {
        mkdir($this->project.'/config/api/bare', 0777, true);

        self::assertStringContainsString('has no base URI yet', $this->post('/api/bare/endpoint', ['method' => 'GET', 'path' => '/things'])->body);
    }

    private function doctorStepOf(string $dashboard): string
    {
        preg_match('#Check with the doctor\s*<span class="badge ([^"]+)">([^<]+)</span>.*?<span class="small text-secondary">([^<]*)</span>#s', $dashboard, $m);

        return ($m[1] ?? '?').' | '.($m[2] ?? '?').' | '.html_entity_decode($m[3] ?? '?');
    }

    /**
     * Steps 3 and 4 done: the notes accepted, the code generated.
     */
    private function readyForTheDoctor(): void
    {
        preg_match_all('/<input type="hidden" name="notes\[\]" value="([^"]+)">/', $this->get('/api/shop/notes')->body, $m);
        $this->post('/api/shop/notes', ['notes' => array_map('html_entity_decode', $m[1])]);
        $this->post('/api/shop/generate');
    }

    private function stepBadges(string $dashboard): array
    {
        preg_match_all('#<div class="fw-semibold">\s*([^<]+?)\s*<span class="badge [^"]+">([^<]+)</span>#', $dashboard, $m, \PREG_SET_ORDER);

        return array_column(array_map(static fn (array $x) => [trim($x[1]), $x[2]], $m), 1, 0);
    }

    public function testTheDoctorStepIsDoneWhenTheLastRunFoundNoProblem(): void
    {
        $this->createApi();
        $this->example();
        $this->readyForTheDoctor();

        self::assertSame('text-bg-warning | next | not run yet; needs the API key in .env.local', $this->doctorStepOf($this->get('/api/shop')->body));

        $this->post('/api/shop/doctor');
        $done = $this->doctorStepOf($this->get('/api/shop')->body);
        self::assertStringStartsWith('text-bg-success | done | no problem found at ', $done);
        self::assertFileExists($this->project.'/var/api-forge/web/doctor-shop.json');
        // On the page of the APIs too
        self::assertStringContainsString('text-bg-success">done', $this->get('/')->body);

        // A descriptor changed since: to do again
        touch($this->project.'/config/api/shop/prices.yaml', time() + 60);
        self::assertStringStartsWith('text-bg-', $this->doctorStepOf($this->get('/api/shop')->body));
        self::assertStringContainsString('but a descriptor or the generated code changed since', $this->doctorStepOf($this->get('/api/shop')->body));
        self::assertStringNotContainsString('text-bg-success', explode(' | ', $this->doctorStepOf($this->get('/api/shop')->body))[0]);
    }

    public function testTheDoctorStepIsNotDoneWhenItFoundProblems(): void
    {
        $this->createApi();
        $this->example();
        $this->readyForTheDoctor();
        $failing = new class extends CommandRunner {
            public function run(array $command, string $cwd, int $timeout = 60): array
            {
                return [1, " ✘ SHOP_API_KEY (credentials) is empty: set it in .env.local.\n"];
            }
        };
        $app = new WebApp(new WebConfig($this->project, $this->project.'/config/api', 'App\\Api', 'src/Api', 'config/packages', ['php', 'bin/console'], 'secret', [self::HOST]), $failing);

        $app->handle(new Request('POST', '/api/shop/doctor', [], ['_token' => $app->token()], self::HOST));

        $step = $this->doctorStepOf($app->handle(new Request('GET', '/api/shop', [], [], self::HOST))->body);
        self::assertStringNotContainsString('text-bg-success', $step);
        self::assertStringContainsString('found problems at', $step);
    }

    private function createProductExample(): void
    {
        $this->createApi();
        $this->post('/api/shop/type', ['name' => 'meta', 'format' => 'json', 'content' => '{"origin": "paddle", "ref": "x1"}']);
        $this->example([
            'operation' => 'create', 'resource' => 'products', 'request' => 'curl -X POST https://api.shop.test/products',
            'body' => '{"name": "A", "image_url": "u", "custom_data": {"features": {"sso": true}, "tags": ["x"]}}',
            'body_docs' => "namestringrequired\n\ncustom_dataobject | null\n\ntypestring\n\nValues\n\n*   `custom`\n\n*   `standard`\n",
            'response' => '{"data": {"id": "p", "import_meta": null, "custom_data": {"features": {"sso": true}}}}',
            'docs' => "dataobjectrequired\n\nidstringrequired\n\nimport_metaobject | nullrequired\n\nexternal_idstring | null\n\ncustom_dataobject | null\n",
        ]);
    }

    public function testNotesSayWhatToDoAndWhichFileToOpen(): void
    {
        $this->createProductExample();

        $page = html_entity_decode($this->get('/api/shop/notes')->body);

        self::assertStringContainsString('The docs page of the body describes "type" (line 5)', $page);
        self::assertStringContainsString('Add it to create-products.body.json, for example "type": "custom" (the docs allow custom, standard)', $page);
        self::assertStringContainsString('The body example has "image_url" (image_url), and the text of the docs page does not describe it', $page);
        self::assertStringContainsString('open create-products.body.docs.md and paste the missing part', $page);
        self::assertStringContainsString('The docs say "custom_data" is a free-form object', $page);
        self::assertStringContainsString('Replace its value with {} in create-products.body.json', $page);
        self::assertStringContainsString('Replace its value with {} in create-products.json', $page);
        self::assertStringContainsString('The docs describe fields inside "import_meta" (external_id)', $page);
        // Each file with its role; the notes of the body open the body files only
        self::assertStringContainsString('the body example', $page);
        self::assertStringContainsString('the docs page of the body', $page);
        self::assertStringContainsString('the response example', $page);
        $start = (int) strpos($page, 'The docs page of the body describes "type"');
        $block = substr($page, $start, (int) strpos($page, 'type="checkbox"', $start) - $start);
        self::assertStringContainsString('create-products.body.json</code>', $block);
        self::assertStringContainsString('create-products.body.docs.md</code>', $block);
        self::assertStringNotContainsString('create-products.json</code>', $block);
        // Far fewer notes than one per key
        self::assertLessThanOrEqual(9, substr_count($page, 'type="checkbox" name="notes[]"'));
    }

    public function testAFreeFormObjectIsFixedInOneClickInTheBodyAndTheResponse(): void
    {
        $this->createProductExample();

        $body = $this->post('/api/shop/fix', ['kind' => 'free', 'key' => 'custom_data', 'file' => 'create-products.body.json', 'choice' => 'freeform']);
        self::assertStringContainsString('Fixed', $body->body);
        self::assertStringContainsString('"custom_data": {}', (string) file_get_contents($this->project.'/tests/Fixtures/shop/create-products.body.json'));

        $this->post('/api/shop/fix', ['kind' => 'free', 'key' => 'custom_data', 'file' => 'create-products.json', 'choice' => 'freeform']);
        self::assertStringContainsString('"custom_data": {}', (string) file_get_contents($this->project.'/tests/Fixtures/shop/create-products.json'));

        $operation = Yaml::parseFile($this->project.'/config/api/shop/products.yaml')['operations']['create'];
        self::assertStringContainsString('custom_data?: array<string, mixed>|null', $operation['body']['shape']);
        self::assertStringContainsString('custom_data?: array<string, mixed>|null', $operation['response']['shape']);
        $notes = html_entity_decode($this->get('/api/shop/notes')->body);
        self::assertStringNotContainsString('is a free-form object', $notes);
        self::assertStringNotContainsString('gives it fields', $notes);
    }

    public function testAnEmptyParentIsFixedWithATypeFile(): void
    {
        $this->createProductExample();
        $notes = html_entity_decode($this->get('/api/shop/notes')->body);
        self::assertStringContainsString('<code>data.import_meta</code> is', $notes);
        self::assertStringContainsString('value="type:meta-type.json"', $notes);

        $this->post('/api/shop/fix', ['kind' => 'null', 'key' => 'import_meta', 'file' => 'create-products.json', 'choice' => 'type:meta-type.json']);

        self::assertStringContainsString('"import_meta": "meta-type.json"', (string) file_get_contents($this->project.'/tests/Fixtures/shop/create-products.json'));
        self::assertStringNotContainsString('fields inside "import_meta"', html_entity_decode($this->get('/api/shop/notes')->body));
    }

    public function testTheFreeFormFixOnlyReplacesAnObject(): void
    {
        $this->createProductExample();
        $before = (string) file_get_contents($this->project.'/tests/Fixtures/shop/create-products.body.json');

        $response = $this->post('/api/shop/fix', ['kind' => 'free', 'key' => 'name', 'file' => 'create-products.body.json', 'choice' => 'freeform']);

        self::assertStringContainsString('alert-danger', $response->body);
        self::assertSame($before, file_get_contents($this->project.'/tests/Fixtures/shop/create-products.body.json'));
        self::assertStringContainsString('Choose what the field is', $this->post('/api/shop/fix', ['kind' => 'null', 'key' => 'name', 'file' => 'create-products.body.json', 'choice' => 'freeform'])->body);
    }

    public function testEditingAStepMakesTheNextOnesToDoAgain(): void
    {
        $this->createApi();
        $this->example();
        $this->readyForTheDoctor();
        $this->post('/api/shop/doctor');
        self::assertSame(['Describe the API' => 'done', 'Add endpoints' => 'done', 'Review the notes' => 'done', 'Generate the code' => 'done', 'Check with the doctor' => 'done'], $this->stepBadges($this->get('/api/shop')->body));

        // The doctor ran a while ago (the files have a time to the second)
        file_put_contents($this->project.'/var/api-forge/web/doctor-shop.json', json_encode(['time' => time() - 120, 'code' => 0]));

        // Step 2 edited (the endpoint imported again): the notes come back, and the steps after them are to do again
        $this->post('/api/shop/import', ['name' => 'list-prices.curl', 'force' => '1']);
        $badges = $this->stepBadges($this->get('/api/shop')->body);
        self::assertSame('done', $badges['Add endpoints']);
        self::assertSame('next', $badges['Review the notes']);
        self::assertSame('to do', $badges['Generate the code']);
        self::assertSame('to do', $badges['Check with the doctor']);
        self::assertStringContainsString('(to do again after step 3)', $this->get('/api/shop')->body);

        // The notes accepted and the code generated again: the doctor is the last one to do
        $this->readyForTheDoctor();
        $badges = $this->stepBadges($this->get('/api/shop')->body);
        self::assertSame(['done', 'done', 'done', 'done', 'next'], array_values($badges));
        self::assertStringContainsString('Generate again', $this->get('/api/shop')->body);
    }

    public function testEveryButtonOfTheFirstFourStepsStaysEnabled(): void
    {
        $this->createApi();
        $this->example();
        $this->readyForTheDoctor();

        $dashboard = $this->get('/api/shop')->body;

        self::assertStringContainsString('href="/api/shop/describe">Edit</a>', $dashboard);
        self::assertStringContainsString('href="/api/shop/endpoint">New endpoint</a>', $dashboard);
        self::assertStringContainsString('href="/api/shop/notes">Review</a>', $dashboard);
        self::assertStringContainsString('href="/api/shop/generate">Generate again</a>', $dashboard);
        foreach (['/api/shop/describe', '/api/shop/endpoint', '/api/shop/notes', '/api/shop/generate'] as $path) {
            self::assertSame(200, $this->get($path)->status, $path);
        }
    }

    public function testTheDescriptionOfAnApiCanBeEdited(): void
    {
        $this->createApi();
        $this->example();
        // What the form does not hold is kept
        $store = Yaml::parseFile($this->project.'/config/api/shop/_api.yaml');
        self::assertArrayHasKey('doctor', $store);

        $form = $this->get('/api/shop/describe')->body;
        self::assertStringContainsString('Edit the description of shop', $form);
        self::assertStringContainsString('value="https://api.shop.test/"', $form);
        self::assertStringContainsString('value="https://sandbox.shop.test/"', $form);
        self::assertStringContainsString('value="SHOP_API_KEY"', $form);
        self::assertStringContainsString('Shop-Version: 1', $form);
        self::assertMatchesRegularExpression('/id="name"[^>]*readonly/', $form);

        $saved = $this->post('/api/shop/describe', ['base_uri' => 'https://api2.shop.test/', 'sandbox_uri' => '', 'base_uri_env' => '', 'format' => 'json', 'auth_type' => 'bearer', 'auth_env' => 'SHOP_TOKEN', 'auth_header' => '', 'headers' => '', 'namespace' => '']);

        self::assertSame(303, $saved->status);
        $api = Yaml::parseFile($this->project.'/config/api/shop/_api.yaml');
        self::assertSame('https://api2.shop.test/', $api['base_uri']);
        self::assertSame(['type' => 'bearer', 'env' => 'SHOP_TOKEN'], $api['auth']);
        self::assertArrayNotHasKey('environments', $api);
        self::assertArrayNotHasKey('base_uri_env', $api);
        self::assertArrayNotHasKey('headers', $api);
        self::assertSame($store['doctor'], $api['doctor']);

        $bad = $this->post('/api/shop/describe', ['base_uri' => 'nope', 'format' => 'json', 'auth_type' => 'bearer', 'auth_env' => '', 'headers' => 'no colon', 'sandbox_uri' => '', 'base_uri_env' => '', 'auth_header' => '', 'namespace' => '']);
        self::assertStringContainsString('alert-danger', $bad->body);
        self::assertSame('https://api2.shop.test/', Yaml::parseFile($this->project.'/config/api/shop/_api.yaml')['base_uri']);
    }

    public function testAnExistingEndpointCanBeEditedOrReplaced(): void
    {
        $this->createApi();
        $this->post('/api/shop/endpoint', ['method' => 'POST', 'path' => '/products']);
        $this->post('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'request', 'query' => '', 'body' => '{"name": "A"}']);

        $again = $this->post('/api/shop/endpoint', ['method' => 'POST', 'path' => '/products']);

        self::assertSame(200, $again->status);
        self::assertStringContainsString('This endpoint exists already', $again->body);
        self::assertStringContainsString('href="/api/shop/endpoint?base=create-products&amp;step=request">Edit the existing endpoint</a>', $again->body);
        self::assertStringContainsString('name="replace" value="1"', $again->body);

        // Replaced: the request is written again, the other files are kept
        file_put_contents($this->project.'/tests/Fixtures/shop/create-products.curl', "curl -X POST 'https://api.shop.test/products?old=1'\n");
        $replaced = $this->post('/api/shop/endpoint', ['method' => 'POST', 'path' => '/products', 'replace' => '1']);
        self::assertStringNotContainsString('old=1', (string) file_get_contents($this->project.'/tests/Fixtures/shop/create-products.curl'));
        self::assertSame(['base' => 'create-products', 'step' => 'request'], $this->redirectQuery($replaced));
        self::assertFileExists($this->project.'/tests/Fixtures/shop/create-products.body.json');
        self::assertStringContainsString('&quot;name&quot;: &quot;A&quot;', $this->get('/api/shop/endpoint', ['base' => 'create-products', 'step' => 'request'])->body);
    }

    public function testTheDashboardOffersToEditEachEndpoint(): void
    {
        $this->createApi();
        $this->example();

        self::assertStringContainsString('href="/api/shop/endpoint?base=list-prices&amp;step=request">Edit</a>', $this->get('/api/shop')->body);
    }

    public function testTheDoctorStepIsToDoAgainWhenTheCodeWasGeneratedAfterIt(): void
    {
        $this->createApi();
        $this->example();
        $this->readyForTheDoctor();
        $this->post('/api/shop/doctor');
        self::assertStringStartsWith('text-bg-success | done', $this->doctorStepOf($this->get('/api/shop')->body));

        touch($this->project.'/src/Api/Shop/Generated/ShopApi.php', time() + 60);

        self::assertStringContainsString('or the generated code changed since', $this->doctorStepOf($this->get('/api/shop')->body));
    }
}
