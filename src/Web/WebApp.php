<?php

namespace Snorky22\ApiForge\Web;

use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorSchema;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;
use Snorky22\ApiForge\Dev\PhpLiteral;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The web interface of the workflow: a form per step, each one checks what it gets, does the work of the step
 * (writes the examples, imports, generates, runs the doctor) and returns the next form. It keeps nothing: the
 * state of an API is read from the project's files, as api-forge:guide does.
 *
 * Local use only (api-forge:serve): the Host header is checked, every POST carries a token, and the files written
 * are the examples and the descriptors of the API.
 */
final class WebApp
{
    private const NAME = '[a-z][a-z0-9_-]{0,40}';
    private const OPERATIONS = ['list', 'get', 'create', 'update', 'delete'];

    private readonly WebServices $services;
    private readonly View $view;

    public function __construct(
        private readonly WebConfig $config,
        private readonly CommandRunner $runner = new CommandRunner(),
    ) {
        $this->services = new WebServices($config);
        $this->view = new View(__DIR__.'/templates');
    }

    public function handle(Request $request): Response
    {
        if (!\in_array($request->host, $this->config->hosts, true)) {
            return $this->error(403, 'Unexpected Host header: this interface answers on the loopback only.');
        }
        if ('POST' === $request->method && !hash_equals($this->token(), (string) ($request->post['_token'] ?? ''))) {
            return $this->error(403, 'The form is out of date (token): reload the page and submit it again.');
        }

        try {
            return $this->route($request);
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error(500, $e::class.': '.$e->getMessage());
        }
    }

    public function token(): string
    {
        return hash_hmac('sha256', 'api-forge-web', $this->config->secret);
    }

    private function route(Request $request): Response
    {
        $path = rtrim($request->path, '/') ?: '/';
        if ('/' === $path) {
            return $this->home();
        }
        if ('/new' === $path) {
            return 'POST' === $request->method ? $this->createApi($request) : $this->page('api_form', ['values' => $this->apiDefaults(), 'errors' => []], 'New API');
        }
        if (1 !== preg_match('#^/api/('.self::NAME.')(?:/(example|type|file|import|notes|generate|doctor))?$#', $path, $matches)) {
            return $this->error(404, 'Not found.');
        }
        $api = $matches[1];
        if (!is_dir($this->config->descriptorDir.'/'.$api)) {
            return $this->error(404, \sprintf('The API "%s" does not exist yet: create it.', $api));
        }

        return match ($matches[2] ?? '') {
            '' => $this->dashboard($api),
            'example' => 'POST' === $request->method ? $this->addExample($api, $request) : $this->exampleForm($api),
            'type' => 'POST' === $request->method ? $this->addType($api, $request) : $this->typeForm($api),
            'file' => 'POST' === $request->method ? $this->saveFile($api, $request) : $this->fileForm($api, $request),
            'import' => $this->importFile($api, $request),
            'notes' => 'POST' === $request->method ? $this->deleteNotes($api, $request) : $this->notes($api),
            'generate' => 'POST' === $request->method ? $this->generate($api) : $this->generateForm($api),
            'doctor' => 'POST' === $request->method ? $this->doctor($api) : $this->page('doctor', ['api' => $api, 'result' => null], 'Doctor'),
        };
    }

    // Pages

    private function home(): Response
    {
        $apis = [];
        foreach ($this->services->guide->apis() as $api) {
            $apis[$api] = $this->steps($api);
        }

        return $this->page('home', ['apis' => $apis], 'APIs');
    }

    private function dashboard(string $api): Response
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));

        return $this->page('api', ['api' => $api, 'steps' => $this->steps($api), 'files' => $fixtures->names(), 'descriptors' => $this->files($api, 'descriptors')->names(), 'progress' => $this->progress($api)], $api);
    }

    /**
     * @return list<array{title: string, done: bool, current: bool, detail: string, url: string, action: string}>
     */
    private function steps(string $api): array
    {
        $p = $this->progress($api);
        $notes = \count($p['review']);
        $steps = [
            ['1. Describe the API', $p['described'], $p['described'] ? 'described' : 'name, hosts, authentication', '/new', 'Describe'],
            ['2. Add examples', $p['described'] && $p['operations'] > 0 && [] === $p['pending'], \sprintf('%d operation(s)%s', $p['operations'], [] !== $p['pending'] ? ', '.\count($p['pending']).' example(s) not imported' : ''), '/api/'.$api.'/example', 'Add an example'],
            ['3. Review the notes', $p['operations'] > 0 && 0 === $notes, \sprintf('%d note(s)', $notes), '/api/'.$api.'/notes', 'Review'],
            ['4. Generate the code', $p['operations'] > 0 && [] === $p['stale'] && [] === $p['errors'], [] === $p['errors'] ? \sprintf('%d file(s) to generate', \count($p['stale'])) : 'descriptors invalid', '/api/'.$api.'/generate', 'Generate'],
            ['5. Check with the doctor', false, 'needs the API key in .env.local', '/api/'.$api.'/doctor', 'Run the doctor'],
        ];

        $current = true;
        $result = [];
        foreach ($steps as [$title, $done, $detail, $url, $action]) {
            $result[] = ['title' => $title, 'done' => $done, 'current' => $current && !$done, 'detail' => $detail, 'url' => $url, 'action' => $action];
            $current = $current && $done;
        }

        return $result;
    }

    /**
     * @return array{described: bool, errors: list<string>, resources: list<string>, operations: int, pending: array<string, string>, review: list<string>, probe: ?string, webhooks: bool, stale: list<string>}
     */
    private function progress(string $api): array
    {
        return $this->services->guide->progress($api, null, null, $this->config->fixturesDir($api));
    }

    // Step 1: the API

    /**
     * @return array<string, string>
     */
    private function apiDefaults(): array
    {
        return ['name' => '', 'base_uri' => '', 'sandbox_uri' => '', 'base_uri_env' => '', 'format' => 'json', 'auth_type' => 'bearer', 'auth_env' => '', 'auth_header' => '', 'headers' => '', 'namespace' => ''];
    }

    private function createApi(Request $request): Response
    {
        $values = [];
        foreach (array_keys($this->apiDefaults()) as $key) {
            $values[$key] = trim($request->text($key));
        }
        $errors = [];

        if (1 !== preg_match('/^'.self::NAME.'$/', $values['name'])) {
            $errors[] = 'The name is lowercase letters, digits, "-" and "_", starting with a letter.';
        } elseif (is_dir($this->config->descriptorDir.'/'.$values['name'])) {
            $errors[] = \sprintf('The API "%s" exists already.', $values['name']);
        }

        $data = ['base_uri' => $values['base_uri'], 'format' => $values['format']];
        if ('' !== $values['sandbox_uri']) {
            $data['environments'] = ['sandbox' => $values['sandbox_uri']];
            $data['base_uri_env'] = '' !== $values['base_uri_env'] ? $values['base_uri_env'] : strtoupper(preg_replace('/\W/', '_', $values['name'])).'_BASE_URI';
        }
        if ('' !== $values['namespace']) {
            $data['namespace'] = $values['namespace'];
        }
        $headers = [];
        foreach (explode("\n", $values['headers']) as $line) {
            if ('' !== trim($line)) {
                if (!str_contains($line, ':')) {
                    $errors[] = \sprintf('Header "%s": write "Name: value".', trim($line));
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }
        if ([] !== $headers) {
            $data['headers'] = $headers;
        }
        if ('' !== $values['auth_env']) {
            $data['auth'] = ['type' => $values['auth_type'], 'env' => $values['auth_env']] + ('header' === $values['auth_type'] ? ['header' => $values['auth_header']] : []);
        }

        if ([] === $errors) {
            try {
                (new Processor())->process(DescriptorSchema::api(), [$data]);
            } catch (ConfigException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ([] !== $errors) {
            return $this->page('api_form', ['values' => $values, 'errors' => $errors], 'New API');
        }

        $this->services->store->saveApi($values['name'], $data);

        return Response::redirect('/api/'.$values['name']);
    }

    // Step 2: the examples

    /**
     * @param list<string> $saved the files written although the import failed
     */
    private function exampleForm(string $api, array $values = [], array $errors = [], array $saved = []): Response
    {
        $values += ['operation' => '', 'resource' => '', 'variant' => '', 'request_format' => 'curl', 'request' => '', 'response_format' => 'json', 'response' => '', 'docs' => '', 'overwrite' => '', 'force' => ''];

        return $this->page('example_form', ['api' => $api, 'values' => $values, 'errors' => $errors, 'saved' => $saved, 'operations' => self::OPERATIONS, 'described' => $this->progress($api)['described']], 'Add an example');
    }

    private function addExample(string $api, Request $request): Response
    {
        $values = [];
        foreach (['operation', 'resource', 'variant', 'request_format', 'request', 'response_format', 'response', 'docs'] as $key) {
            $values[$key] = trim($request->text($key));
        }
        $values['overwrite'] = $request->flag('overwrite') ? '1' : '';
        $values['force'] = $request->flag('force') ? '1' : '';
        $errors = [];

        foreach (['operation' => $values['operation'], 'resource' => $values['resource']] as $label => $value) {
            if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $value)) {
                $errors[] = \sprintf('The %s is lowercase letters, digits and "-" (e.g. %s).', $label, 'operation' === $label ? 'list' : 'prices');
            }
        }
        if ('' !== $values['variant'] && 1 !== preg_match('/^[a-z][a-z0-9-]*$/', $values['variant'])) {
            $errors[] = 'The variant is lowercase letters, digits and "-" (e.g. include).';
        }
        if ('' === $values['request']) {
            $errors[] = 'Paste the request.';
        }
        if ([] !== $errors) {
            return $this->exampleForm($api, $values, $errors);
        }

        $base = $values['operation'].'-'.$values['resource'].('' !== $values['variant'] ? '-'.$values['variant'] : '');
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $baseUri = $this->services->store->loadApi($api)['base_uri'] ?? null;
        $files = [];

        try {
            if ('array' === $values['request_format']) {
                $content = $this->arrayFile($values['request']);
                (new PhpArrayExample())->operation($content, $baseUri ?? 'https://example.test/');
                $files[$base.'.php'] = $content;
                if ('' !== $values['response']) {
                    $errors[] = 'An array example holds its response under "response": leave the response box empty.';
                }
            } else {
                $this->services->curl->parse($values['request']);
                $files[$base.'.curl'] = $values['request']."\n";
                if ('' !== $values['response']) {
                    if ('array' === $values['response_format']) {
                        $files[$base.'.array'] = $this->arrayFile($values['response'], false);
                    } else {
                        $this->checkJson($values['response']);
                        $files[$base.'.json'] = $values['response']."\n";
                    }
                }
            }
            if ('' !== $values['docs']) {
                $files[$base.'.docs.md'] = $values['docs']."\n";
            }
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        if ([] === $errors) {
            try {
                foreach ($files as $name => $content) {
                    $fixtures->write($name, $content, '' !== $values['overwrite']);
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ([] !== $errors) {
            return $this->exampleForm($api, $values, $errors);
        }

        $paired = $this->pairedResponse($fixtures, $base);
        $example = isset($files[$base.'.php']) ? $base.'.php' : $base.'.curl';
        try {
            $result = $this->services->import->import($api, $fixtures->path($example), isset($files[$base.'.php']) ? null : $paired, null, null, null, '' !== $values['force']);
        } catch (\InvalidArgumentException $e) {
            return $this->exampleForm($api, $values, ['Saved, but not imported: '.$e->getMessage()], array_keys($files));
        }

        return $this->page('example_result', ['api' => $api, 'files' => array_keys($files), 'result' => $result, 'named' => $values['operation'].'-'.$values['resource'], 'steps' => $this->steps($api), 'descriptors' => [$result->resource.'.yaml']], 'Imported');
    }

    private function typeForm(string $api, array $values = [], array $errors = []): Response
    {
        return $this->page('type_form', ['api' => $api, 'values' => $values + ['name' => '', 'format' => 'json', 'content' => ''], 'errors' => $errors], 'Add a type');
    }

    private function addType(string $api, Request $request): Response
    {
        $values = ['name' => trim($request->text('name')), 'format' => trim($request->text('format')), 'content' => trim($request->text('content'))];
        $errors = [];
        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $values['name'])) {
            $errors[] = 'The name is lowercase letters, digits, "-" and "_" (e.g. duration).';
        }
        $file = $values['name'].'-type.'.('array' === $values['format'] ? 'array' : 'json');
        try {
            'array' === $values['format'] ? $this->arrayFile($values['content'], false) : $this->checkJson($values['content']);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
        if ([] === $errors) {
            try {
                (new FixtureDirectory($this->config->fixturesDir($api)))->write($file, $values['content']."\n", $request->flag('overwrite'));
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ([] !== $errors) {
            return $this->typeForm($api, $values, $errors);
        }

        return $this->page('type_result', ['api' => $api, 'file' => $file], 'Type added');
    }

    /**
     * The editor of a file: its page, the fragment the page's script inserts (?partial=1), or the content as JSON.
     */
    private function fileForm(string $api, Request $request, ?string $content = null, array $errors = [], ?string $saved = null): Response
    {
        $area = $this->areaOf((string) ($request->query['area'] ?? $request->post['area'] ?? ''));
        $name = (string) ($request->query['name'] ?? $request->post['name'] ?? '');
        $files = $this->files($api, $area);
        if (!$files->has($name)) {
            return $request->wantsJson() ? Response::json(['ok' => false, 'errors' => ['Unknown file.']], 404) : $this->error(404, 'Unknown file.');
        }

        $content ??= $files->read($name);
        $mode = self::modeOf($name);
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'name' => $name, 'area' => $area, 'mode' => $mode, 'content' => $content]);
        }

        $isExample = 'examples' === $area && 1 === preg_match('/\.(curl|php)$/', $name) && !str_contains($name, '-type.') && !str_contains($name, '-object.') && !str_starts_with($name, 'webhook-');
        $vars = ['api' => $api, 'area' => $area, 'name' => $name, 'content' => $content, 'mode' => $mode, 'line' => max(0, (int) ($request->query['line'] ?? 0)), 'errors' => $errors, 'saved' => $saved, 'isExample' => $isExample, 'token' => $this->token()];
        if (isset($request->query['partial'])) {
            return new Response(200, $this->view->fragment('editor_panel', $vars + ['fragment' => true]));
        }

        return $this->page('file', $vars, $name);
    }

    private function saveFile(string $api, Request $request): Response
    {
        $area = $this->areaOf(trim($request->text('area')));
        $name = trim($request->text('name'));
        $content = rtrim($request->text('content'))."\n";
        $files = $this->files($api, $area);
        if (!$files->has($name)) {
            return $request->wantsJson() ? Response::json(['ok' => false, 'errors' => ['Unknown file.']], 404) : $this->error(404, 'Unknown file.');
        }

        try {
            $this->checkFile($area, $name, $content);
            $files->write($name, $content);
        } catch (\InvalidArgumentException $e) {
            return $request->wantsJson() ? Response::json(['ok' => false, 'errors' => [$e->getMessage()]], 422) : $this->fileForm($api, $request, $content, [$e->getMessage()]);
        }

        // A descriptor may be saved half way through a change: say what the loader finds, without refusing it
        $warnings = 'descriptors' === $area ? array_map(fn (string $error) => str_replace($this->config->projectDir.'/', '', $error), \array_slice($this->progress($api)['errors'], 0, 5)) : [];
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'message' => 'Saved.', 'warnings' => $warnings]);
        }

        return $this->fileForm($api, $request, $content, [], 'Saved.'.([] !== $warnings ? ' Warning: '.implode(' ', $warnings) : ''));
    }

    private function importFile(string $api, Request $request): Response
    {
        $name = trim($request->text('name'));
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        if ('POST' !== $request->method || !$fixtures->has($name) || 1 !== preg_match('/\.(curl|php)$/', $name)) {
            return $this->error(404, 'Unknown example.');
        }

        $base = (string) preg_replace('/\.[^.]+$/', '', $name);
        try {
            $result = $this->services->import->import($api, $fixtures->path($name), str_ends_with($name, '.curl') ? $this->pairedResponse($fixtures, $base) : null, null, null, null, $request->flag('force'));
        } catch (\InvalidArgumentException $e) {
            return $this->fileForm($api, new Request('GET', $request->path, ['name' => $name, 'area' => 'examples'], [], $request->host, $request->accept), null, ['Not imported: '.$e->getMessage()]);
        }

        return $this->page('example_result', ['api' => $api, 'files' => [$name], 'result' => $result, 'named' => null, 'steps' => $this->steps($api), 'descriptors' => [$result->resource.'.yaml']], 'Imported');
    }

    // Step 3: the review notes

    private function notes(string $api, ?string $message = null): Response
    {
        $notes = [];
        foreach ($this->progress($api)['review'] as $note) {
            $notes[] = ['note' => $note, 'shown' => str_replace($this->config->projectDir.'/', '', $note), 'advice' => NoteAdvice::for($note), 'links' => $this->relatedFiles($api, $note)];
        }

        return $this->page('notes', ['api' => $api, 'notes' => $notes, 'message' => $message, 'errors' => $this->progress($api)['errors']], 'Review notes');
    }

    private function deleteNotes(string $api, Request $request): Response
    {
        $checked = array_values(array_filter((array) ($request->post['notes'] ?? []), 'is_string'));
        $removed = $this->services->store->removeReviewNotes($api, array_map(static fn (string $note) => str_replace("\r\n", "\n", $note), $checked));

        return $this->notes($api, \sprintf('%d note(s) deleted.', $removed));
    }

    // Steps 4 and 5: generate, check

    private function generateForm(string $api): Response
    {
        return $this->page('generate', ['api' => $api, 'result' => null, 'progress' => $this->progress($api)], 'Generate');
    }

    private function generate(string $api): Response
    {
        return $this->page('generate', ['api' => $api, 'result' => $this->services->generate->run($api), 'progress' => $this->progress($api)], 'Generate');
    }

    private function doctor(string $api): Response
    {
        [$code, $output] = $this->runner->run([...$this->config->console, 'api-forge:doctor', $api, '--no-ansi', '--no-interaction'], $this->config->projectDir);

        return $this->page('doctor', ['api' => $api, 'result' => ['code' => $code, 'output' => trim($output)]], 'Doctor');
    }

    // Helpers

    /**
     * A PHP array as a file: the text as pasted when it is a file, else wrapped in "return ...;".
     *
     * @throws \InvalidArgumentException when it is not an array of literal data
     */
    private function arrayFile(string $text, bool $wrap = true): string
    {
        $data = PhpLiteral::evaluate($text);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The array is expected, e.g. [\'a\' => 1].');
        }

        return $wrap && !str_starts_with(ltrim($text), '<?php') ? "<?php\n\nreturn ".rtrim(trim($text), "; \n").";\n" : rtrim($text)."\n";
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function checkJson(string $text): void
    {
        try {
            $this->services->decoder->decode($text);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Not JSON ('.$this->services->decoder->explain($text, $e).').');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function checkFile(string $area, string $name, string $content): void
    {
        match (strtolower(pathinfo($name, \PATHINFO_EXTENSION))) {
            'json' => $this->checkJson($content),
            'curl' => $this->services->curl->parse($content),
            'php', 'array' => $this->arrayFile($content),
            'yaml' => $this->checkYaml($content),
            default => null,
        };
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function checkYaml(string $content): void
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException('Not YAML: '.$e->getMessage());
        }
        if (null !== $data && !\is_array($data)) {
            throw new \InvalidArgumentException('A descriptor is a YAML mapping (key: value).');
        }
    }

    /**
     * The files an editor may open for an API: its examples, or its descriptors.
     */
    private function files(string $api, string $area): FixtureDirectory
    {
        return 'descriptors' === $area
            ? new FixtureDirectory($this->config->descriptorDir.'/'.$api, ['yaml'])
            : new FixtureDirectory($this->config->fixturesDir($api));
    }

    private function areaOf(string $area): string
    {
        return 'descriptors' === $area ? 'descriptors' : 'examples';
    }

    private static function modeOf(string $name): string
    {
        return match (strtolower(pathinfo($name, \PATHINFO_EXTENSION))) {
            'json' => 'json',
            'php' => 'php',
            'array' => 'array',
            'md' => 'markdown',
            'curl' => 'sh',
            'yaml' => 'yaml',
            default => 'text',
        };
    }

    /**
     * Where to fix what a review note is about: its descriptor, and the examples of its resource (at the line the
     * note gives, for the docs page).
     *
     * @return list<array{label: string, url: string}>
     */
    private function relatedFiles(string $api, string $note): array
    {
        $links = [];
        $link = static fn (string $api, string $area, string $name, int $line = 0): array => ['label' => $name, 'url' => '/api/'.$api.'/file?'.http_build_query(['name' => $name, 'area' => $area] + ($line > 0 ? ['line' => $line] : []))];

        $descriptors = $this->files($api, 'descriptors');
        if (1 === preg_match('#/([A-Za-z0-9_.-]+\.yaml): #', $note, $matches) && $descriptors->has($matches[1])) {
            $links[] = $link($api, 'descriptors', $matches[1]);
            $resource = basename($matches[1], '.yaml');
            $line = 1 === preg_match('/\(line (\d+)\)/', $note, $lines) ? (int) $lines[1] : 0;
            foreach ((new FixtureDirectory($this->config->fixturesDir($api)))->names() as $name) {
                if (!str_starts_with($resource, '_') && 1 === preg_match('/-'.preg_quote($resource, '/').'(-[a-z0-9-]+)?\./', $name) && !str_contains($name, '-type.')) {
                    $links[] = $link($api, 'examples', $name, str_ends_with($name, '.docs.md') ? $line : 0);
                }
            }
        }

        return $links;
    }

    private function pairedResponse(FixtureDirectory $fixtures, string $base): ?string
    {
        foreach (['json', 'array'] as $extension) {
            if ($fixtures->has($base.'.'.$extension)) {
                return $fixtures->path($base.'.'.$extension);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function page(string $template, array $vars, string $title): Response
    {
        return new Response(200, $this->view->render($template, $vars + ['token' => $this->token(), 'projectDir' => $this->config->projectDir, 'editor' => \in_array($template, ['file', 'api', 'notes', 'example_form', 'type_form', 'example_result'], true)], $title));
    }

    private function error(int $status, string $message): Response
    {
        return new Response($status, $this->view->render('error', ['message' => $message, 'status' => $status, 'token' => ''], 'Error'));
    }
}
