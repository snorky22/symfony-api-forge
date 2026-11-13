<?php

namespace Snorky22\ApiForge\Web;

use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorSchema;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;
use Snorky22\ApiForge\Dev\Api\Generator\GenerateResult;
use Snorky22\ApiForge\Dev\PhpArrayDumper;
use Snorky22\ApiForge\Dev\PhpLiteral;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\String\Inflector\EnglishInflector;
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
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

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
            return 'POST' === $request->method ? $this->createApi($request) : $this->page('api_form', ['values' => $this->apiDefaults(), 'errors' => [], 'edit' => false], 'New API');
        }
        if (1 !== preg_match('#^/api/('.self::NAME.')(?:/(describe|endpoint|example|type|file|import|import-all|notes|fix|generate|doctor|webhooks|webhook|object|object-choice))?$#', $path, $matches)) {
            return $this->error(404, 'Not found.');
        }
        $api = $matches[1];
        if (!is_dir($this->config->descriptorDir.'/'.$api)) {
            return $this->error(404, \sprintf('The API "%s" does not exist yet: create it.', $api));
        }

        return match ($matches[2] ?? '') {
            '' => $this->dashboard($api),
            'describe' => 'POST' === $request->method ? $this->saveApi($api, $request) : $this->editApi($api),
            'endpoint' => $this->endpoint($api, $request),
            'example' => 'POST' === $request->method ? $this->addExample($api, $request) : $this->exampleForm($api),
            'type' => 'POST' === $request->method ? $this->addType($api, $request) : $this->typeForm($api),
            'file' => 'POST' === $request->method ? $this->saveFile($api, $request) : $this->fileForm($api, $request),
            'import' => $this->importFile($api, $request),
            'import-all' => 'POST' === $request->method ? $this->importAll($api) : Response::redirect('/api/'.$api),
            'notes' => 'POST' === $request->method ? $this->deleteNotes($api, $request) : $this->notes($api),
            'fix' => 'POST' === $request->method ? $this->applyFix($api, $request) : Response::redirect('/api/'.$api.'/notes'),
            'generate' => 'POST' === $request->method ? $this->generate($api) : $this->generateForm($api),
            'doctor' => 'POST' === $request->method ? $this->doctor($api) : $this->doctorPage($api, null),
            'webhooks' => $this->webhooks($api),
            'object' => 'POST' === $request->method ? $this->editObject($api, $request) : Response::redirect('/api/'.$api.'/notes'),
            'object-choice' => 'POST' === $request->method ? $this->chooseObject($api, $request) : Response::redirect('/api/'.$api.'/notes'),
            'webhook' => 'POST' === $request->method ? $this->importPayload($api, $request) : $this->payloadForm($api, (string) ($request->query['event'] ?? ''), [], null),
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

        $progress = $this->progress($api);

        return $this->page('api', [
            'api' => $api,
            'steps' => $this->steps($api),
            'files' => $fixtures->names(),
            'descriptors' => $this->files($api, 'descriptors')->names(),
            'progress' => $progress,
            'problems' => $this->problems($fixtures, $progress['errors']),
            'notImported' => array_map('basename', array_keys($progress['pending'])),
            'endpoints' => $this->endpoints($fixtures, [] === $progress['errors'] ? array_map('basename', array_keys($progress['pending'])) : null),
            'webhooks' => $this->webhookState($api),
            'unreadable' => $this->unreadableExamples($fixtures),
        ], $api);
    }

    /**
     * @return list<array{title: string, done: bool, current: bool, detail: string, url: string, action: string}>
     */
    private function steps(string $api): array
    {
        $p = $this->progress($api);
        $notes = \count($p['review']);
        $steps = [
            ['1. Describe the API', $p['described'], $p['described'] ? 'described' : 'name, hosts, authentication', $p['described'] ? '/api/'.$api.'/describe' : '/new', $p['described'] ? 'Edit' : 'Describe'],
            $this->examplesStep($api, $p),
            ['3. Review the notes', $p['operations'] > 0 && 0 === $notes, \sprintf('%d note(s)', $notes), '/api/'.$api.'/notes', 'Review'],
            ['4. Generate the code', $p['operations'] > 0 && [] === $p['stale'] && [] === $p['errors'], [] === $p['errors'] ? (0 === \count($p['stale']) && $p['operations'] > 0 ? 'up to date' : \sprintf('%d file(s) to generate', \count($p['stale']))) : 'descriptors invalid', '/api/'.$api.'/generate', $p['operations'] > 0 && [] === $p['stale'] && [] === $p['errors'] ? 'Generate again' : 'Generate'],
            $this->doctorStep($api),
        ];

        // A step is done only if the ones before it are: editing a step makes the next ones to do again
        $previous = true;
        $result = [];
        foreach ($steps as $number => [$title, $done, $detail, $url, $action]) {
            if ($done && !$previous) {
                $detail .= ' (to do again after step '.$number.')';
            }
            $done = $done && $previous;
            $result[] = ['title' => $title, 'done' => $done, 'current' => $previous && !$done, 'detail' => $detail, 'url' => $url, 'action' => $action];
            $previous = $previous && $done;
        }

        return $result;
    }

    /**
     * Step 5. Done when the last run of the doctor found no problem, and no descriptor changed since.
     *
     * @return array{string, bool, string, string, string}
     */
    private function doctorStep(string $api): array
    {
        $title = '5. Check with the doctor';
        $url = '/api/'.$api.'/doctor';
        $last = $this->lastDoctorRun($api);
        if (null === $last) {
            return [$title, false, 'not run yet; needs the API key in .env.local', $url, 'Run the doctor'];
        }

        $when = date('Y-m-d H:i', $last['time']);
        if (0 !== $last['code']) {
            return [$title, false, 'found problems at '.$when, $url, 'Run it again'];
        }
        if (max($this->newestDescriptor($api), $this->newestGenerated($api)) > $last['time']) {
            return [$title, false, 'passed at '.$when.', but a descriptor or the generated code changed since', $url, 'Run it again'];
        }

        return [$title, true, 'no problem found at '.$when, $url, 'Run it again'];
    }

    private function doctorMarker(string $api): string
    {
        return $this->config->projectDir.'/var/api-forge/web/doctor-'.$api.'.json';
    }

    /**
     * @return ?array{time: int, code: int}
     */
    private function lastDoctorRun(string $api): ?array
    {
        $file = $this->doctorMarker($api);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($data) && isset($data['time'], $data['code']) ? ['time' => (int) $data['time'], 'code' => (int) $data['code']] : null;
    }

    /**
     * The newest file of the generated code of the API (its services, its client configuration).
     */
    private function newestGenerated(string $api): int
    {
        $namespace = $this->services->store->loadApi($api)['namespace'] ?? \Snorky22\ApiForge\Dev\Api\Naming::className($api);
        $newest = 0;
        $directory = $this->config->projectDir.'/'.trim($this->config->sourceDir, '/').'/'.$namespace.'/Generated';
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $newest = max($newest, (int) $file->getMTime());
            }
        }
        $client = $this->config->projectDir.'/'.trim($this->config->configDir, '/').'/api_'.$api.'.yaml';

        return max($newest, is_file($client) ? (int) filemtime($client) : 0);
    }

    private function newestDescriptor(string $api): int
    {
        $newest = 0;
        foreach (glob($this->config->descriptorDir.'/'.$api.'/*.yaml') ?: [] as $file) {
            $newest = max($newest, (int) filemtime($file));
        }

        return $newest;
    }

    /**
     * Step 2. The examples that are in the folder come first: an invalid descriptor, or examples not imported yet,
     * are dealt with from the dashboard, not by writing the examples again.
     *
     * @param array{described: bool, errors: list<string>, operations: int, pending: array<string, string>} $p
     *
     * @return array{string, bool, string, string, string}
     */
    private function examplesStep(string $api, array $p): array
    {
        $title = '2. Add endpoints';
        if ([] !== $p['errors']) {
            return [$title, false, 'a descriptor is invalid: see below', '/api/'.$api.'#problems', 'Fix it'];
        }
        if ([] !== $p['pending']) {
            return [$title, false, \sprintf('%d example(s) found, not imported yet', \count($p['pending'])), '/api/'.$api.'#examples', 'Import them'];
        }

        return [$title, $p['described'] && $p['operations'] > 0, \sprintf('%d operation(s)', $p['operations']), '/api/'.$api.'/endpoint', 'New endpoint'];
    }

    /**
     * For each descriptor error, the example that can make the descriptor again (importing it replaces it).
     *
     * @param list<string> $errors
     *
     * @return list<array{error: string, examples: list<string>}>
     */
    private function problems(FixtureDirectory $fixtures, array $errors): array
    {
        $problems = [];
        foreach (\array_slice($errors, 0, 8) as $error) {
            $examples = [];
            if (1 === preg_match('#config/api/[^/\s]+/([A-Za-z0-9_-]+)\.yaml#', $error, $m) && !str_starts_with($m[1], '_')) {
                foreach ($fixtures->names() as $name) {
                    if (1 === preg_match('/^[a-z0-9]+-'.preg_quote($m[1], '/').'\.(curl|php)$/', $name)) {
                        $examples[] = $name;
                    }
                }
            }
            $problems[] = ['error' => str_replace($this->config->projectDir.'/', '', $error), 'examples' => $examples];
        }

        return $problems;
    }

    /**
     * @return array{described: bool, errors: list<string>, resources: list<string>, operations: int, pending: array<string, string>, review: list<string>, probe: ?string, webhooks: bool, stale: list<string>}
     */
    private function progress(string $api): array
    {
        $progress = $this->services->guide->progress($api, null, null, $this->config->fixturesDir($api));
        $progress['errors'] = DescriptorErrors::explainAll($progress['errors'], $this->config->projectDir);

        return $progress;
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
            $errors[] = \sprintf('The API "%s" exists already: edit it from its dashboard.', $values['name']);
        }

        [$data, $more] = $this->apiData($values, []);
        $errors = [...$errors, ...$more];
        if ([] !== $errors) {
            return $this->page('api_form', ['values' => $values, 'errors' => $errors, 'edit' => false], 'New API');
        }

        $this->services->store->saveApi($values['name'], $data);

        return Response::redirect('/api/'.$values['name']);
    }

    /**
     * The description of an existing API, to edit.
     */
    private function editApi(string $api, ?array $values = null, array $errors = []): Response
    {
        $d = $this->services->store->loadApi($api) ?? [];
        $headers = [];
        foreach ($d['headers'] ?? [] as $name => $value) {
            $headers[] = $name.': '.$value;
        }
        $values ??= [
            'name' => $api,
            'base_uri' => (string) ($d['base_uri'] ?? ''),
            'sandbox_uri' => (string) ($d['environments']['sandbox'] ?? ''),
            'base_uri_env' => (string) ($d['base_uri_env'] ?? ''),
            'format' => (string) ($d['format'] ?? 'json'),
            'auth_type' => (string) ($d['auth']['type'] ?? 'bearer'),
            'auth_env' => (string) ($d['auth']['env'] ?? ''),
            'auth_header' => (string) ($d['auth']['header'] ?? ''),
            'headers' => implode("\n", $headers),
            'namespace' => (string) ($d['namespace'] ?? ''),
        ];

        return $this->page('api_form', ['values' => $values, 'errors' => $errors, 'edit' => true], 'Edit '.$api);
    }

    private function saveApi(string $api, Request $request): Response
    {
        $values = ['name' => $api];
        foreach (array_keys($this->apiDefaults()) as $key) {
            if ('name' !== $key) {
                $values[$key] = trim($request->text($key));
            }
        }

        [$data, $errors] = $this->apiData($values, $this->services->store->loadApi($api) ?? []);
        if ([] !== $errors) {
            return $this->editApi($api, $values, $errors);
        }
        $this->services->store->saveApi($api, $data);

        return Response::redirect('/api/'.$api);
    }

    /**
     * The descriptor of an API from the form: what the form does not hold (the doctor, the review notes, other
     * environments) is kept from the existing one.
     *
     * @param array<string, string> $values
     * @param array<string, mixed>  $existing
     *
     * @return array{array<string, mixed>, list<string>}
     */
    private function apiData(array $values, array $existing): array
    {
        $errors = [];
        $data = $existing;
        $data['base_uri'] = $values['base_uri'];
        $data['format'] = $values['format'];

        $environments = array_diff_key($data['environments'] ?? [], ['sandbox' => true]);
        if ('' !== $values['sandbox_uri']) {
            $environments['sandbox'] = $values['sandbox_uri'];
        }
        if ([] !== $environments) {
            $data['environments'] = $environments;
            $data['base_uri_env'] = '' !== $values['base_uri_env'] ? $values['base_uri_env'] : ($data['base_uri_env'] ?? strtoupper((string) preg_replace('/\W/', '_', $values['name'])).'_BASE_URI');
        } else {
            unset($data['environments'], $data['base_uri_env']);
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
        [] !== $headers ? $data['headers'] = $headers : $data = array_diff_key($data, ['headers' => true]);

        if ('' !== $values['auth_env']) {
            $data['auth'] = ['type' => $values['auth_type'], 'env' => $values['auth_env']] + ('header' === $values['auth_type'] ? ['header' => $values['auth_header']] : []);
        } else {
            unset($data['auth']);
        }
        '' !== $values['namespace'] ? $data['namespace'] = $values['namespace'] : $data = array_diff_key($data, ['namespace' => true]);

        if ([] === $errors) {
            try {
                (new Processor())->process(DescriptorSchema::api(), [$data]);
            } catch (ConfigException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [$data, $errors];
    }

    // Step 2: the examples

    /**
     * @param list<string> $saved the files written although the import failed
     */
    private function exampleForm(string $api, array $values = [], array $errors = [], array $saved = []): Response
    {
        $values += ['operation' => '', 'resource' => '', 'variant' => '', 'request_format' => 'curl', 'request' => '', 'body' => '', 'body_docs' => '', 'response_format' => 'json', 'response' => '', 'docs' => '', 'overwrite' => '', 'force' => ''];

        return $this->page('example_form', ['api' => $api, 'values' => $values, 'errors' => $errors, 'saved' => $saved, 'operations' => self::OPERATIONS, 'described' => $this->progress($api)['described']], 'Add an example');
    }

    private function addExample(string $api, Request $request): Response
    {
        $values = [];
        foreach (['operation', 'resource', 'variant', 'request_format', 'request', 'body', 'body_docs', 'response_format', 'response', 'docs'] as $key) {
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
                if ('' !== $values['response'] || '' !== $values['body']) {
                    $errors[] = 'An array example holds its body and its response under "body" and "response": leave their boxes empty.';
                }
            } else {
                $request = $this->services->curl->parse($values['request']);
                $files[$base.'.curl'] = $values['request']."\n";
                if ('' !== $values['body']) {
                    if (null !== $request->body) {
                        throw new \InvalidArgumentException('The request body is given twice: "-d" in the cURL sample and the body box.');
                    }
                    $this->checkJson($values['body']);
                    $files[$base.'.body.json'] = $values['body']."\n";
                }
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
            if ('' !== $values['body_docs']) {
                $files[$base.'.body.docs.md'] = $values['body_docs']."\n";
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
        $vars = ['api' => $api, 'area' => $area, 'name' => $name, 'content' => $content, 'mode' => $mode, 'line' => max(0, (int) ($request->query['line'] ?? 0)), 'errors' => $errors, 'saved' => $saved, 'isExample' => $isExample, 'importTarget' => 'examples' === $area ? ($this->importTarget($files, $name) ?? ([] !== $this->dependents($files, $name) ? $name : null)) : null, 'token' => $this->token()];
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
            $this->checkFile($area, $name, $content, 'examples' === $area ? $files : null);
            $files->write($name, $content);
        } catch (\InvalidArgumentException $e) {
            return $request->wantsJson() ? Response::json(['ok' => false, 'errors' => [$e->getMessage()]], 422) : $this->fileForm($api, $request, $content, [$e->getMessage()]);
        }

        // A descriptor may be saved half way through a change: say what the loader finds, without refusing it
        $warnings = 'descriptors' === $area ? \array_slice($this->progress($api)['errors'], 0, 5) : [];
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'message' => 'Saved.', 'warnings' => $warnings]);
        }

        return $this->fileForm($api, $request, $content, [], 'Saved.'.([] !== $warnings ? ' Warning: '.implode(' ', $warnings) : ''));
    }

    private function importFile(string $api, Request $request): Response
    {
        if ('POST' !== $request->method) {
            return Response::redirect('/api/'.$api);
        }
        $name = trim($request->text('name'));
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        if ($fixtures->has($name) && null === $this->importTarget($fixtures, $name) && [] !== $dependents = $this->dependents($fixtures, $name)) {
            return $this->importDependents($api, $fixtures, $dependents, $request);
        }
        if (!$fixtures->has($name) || 1 !== preg_match('/\.(curl|php)$/', $name)) {
            return $request->wantsJson() ? Response::json(['ok' => false, 'errors' => ['Unknown example.']], 404) : $this->error(404, 'Unknown example.');
        }

        try {
            $results = $this->importFamily($api, $fixtures, $name, $request->flag('force'), $request->flag('family'));
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return $this->fileForm($api, new Request('GET', $request->path, ['name' => $name, 'area' => 'examples'], [], $request->host, $request->accept), null, ['Not imported: '.$e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'results' => array_map(static fn (array $r) => ['name' => $r[0], 'operation' => $r[1]->resource.'.'.$r[1]->operation, 'endpoint' => $r[1]->endpoint, 'notes' => $r[1]->notes, 'review' => \count($r[1]->review)], $results)]);
        }
        $result = $results[0][1];

        return $this->page('example_result', ['api' => $api, 'files' => [$name], 'result' => $result, 'named' => null, 'steps' => $this->steps($api), 'descriptors' => [$result->resource.'.yaml']], 'Imported');
    }

    /**
     * Imports again the payloads and the examples that depend on a file (Save & import of a payload, an object or a
     * type file), replacing what they describe.
     *
     * @param list<string> $dependents
     */
    private function importDependents(string $api, FixtureDirectory $fixtures, array $dependents, Request $request): Response
    {
        $results = [];
        try {
            foreach ($dependents as $file) {
                if (1 === preg_match('/^webhook-(.+)\.json$/', $file, $event)) {
                    $result = $this->services->webhooks->import($api, null, [$fixtures->path($file)], $event[1], null, true);
                    $results[] = ['name' => $file, 'operation' => 'event '.$event[1], 'endpoint' => '/webhook/'.$api, 'notes' => $result['notes'], 'review' => \count(array_filter($result['review'], static fn (string $note) => str_starts_with($note, 'event "'.$event[1].'": ')))];
                } else {
                    foreach ($this->importFamily($api, $fixtures, $file, true, true) as [$name, $imported]) {
                        $results[] = ['name' => $name, 'operation' => $imported->resource.'.'.$imported->operation, 'endpoint' => $imported->endpoint, 'notes' => $imported->notes, 'review' => \count($imported->review)];
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $request->wantsJson() ? Response::json(['ok' => false, 'errors' => [$e->getMessage()]], 422) : $this->notes($api, null, ['Not imported: '.$e->getMessage()]);
        }

        return $request->wantsJson() ? Response::json(['ok' => true, 'results' => $results]) : $this->notes($api, \sprintf('Imported again: %s.', implode(', ', array_column($results, 'name'))));
    }

    /**
     * Imports an example (replacing its operation when asked) and, with $family, the variants of the same operation
     * after it (list-prices-include.php after list-prices.curl): they add their query parameters to it, which a
     * replacement would have dropped.
     *
     * @return list<array{string, \Snorky22\ApiForge\Dev\Api\Import\ImportResult}>
     *
     * @throws \InvalidArgumentException
     */
    private function importFamily(string $api, FixtureDirectory $fixtures, string $name, bool $force, bool $family, ?string $objectMode = null): array
    {
        $import = fn (string $file, bool $replace, ?string $mode = null) => $this->services->import->import(
            $api,
            $fixtures->path($file),
            str_ends_with($file, '.curl') ? $this->pairedResponse($fixtures, (string) preg_replace('/\.[^.]+$/', '', $file)) : null,
            null,
            null,
            null,
            $replace,
            null,
            $mode,
        );

        $primary = $import($name, $force, $objectMode);
        $results = [[$name, $primary]];
        if ($family) {
            $prefix = $primary->operation.'-'.$primary->resource.'-';
            foreach ($fixtures->names() as $variant) {
                if ($variant !== $name && str_starts_with($variant, $prefix) && 1 === preg_match('/\.(curl|php)$/', $variant)) {
                    $results[] = [$variant, $import($variant, false)];
                }
            }
        }

        return $results;
    }

    /**
     * The example to import again when this file is saved: itself, or the request next to a response, an array
     * response or a docs page (list-prices.json → list-prices.curl). Null for the others (a type file is pointed
     * to by examples: import the example that does).
     */
    private function importTarget(FixtureDirectory $fixtures, string $name): ?string
    {
        if (str_contains($name, '-type.') || str_contains($name, '-object.') || str_starts_with($name, 'webhook-')) {
            return null;
        }
        if (1 === preg_match('/\.(curl|php)$/', $name)) {
            return $name;
        }
        $base = (string) preg_replace('/(\.body\.docs\.md|\.docs\.md|\.body\.(json|array)|\.[^.]+)$/', '', $name);
        foreach (['curl', 'php'] as $extension) {
            if ($fixtures->has($base.'.'.$extension)) {
                return $base.'.'.$extension;
            }
        }

        return null;
    }

    private function commandLine(string ...$arguments): string
    {
        $console = array_map(fn (string $part) => str_replace($this->config->projectDir.'/', '', $part), $this->config->console);
        $console[0] = basename($console[0]);

        return implode(' ', [...$console, ...$arguments]);
    }

    // Webhooks: the events, and a screen per payload example (the JSON and its docs page), which describes the event,
    // or the resource of its object and so all the events of that entity

    /**
     * What _webhooks.yaml describes, for the dashboard and the webhooks page.
     *
     * @return array{described: bool, format: string, signature: ?string, eventName: ?string, eventId: ?string, secret: ?string, envelope: ?string, registration: ?string, entities: array<string, list<array{name: string, description: ?string, typed: string, detail: string, example: bool, notes: int}>>, typed: int, total: int, notes: int}
     */
    private function webhookState(string $api): array
    {
        $webhooks = $this->services->store->loadWebhooks($api);
        $format = $this->services->store->loadApi($api)['format'] ?? 'json';
        $state = ['described' => null !== $webhooks, 'format' => $format, 'signature' => null, 'eventName' => null, 'eventId' => null, 'secret' => null, 'envelope' => null, 'registration' => null, 'entities' => [], 'typed' => 0, 'total' => 0, 'notes' => 0];
        if (null === $webhooks) {
            return $state;
        }

        $signature = $webhooks['signature'] ?? [];
        $state['signature'] = isset($signature['header']) ? $signature['header'].': HMAC-'.strtoupper($signature['algorithm'] ?? 'sha256').' of '.($signature['content'] ?? '{body}').(isset($signature['parts']) ? ' ('.$signature['parts']['timestamp'].'=…'.($signature['parts']['separator'] ?? ';').$signature['parts']['signature'].'=…)' : '') : null;
        $state['secret'] = $signature['env'] ?? null;
        $state['eventName'] = $webhooks['event_name']['path'] ?? (isset($webhooks['event_name']['header']) ? 'header '.$webhooks['event_name']['header'] : null);
        $state['eventId'] = $webhooks['event_id']['path'] ?? (isset($webhooks['event_id']['header']) ? 'header '.$webhooks['event_id']['header'] : null);
        $state['envelope'] = isset($webhooks['envelope']) ? $webhooks['envelope']['data'] ?? 'data' : null;
        $state['registration'] = $webhooks['registration']['operation'] ?? null;
        $state['notes'] = \count($webhooks['review'] ?? []);

        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $objects = [];
        foreach ($webhooks['events'] ?? [] as $name => $event) {
            $name = (string) $name;
            $resource = $event['resource'] ?? null;
            if (null !== $resource && !\array_key_exists($resource, $objects)) {
                $objects[$resource] = isset($this->services->store->loadResource($api, $resource)['object']);
            }
            [$typed, $detail] = match (true) {
                isset($event['shape']) => ['shape', 'own shape'],
                null !== $resource && $objects[$resource] => ['object', 'object of '.$resource],
                null !== $resource => ['untyped', $resource.' has no object yet'],
                default => ['untyped', 'no payload yet'],
            };
            $notes = \count($event['review'] ?? []);
            $state['entities'][preg_match('/^([^.:]+)[.:]/', $name, $match) ? $match[1] : $name][] = ['name' => $name, 'description' => $event['description'] ?? null, 'typed' => $typed, 'detail' => $detail, 'example' => $fixtures->has('webhook-'.$name.'.json'), 'notes' => $notes];
            $state['total']++;
            $state['typed'] += 'untyped' === $typed ? 0 : 1;
            $state['notes'] += $notes;
        }

        return $state;
    }

    private function webhooks(string $api): Response
    {
        return $this->page('webhooks', ['api' => $api, 'state' => $this->webhookState($api), 'command' => $this->commandLine('api-forge:import-webhook', $api, '--events=tests/Fixtures/'.$api.'/webhook-events.md')], 'Webhooks');
    }

    /**
     * The screen of a payload example: the JSON, and the docs page of the payload.
     *
     * @param list<string> $errors
     */
    private function payloadForm(string $api, string $event, array $errors, ?array $values): Response
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $state = $this->webhookState($api);
        $known = null;
        foreach ($state['entities'] as $events) {
            foreach ($events as $candidate) {
                if ($candidate['name'] === $event) {
                    $known = $candidate;
                }
            }
        }
        $values ??= [
            'event' => $event,
            'payload' => '' !== $event ? $fixtures->read('webhook-'.$event.'.json') : '',
            'docs' => '' !== $event ? $fixtures->read('webhook-'.$event.'.docs.md') : '',
            'force' => true,
        ];

        return $this->page('webhook_form', ['api' => $api, 'values' => $values, 'errors' => $errors, 'state' => $state, 'known' => $known], '' !== $event ? $event : 'Webhook payload');
    }

    private function importPayload(string $api, Request $request): Response
    {
        $values = ['event' => trim($request->text('event')), 'payload' => trim($request->text('payload')), 'docs' => trim($request->text('docs')), 'force' => $request->flag('force')];
        if ('' === $values['payload']) {
            return $this->payloadForm($api, $values['event'], ['Paste the payload example: the JSON body of a delivery, from the docs or from a real one.'], $values);
        }
        try {
            $this->checkJson($values['payload']);
        } catch (\InvalidArgumentException $e) {
            return $this->payloadForm($api, $values['event'], ['Payload: '.$e->getMessage()], $values);
        }

        // The event names the files: the one chosen, or the one the payload holds
        $format = $this->services->store->loadApi($api)['format'] ?? 'json';
        $found = $this->services->webhookImporter->importPayload($values['payload'], $format)['event'];
        $event = '' !== $values['event'] ? $values['event'] : $found;
        if (null === $event || '' === $event) {
            return $this->payloadForm($api, '', ['The event name is not in the payload where api-forge looks (event_type, type, event, meta.event_name…): choose the event.'], $values);
        }
        if (null !== $found && $found !== $event) {
            return $this->payloadForm($api, $values['event'], [\sprintf('The payload is a "%s" event, not "%s": choose "%s", or paste the payload of "%s".', $found, $event, $found, $event)], $values);
        }

        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $written = [basename($fixtures->write('webhook-'.$event.'.json', $values['payload']."\n"))];
        $docsName = 'webhook-'.$event.'.docs.md';
        if ('' !== $values['docs']) {
            $written[] = basename($fixtures->write($docsName, $values['docs']."\n"));
        } elseif ($fixtures->has($docsName)) {
            // Emptied in the form, which showed it: the payload is imported without it
            unlink($fixtures->path($docsName));
        }

        try {
            $result = $this->services->webhooks->import($api, null, [$fixtures->path('webhook-'.$event.'.json')], $event, null, $values['force']);
        } catch (\InvalidArgumentException $e) {
            return $this->payloadForm($api, $event, [$e->getMessage()], $values);
        }

        $relative = fn (string $note) => str_replace([$this->config->projectDir.'/', $this->config->descriptorDir.'/'.$api.'/'], '', $note);
        $eventNotes = array_values(array_map(static fn (string $note) => substr($note, \strlen('event "'.$event.'": ')), array_filter($result['review'], static fn (string $note) => str_starts_with($note, 'event "'.$event.'": '))));

        return $this->page('webhook_result', [
            'api' => $api,
            'event' => $event,
            'written' => $written,
            'done' => array_map($relative, array_values(array_filter($result['notes'], static fn (string $note) => !str_starts_with($note, 'docs page read: ')))),
            'descriptors' => array_map('basename', $result['files']),
            'eventNotes' => $eventNotes,
            'otherNotes' => \count($result['review']) - \count($eventNotes),
            'state' => $this->webhookState($api),
        ], 'Imported');
    }

    // The object of a resource, described by a file of its own (<object>-object.array) that the payloads and the
    // examples point to: one description, edited by the user, shared by every event and operation of the resource

    /**
     * The resource a note is about: the one it names, or the one of its event's payload.
     *
     * @param array{file: string, part: ?string, text: string} $location
     */
    private function resourceOfNote(string $api, array $location): ?string
    {
        if (1 === preg_match('/(?:resource|the) "([a-z0-9][a-z0-9-]*)"(?: has no object| object|,| derived| set on)/', $location['text'], $m)) {
            return $m[1];
        }
        if ('_webhooks.yaml' === $location['file'] && null !== $location['part'] && str_starts_with($location['part'], 'event ')) {
            $resource = $this->services->store->loadWebhooks($api)['events'][substr($location['part'], 6)]['resource'] ?? null;

            return \is_string($resource) ? $resource : null;
        }

        return null;
    }

    /**
     * Where the object of a resource is described, and which payloads describe it.
     *
     * @return array{resource: string, label: string, described: bool, sources: list<string>, file: string, exists: bool, payloads: list<string>, pointing: list<string>}
     */
    private function objectState(string $api, string $resource): array
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $words = explode('-', $resource);
        $words[] = (new EnglishInflector())->singularize(array_pop($words))[0];
        $label = implode('-', $words);
        $file = $label.'-object.array';
        foreach (['array', 'php', 'json'] as $extension) {
            if ($fixtures->has($label.'-object.'.$extension)) {
                $file = $label.'-object.'.$extension;
                break;
            }
        }
        $payloads = [];
        foreach ($this->services->store->loadWebhooks($api)['events'] ?? [] as $event => $definition) {
            if ($resource === ($definition['resource'] ?? null) && $fixtures->has('webhook-'.$event.'.json')) {
                $payloads[] = 'webhook-'.$event.'.json';
            }
        }
        $pointing = array_values(array_filter($payloads, static fn (string $name) => str_contains($fixtures->read($name), '"'.$file.'"')));
        $descriptor = $this->services->store->loadResource($api, $resource);

        return [
            'resource' => $resource,
            'label' => str_replace('-', ' ', $label),
            'described' => isset($descriptor['object']),
            'sources' => array_map(static fn (array $source) => basename($source['example']), $descriptor['object']['sources'] ?? []),
            'file' => $file,
            'exists' => $fixtures->has($file),
            'payloads' => $payloads,
            'pointing' => $pointing,
        ];
    }

    /**
     * Opens the editor of the object of a resource; first creates its file from the object of a payload, which then
     * points to it.
     */
    private function editObject(string $api, Request $request): Response
    {
        $resource = trim($request->text('resource'));
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $resource)) {
            return $this->notes($api, null, ['Unknown resource.']);
        }
        $state = $this->objectState($api, $resource);
        $editor = Response::redirect('/api/'.$api.'/file?'.http_build_query(['name' => $state['file'], 'area' => 'examples']));
        if ($state['exists']) {
            return $editor;
        }
        if ([] === $state['payloads']) {
            return $this->notes($api, null, [\sprintf('No payload example holds a "%s" object yet: add one (Webhooks page), then create its file from it.', $resource)]);
        }

        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $key = $this->services->store->loadWebhooks($api)['envelope']['data'] ?? 'data';
        $payload = $state['payloads'][0];
        $content = $fixtures->read($payload);
        try {
            $data = $this->services->decoder->decode($content)[$key] ?? null;
        } catch (\JsonException) {
            $data = null;
        }
        if (!\is_array($data)) {
            return $this->notes($api, null, [\sprintf('%s holds no object in "%s": edit it by hand.', $payload, $key)]);
        }

        // The payload keeps its layout: only the value of its object changes, to the name of the file
        $pointed = JsonText::replaceValue($content, $key, '"'.$state['file'].'"', '{');
        if (null === $pointed || $state['file'] !== ($this->services->decoder->decode($pointed)[$key] ?? null)) {
            return $this->notes($api, null, [\sprintf('The object of %s could not be replaced by a reference: edit it by hand ("%s": "%s").', $payload, $key, $state['file'])]);
        }
        $fixtures->write($state['file'], "// The {$state['label']} object, as the API sends it. ".implode(', ', $state['payloads'])." point(s) to this file.\n// Edit it, then Save & import: the payloads that point to it are imported again.\n".(new PhpArrayDumper())->dump($data)."\n");
        $fixtures->write($payload, $pointed);

        return $editor;
    }

    /**
     * Imports again an example whose objects differ from the object of its resource, merging it with the examples of
     * the object, or replacing them by it.
     */
    private function chooseObject(string $api, Request $request): Response
    {
        $example = trim($request->text('example'));
        $mode = $request->text('mode');
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        if (!\in_array($mode, ['merge', 'replace'], true) || !$fixtures->has($example)) {
            return $this->notes($api, null, ['Choose Merge or Replace, for an example of the examples folder.']);
        }

        try {
            if (1 === preg_match('/^webhook-(.+)\.json$/', $example, $event)) {
                $result = $this->services->webhooks->import($api, null, [$fixtures->path($example)], $event[1], null, false, null, $mode);
                $done = $result['notes'];
            } else {
                $target = $this->importTarget($fixtures, $example) ?? throw new \InvalidArgumentException(\sprintf('No request next to %s to import it with.', $example));
                $done = $this->importFamily($api, $fixtures, $target, false, false, $mode)[0][1]->notes;
            }
        } catch (\InvalidArgumentException $e) {
            return $this->notes($api, null, ['Not done: '.$e->getMessage()]);
        }

        // The note of the difference is settled: the example is used now
        $this->services->store->removeReviewNotes($api, array_values(array_filter($this->progress($api)['review'], static fn (string $note) => str_contains($note, 'the objects of ') && str_contains($note, basename($example)) && str_contains($note, ' differ from the '))));
        $object = array_values(array_filter($done, static fn (string $note) => str_starts_with($note, 'object of ')));

        return $this->notes($api, [] !== $object ? ucfirst($object[0]).'.' : 'Imported again.');
    }

    /**
     * The webhook payloads and the endpoint examples to import again after a file changed: the file itself when it is
     * a payload, or the ones that point to it.
     *
     * @return list<string> example file names
     */
    private function dependents(FixtureDirectory $fixtures, string $name, array $seen = []): array
    {
        if (1 === preg_match('/^webhook-.+\.json$/', $name)) {
            return [$name];
        }
        if (!str_contains($name, '-object.') && !str_contains($name, '-type.') && 1 !== preg_match('/\.array$/', $name)) {
            return [];
        }
        $seen[] = $name;
        $dependents = [];
        foreach ($fixtures->names() as $candidate) {
            if (\in_array($candidate, $seen, true) || 1 !== preg_match('/\.(json|php|array)$/', $candidate) || 1 !== preg_match('/["\']'.preg_quote($name, '/').'["\']/', $fixtures->read($candidate))) {
                continue;
            }
            if (str_starts_with($candidate, 'webhook-')) {
                $dependents[] = $candidate;
            } elseif (null !== $target = $this->importTarget($fixtures, $candidate)) {
                $dependents[] = $target;
            } else {
                // A data file pointed to by another one (a type inside an object): what depends on that one
                array_push($dependents, ...$this->dependents($fixtures, $candidate, $seen));
            }
        }

        return array_values(array_unique($dependents));
    }

    // Step 2: the endpoints. One screen per part of the request and the response; each one writes its files, which are
    // the only state: an endpoint can be left half way and continued.

    /**
     * The endpoints of the examples folder (a .curl each), with what they have.
     *
     * @param ?list<string> $notImported the examples not imported yet, null when it is not known (invalid descriptors)
     *
     * @return list<array{base: string, method: string, path: string, body: bool, response: bool, imported: ?bool}>
     */
    private function endpoints(FixtureDirectory $fixtures, ?array $notImported): array
    {
        $endpoints = [];
        foreach ($fixtures->names() as $name) {
            if (1 !== preg_match('/^([a-z][a-z0-9_-]*)\.curl$/', $name, $m)) {
                continue;
            }
            try {
                $request = $this->services->curl->parse($fixtures->read($name));
            } catch (\InvalidArgumentException) {
                continue;
            }
            $endpoints[] = [
                'base' => $m[1],
                'method' => $request->method,
                'path' => (string) parse_url($request->url, \PHP_URL_PATH),
                'body' => $fixtures->has($m[1].'.body.json') || $fixtures->has($m[1].'.body.array'),
                'response' => $fixtures->has($m[1].'.json') || $fixtures->has($m[1].'.array'),
                'imported' => null === $notImported ? null : !\in_array($name, $notImported, true),
            ];
        }

        return $endpoints;
    }

    private function endpoint(string $api, Request $request): Response
    {
        $base = (string) ($request->query['base'] ?? $request->post['base'] ?? '');
        if ('' === $base) {
            return 'POST' === $request->method ? $this->endpointCreate($api, $request) : $this->endpointNew($api);
        }

        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $base) || !$fixtures->has($base.'.curl')) {
            return $this->error(404, 'Unknown endpoint.');
        }
        try {
            $curl = $this->services->curl->parse($fixtures->read($base.'.curl'));
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, $base.'.curl: '.$e->getMessage());
        }

        $post = 'POST' === $request->method;

        return match ((string) ($request->query['step'] ?? $request->post['step'] ?? 'request')) {
            'request' => $post ? $this->endpointSaveRequest($api, $base, $curl, $request) : $this->endpointRequest($api, $base, $curl, $fixtures),
            'response' => $post ? $this->endpointSaveResponse($api, $base, $curl, $request) : $this->endpointResponse($api, $base, $curl, $fixtures),
            'import' => $post ? $this->endpointImport($api, $base, $fixtures) : $this->endpointCheck($api, $base, $curl, $fixtures),
            default => $this->error(404, 'Unknown step.'),
        };
    }

    /**
     * @param list<string> $errors
     * @param ?string      $exists the base name of an endpoint that exists already: replace it, or edit it
     */
    private function endpointNew(string $api, array $values = [], array $errors = [], ?string $exists = null): Response
    {
        $described = null !== ($this->services->store->loadApi($api)['base_uri'] ?? null);

        return $this->page('endpoint_new', ['api' => $api, 'values' => $values + ['method' => 'GET', 'path' => '', 'variant' => ''], 'errors' => $errors, 'exists' => $exists, 'methods' => self::METHODS, 'described' => $described, 'step' => 'new', 'base' => null], 'New endpoint');
    }

    /**
     * Step "Endpoint": the method and the path. The operation and the resource come from them, as the importer reads them.
     */
    private function endpointCreate(string $api, Request $request): Response
    {
        $values = ['method' => strtoupper(trim($request->text('method'))), 'path' => trim($request->text('path')), 'variant' => trim($request->text('variant'))];
        $errors = [];
        if (!\in_array($values['method'], self::METHODS, true)) {
            $errors[] = 'Choose GET, POST, PUT, PATCH or DELETE.';
        }
        if (1 !== preg_match('#^(https?://[^\s\'"]+|/?[A-Za-z0-9_{}.~-]+(/[A-Za-z0-9_{}.~-]+)*)$#', $values['path'])) {
            $errors[] = 'The path is the one of the docs, e.g. /products or /products/{id}.';
        }
        if ('' !== $values['variant'] && 1 !== preg_match('/^[a-z][a-z0-9-]*$/', $values['variant'])) {
            $errors[] = 'The variant is lowercase letters, digits and "-" (e.g. include).';
        }
        $descriptor = $this->services->store->loadApi($api) ?? [];
        $baseUri = $descriptor['base_uri'] ?? null;
        if ([] === $errors && !preg_match('#^https?://#i', $values['path']) && null === $baseUri) {
            $errors[] = 'The API has no base URI yet: write the full URL ("https://api.example.com/products"), or describe the API first.';
        }
        if ([] !== $errors) {
            return $this->endpointNew($api, $values, $errors);
        }

        $url = preg_match('#^https?://#i', $values['path']) ? $values['path'] : rtrim((string) $baseUri, '/').'/'.ltrim($values['path'], '/');
        $text = self::curlText($values['method'], $url, []);
        try {
            $endpoint = $this->services->importer->import(
                $this->services->curl->parse($text),
                $api,
                null,
                isset($descriptor['base_uri']) ? [$descriptor['base_uri'], ...array_values($descriptor['environments'] ?? [])] : null,
                null,
                null,
                $descriptor['format'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->endpointNew($api, $values, [$e->getMessage()]);
        }

        $base = $endpoint->operationName.'-'.$endpoint->resource.('' !== $values['variant'] ? '-'.$values['variant'] : '');
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $base)) {
            return $this->endpointNew($api, $values, [\sprintf('"%s" cannot name the files: give a variant.', $base)]);
        }
        if ($fixtures->has($base.'.curl') && !$request->flag('replace')) {
            return $this->endpointNew($api, $values, [], $base);
        }
        $fixtures->write($base.'.curl', $text);

        // Replacing keeps the other files: the next screens show them, to change or to keep
        return Response::redirect('/api/'.$api.'/endpoint?base='.$base.'&step=request');
    }

    private function endpointRequest(string $api, string $base, \Snorky22\ApiForge\Dev\Api\Curl\CurlRequest $curl, FixtureDirectory $fixtures, ?array $values = null, array $errors = []): Response
    {
        $query = (string) parse_url($curl->url, \PHP_URL_QUERY);
        $lines = [];
        foreach ('' !== $query ? explode('&', $query) : [] as $pair) {
            $lines[] = rawurldecode($pair);
        }

        return $this->page('endpoint_request', [
            'api' => $api, 'base' => $base, 'step' => 'request', 'method' => $curl->method, 'url' => $curl->url,
            'hasBody' => \in_array($curl->method, self::BODY_METHODS, true),
            'values' => $values ?? ['query' => implode("\n", $lines), 'body' => $fixtures->read($base.'.body.json'), 'body_docs' => $fixtures->read($base.'.body.docs.md')],
            'errors' => $errors,
        ], 'Endpoint: request');
    }

    private function endpointSaveRequest(string $api, string $base, \Snorky22\ApiForge\Dev\Api\Curl\CurlRequest $curl, Request $request): Response
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $values = ['query' => trim($request->text('query')), 'body' => trim($request->text('body')), 'body_docs' => trim($request->text('body_docs'))];
        $errors = [];

        $pairs = [];
        foreach ('' !== $values['query'] ? explode("\n", $values['query']) : [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if (1 !== preg_match('/^([A-Za-z0-9_.\[\]-]+)=(.*)$/', $line, $m)) {
                $errors[] = \sprintf('Query parameter "%s": write name=value.', $line);
                continue;
            }
            $pairs[] = $m[1].'='.rawurlencode($m[2]);
        }
        $hasBody = \in_array($curl->method, self::BODY_METHODS, true);
        if ($hasBody && '' !== $values['body']) {
            try {
                $this->checkJson($values['body']);
            } catch (\InvalidArgumentException $e) {
                $errors[] = 'Body: '.$e->getMessage();
            }
        }
        if ([] !== $errors) {
            return $this->endpointRequest($api, $base, $curl, $fixtures, $values, $errors);
        }

        $url = (string) preg_replace('/\?.*$/', '', $curl->url).([] !== $pairs ? '?'.implode('&', $pairs) : '');
        $fixtures->write($base.'.curl', self::curlText($curl->method, $url, $curl->headers));
        if ($hasBody && '' !== $values['body']) {
            $fixtures->write($base.'.body.json', $values['body']."\n");
        }
        if ($hasBody && '' !== $values['body_docs']) {
            $fixtures->write($base.'.body.docs.md', $values['body_docs']."\n");
        }

        return Response::redirect('/api/'.$api.'/endpoint?base='.$base.'&step=response');
    }

    private function endpointResponse(string $api, string $base, \Snorky22\ApiForge\Dev\Api\Curl\CurlRequest $curl, FixtureDirectory $fixtures, ?array $values = null, array $errors = []): Response
    {
        return $this->page('endpoint_response', [
            'api' => $api, 'base' => $base, 'step' => 'response', 'method' => $curl->method, 'url' => $curl->url,
            'values' => $values ?? ['response' => $fixtures->read($base.'.json'), 'docs' => $fixtures->read($base.'.docs.md')],
            'errors' => $errors,
        ], 'Endpoint: response');
    }

    private function endpointSaveResponse(string $api, string $base, \Snorky22\ApiForge\Dev\Api\Curl\CurlRequest $curl, Request $request): Response
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $values = ['response' => trim($request->text('response')), 'docs' => trim($request->text('docs'))];
        if ('' !== $values['response']) {
            try {
                $this->checkJson($values['response']);
            } catch (\InvalidArgumentException $e) {
                return $this->endpointResponse($api, $base, $curl, $fixtures, $values, ['Response: '.$e->getMessage()]);
            }
            $fixtures->write($base.'.json', $values['response']."\n");
        }
        if ('' !== $values['docs']) {
            $fixtures->write($base.'.docs.md', $values['docs']."\n");
        }

        return Response::redirect('/api/'.$api.'/endpoint?base='.$base.'&step=import');
    }

    /**
     * Step "Import": what the endpoint has, and the button.
     */
    private function endpointCheck(string $api, string $base, \Snorky22\ApiForge\Dev\Api\Curl\CurlRequest $curl, FixtureDirectory $fixtures): Response
    {
        $hasBody = \in_array($curl->method, self::BODY_METHODS, true);
        $files = [['name' => $base.'.curl', 'role' => 'the request', 'present' => true]];
        if ($hasBody) {
            $files[] = ['name' => $base.'.body.json', 'role' => 'the request body', 'present' => $fixtures->has($base.'.body.json')];
            $files[] = ['name' => $base.'.body.docs.md', 'role' => 'the docs page of the body', 'present' => $fixtures->has($base.'.body.docs.md')];
        }
        $files[] = ['name' => $base.'.json', 'role' => 'the response', 'present' => $fixtures->has($base.'.json')];
        $files[] = ['name' => $base.'.docs.md', 'role' => 'the docs page of the response', 'present' => $fixtures->has($base.'.docs.md')];

        return $this->page('endpoint_import', ['api' => $api, 'base' => $base, 'step' => 'import', 'method' => $curl->method, 'url' => $curl->url, 'files' => $files, 'errors' => []], 'Endpoint: import');
    }

    private function endpointImport(string $api, string $base, FixtureDirectory $fixtures): Response
    {
        try {
            $results = $this->importFamily($api, $fixtures, $base.'.curl', true, true);
        } catch (\InvalidArgumentException $e) {
            $curl = $this->services->curl->parse($fixtures->read($base.'.curl'));

            return $this->page('endpoint_import', ['api' => $api, 'base' => $base, 'step' => 'import', 'method' => $curl->method, 'url' => $curl->url, 'files' => [], 'errors' => ['Not imported: '.$e->getMessage()]], 'Endpoint: import');
        }

        return $this->page('import_all', ['api' => $api, 'results' => $results, 'errors' => [], 'steps' => $this->steps($api)], 'Imported');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function curlText(string $method, string $url, array $headers): string
    {
        $quote = static fn (string $value): string => "'".str_replace("'", "'\\''", $value)."'";
        $parts = ['curl -X '.$method.' '.$quote($url)];
        foreach ($headers as $name => $value) {
            $parts[] = '-H '.$quote($name.': '.$value);
        }

        return implode(" \\\n  ", $parts)."\n";
    }

    /**
     * Imports the examples of the folder that are not imported yet.
     */
    private function importAll(string $api): Response
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $results = [];
        $errors = [];
        foreach (array_map('basename', array_keys($this->progress($api)['pending'])) as $name) {
            try {
                foreach ($this->importFamily($api, $fixtures, $name, false, false) as $result) {
                    $results[] = $result;
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $name.': '.$e->getMessage();
            }
        }

        return $this->page('import_all', ['api' => $api, 'results' => $results, 'errors' => $errors, 'steps' => $this->steps($api)], 'Imported');
    }

    // Step 3: the review notes

    /**
     * @param ?array{choice: string, key: string, file: string, name: string, format: string, content: string, placement: string} $draft
     *        what the user submitted in a code box of a note, shown again after an error
     */
    private function notes(string $api, ?string $message = null, array $errors = [], ?array $draft = null): Response
    {
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
        $notes = [];
        foreach ($this->progress($api)['review'] as $note) {
            $related = $this->relatedFiles($api, $note);
            $files = [];
            foreach ($related['examples'] as $example) {
                $files[$example['key']] ??= $example['name'];
            }
            $location = NoteFormatter::locate($note, $this->config->projectDir);
            $notes[] = [
                'note' => $note,
                'shown' => str_replace($this->config->projectDir.'/', '', $note),
                'location' => $location,
                'kind' => NoteFormatter::kind($note),
                'part' => NoteFormatter::partOf($location),
                'subject' => NoteFormatter::subject($location['text']),
                'guide' => NoteGuide::describe($location['text'], $files),
                'object' => null !== ($resource = $this->resourceOfNote($api, $location)) ? $this->objectState($api, $resource) : null,
                // An example whose objects differ from the object: merge it, or replace the object with it
                'choice' => 1 === preg_match('/the objects of (\S+) differ from the "([a-z0-9-]+)" object already described/', $location['text'], $differ) && $fixtures->has(basename($differ[1])) ? ['example' => basename($differ[1]), 'resource' => $differ[2]] : null,
                'examples' => $related['examples'],
                'descriptor' => $related['descriptor'],
                'fix' => $this->fixFor($api, $fixtures, $note),
            ];
        }

        return $this->page('notes', ['draft' => $draft, 'typeContent' => static fn (string $name, string $format): ?string => $fixtures->has($typeFile = 'json' === $format ? $name.'-type.json' : $name.'.array') ? rtrim($fixtures->read($typeFile)) : null, 'api' => $api, 'notes' => $notes, 'message' => $message, 'fixErrors' => $errors, 'unreadable' => $this->unreadableExamples($fixtures), 'errors' => $this->progress($api)['errors'], 'typeFiles' => $this->typeFiles($fixtures)], 'Review notes');
    }

    /**
     * The type files the examples can point to: the ones the user made (name-type.json, x.array), not the examples.
     *
     * @return list<string>
     */
    private function typeFiles(FixtureDirectory $fixtures): array
    {
        return array_values(array_filter($fixtures->names(), static fn (string $name) => 1 === preg_match('/(-type\.(json|php|array)|\.array)$/', $name) && 1 !== preg_match('/^(list|get|create|update|delete)-/', $name)));
    }

    /**
     * The part of an operation a note is about: its body, its response, its request (query, path), or the API.
     */
    private static function scopeOf(string $note): string
    {
        return match (true) {
            1 === preg_match('/body docs:|body example:|request body:/', $note) => 'body',
            1 === preg_match('/query parameter|path parameter/', $note) => 'request',
            1 === preg_match('/base URI|doctor probe|probe guessed/', $note) => 'api',
            default => 'response',
        };
    }

    /**
     * What a note about a field the example leaves unknown can be fixed with: "mixed" and empty lists, an object the docs
     * describe and the example shows empty, a free-form object the example fills. The keys, and the example holding each one.
     *
     * @return ?array{kind: string, keys: list<array{path: string, key: string, file: ?string}>}
     */
    private function fixFor(string $api, FixtureDirectory $fixtures, string $note): ?array
    {
        $scope = self::scopeOf($note);
        $candidates = array_values(array_filter($fixtures->names(), static fn (string $name) => 1 === preg_match('/\.(json|array|php)$/', $name) && !str_contains($name, '-type.') && ('body' === $scope) === str_contains($name, '.body.')));
        // A note of an event is about its payload only, and the files it points to (its object, its types)
        if (1 === preg_match('#_webhooks\.yaml: event "([^"]+)": #', $note, $event)) {
            $candidates = $fixtures->has('webhook-'.$event[1].'.json') ? self::pointedFiles($fixtures, 'webhook-'.$event[1].'.json') : [];
        }
        // A field the docs describe and the example lacks: added next to the field the docs put it after
        if (1 === preg_match('/docs: "([A-Za-z0-9_-]+)" \(line (\d+)[^)]*\) is not in the example: add it to the example \(in the docs it sits (after|between|before) "([A-Za-z0-9_-]+)"(?: and "([A-Za-z0-9_-]+)")?/', $note, $m)) {
            $before = 'before' === $m[3];
            foreach ($candidates as $name) {
                $text = $fixtures->read($name);
                $json = str_ends_with($name, '.json');
                // Between an object and one of its fields: the field goes first in that object, before the second one
                if ('between' === $m[3] && '' !== ($m[5] ?? '') && ($json ? JsonText::holds($text, $m[4], $m[5]) : PhpText::holds($text, $m[4], $m[5]))) {
                    $m[4] = $m[5];
                    $before = true;
                }
                if (($json && str_contains($text, '"'.$m[4].'"')) || (!$json && 1 === preg_match('/[\'"]'.preg_quote($m[4], '/').'[\'"]\s*=>/', $text))) {
                    return ['kind' => 'add', 'keys' => [[
                        'path' => $m[1], 'key' => $m[1], 'after' => $m[4], 'before' => $before, 'file' => $name,
                        'inList' => !$before && ($json ? JsonText::isInListItem($text, $m[4]) : PhpText::isInListItem($text, $m[4])),
                        'skeleton' => $json ? $this->skeletonFromDocs($fixtures, $name, (int) $m[2], $m[1], $m[4]) : [],
                    ]]];
                }
            }

            return null;
        }
        $find = static function (string $key, array $kinds) use ($candidates, $fixtures): ?array {
            foreach ($candidates as $name) {
                foreach ($kinds as $kind) {
                    if (null !== $pattern = self::fixPattern($key, $kind, $name)) {
                        if (1 === preg_match($pattern, $fixtures->read($name))) {
                            return [$name, $kind];
                        }
                    }
                }
            }

            return null;
        };

        if (1 === preg_match('/marks (values that are null|empty arrays) in the example[^(]*\(([^)]*)\)\s*$/s', $note, $m)) {
            $kind = str_starts_with($m[1], 'values') ? 'null' : 'list';
            $keys = [];
            foreach (preg_split('/,\s*/', $m[2]) ?: [] as $path) {
                $path = trim($path);
                if ('' === $path || '…' === $path) {
                    continue;
                }
                $key = (string) preg_replace('/^.*[.\]]/', '', $path);
                $found = $find($key, [$kind]);
                $keys[] = ['path' => $path, 'key' => $key, 'file' => $found[0] ?? null];
            }

            return [] === $keys ? null : ['kind' => $kind, 'keys' => $keys];
        }

        if (1 === preg_match('/"([A-Za-z0-9_-]+)" \(([^)]*)\) shows no fields in the example/', $note, $m)) {
            $found = $find($m[1], ['null', 'list']);

            $fields = 1 === preg_match('/but the docs describe ([^:]*): give it/', $note, $described) ? array_values(array_filter(array_map('trim', explode(',', $described[1])))) : [];

            return ['kind' => $found[1] ?? 'null', 'keys' => [['path' => $m[2], 'key' => $m[1], 'file' => $found[0] ?? null, 'fields' => $fields]]];
        }

        if (1 === preg_match('/"([A-Za-z0-9_-]+)" \(([^)]*)\) is a free-form object in the docs/', $note, $m)) {
            $found = $find($m[1], ['free']);

            return ['kind' => 'free', 'keys' => [['path' => $m[2], 'key' => $m[1], 'file' => $found[0] ?? null]]];
        }

        return null;
    }

    /**
     * The value of a key that a fix replaces, in a JSON example or a PHP array (null for a free-form object in a PHP
     * array: an array cannot say "any object").
     */
    private static function fixPattern(string $key, string $kind, string $file = 'x.json'): ?string
    {
        $key = preg_quote($key, '/');
        if (1 === preg_match('/\.(array|php)$/', $file)) {
            return 'free' === $kind ? null : '/([\'"]'.$key.'[\'"]\s*=>\s*)'.('null' === $kind ? 'null\b' : '\[\s*\]').'/i';
        }

        return 'free' === $kind ? '/"'.$key.'"\s*:\s*\{/' : '/("'.$key.'"\s*:\s*)'.('null' === $kind ? 'null\b' : '\[\s*\]').'/';
    }

    /**
     * The fields the docs list after a field (its own fields, nested ones included: the page has no indentation), up to
     * the next field of the object that holds it in the example: a checklist to describe it with.
     *
     * @return list<array{name: string, type: string}>
     */
    private function skeletonFromDocs(FixtureDirectory $fixtures, string $example, int $line, string $key, string $after): array
    {
        $docs = (string) preg_replace('/\.json$/', '', $example).'.docs.md';
        $siblings = [$after];
        $data = json_decode($fixtures->read($example), true);
        $found = self::objectWith(\is_array($data) ? $data : [], $after);
        if (null !== $found) {
            $siblings = array_keys($found);
        }
        $listed = [];
        if ($fixtures->has($docs)) {
            $entries = (new \Snorky22\ApiForge\Dev\Api\Docs\DocsCapture())->parse($fixtures->read($docs));
            $started = false;
            $count = 0;
            foreach ($entries as $entry) {
                if (!$started) {
                    $started = $entry->line === $line;
                    continue;
                }
                if (\in_array($entry->name, $siblings, true) || ++$count > 40) {
                    break;
                }
                $listed[] = ['name' => $entry->name, 'type' => $entry->type.($entry->nullable ? ' | null' : '').(true === $entry->required ? ', required' : '').(null !== $entry->values ? ' ('.implode(', ', \array_slice($entry->values, 0, 4)).(\count($entry->values) > 4 ? '…' : '').')' : '')];
            }
        }

        return $listed;
    }

    /**
     * The first object of the data that has the key.
     *
     * @param array<mixed> $data
     *
     * @return ?array<mixed>
     */
    private static function objectWith(array $data, string $key): ?array
    {
        if (\array_key_exists($key, $data) && !array_is_list($data)) {
            return $data;
        }
        foreach ($data as $value) {
            if (\is_array($value) && null !== $found = self::objectWith($value, $key)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Writes the type file of a field the example lacks, adds the field to the example (next to another field, or as a
     * new item of the list instead of it), and imports again.
     */
    private function addMissingField(string $api, Request $request, FixtureDirectory $fixtures): Response
    {
        $key = $request->text('key');
        $after = $request->text('after');
        $file = $request->text('file');
        $name = trim($request->text('new_name'));
        $formats = $request->post['new_format'] ?? 'array';
        $format = \is_array($formats) ? (string) reset($formats) : (string) $formats;
        $content = trim($request->text('new_content'));
        $asNewItem = 'item' === $request->text('placement');
        $before = 'before' === $request->text('placement');
        $typeFile = 'json' === $format ? $name.'-type.json' : $name.'.array';
        try {
            if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $key) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $after) || !$fixtures->has($file) || 1 !== preg_match('/\.(json|array|php)$/', $file)) {
                throw new \InvalidArgumentException('This field cannot be added from here: edit the example by hand.');
            }
            if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
                throw new \InvalidArgumentException('The name of the type is lowercase letters, digits, "-" and "_" (e.g. price).');
            }
            'json' === $format ? $this->checkJson($content) : $this->arrayFile($content, false);
            $added = str_ends_with($file, '.json')
                ? JsonText::addMember($fixtures->read($file), $after, $key, '"'.$typeFile.'"', $asNewItem, $before)
                : PhpText::addMember($fixtures->read($file), $after, $key, "'".$typeFile."'", $asNewItem, $before);
            if (null === $added) {
                throw new \InvalidArgumentException(\sprintf('"%s" was not found in %s%s.', $after, $file, $asNewItem ? ' inside an item of a list' : ''));
            }
            $this->checkFile('examples', $file, $added, $fixtures);
            $fixtures->write($typeFile, $content."\n");
            $fixtures->write($file, $added);
            // The example imported again: itself (an endpoint), or what depends on it (a payload, a file pointed to)
            $target = $this->importTarget($fixtures, $file);
            $imported = null !== $target ? $this->importFamily($api, $fixtures, $target, true, true) : [];
            foreach (array_diff([...$this->dependents($fixtures, $file), ...$this->dependents($fixtures, $typeFile)], [$target]) as $other) {
                str_starts_with($other, 'webhook-') ? $this->services->webhooks->import($api, null, [$fixtures->path($other)], substr($other, 8, -5), null, true) : $this->importFamily($api, $fixtures, $other, true, true);
                $imported[] = $other;
            }
        } catch (\InvalidArgumentException $e) {
            return $this->notes($api, null, [\sprintf('"%s" was not added: %s', $key, $e->getMessage())], ['choice' => 'add', 'key' => $key, 'file' => $file, 'name' => $name, 'format' => $format, 'content' => $content, 'placement' => $asNewItem ? 'item' : 'same']);
        }

        return $this->notes($api, \sprintf('Added "%s" to %s (%s), described in %s%s.', $key, NoteFormatter::roleOfFile($file), $asNewItem ? 'as a new item of the list, instead of "'.$after.'"' : ($before ? 'before "'.$after.'"' : 'next to "'.$after.'"'), $typeFile, [] !== $imported ? ', and imported again' : ''));
    }

    /**
     * An example and the data files it points to, at any depth ("trial_period.array" in a payload…).
     *
     * @return list<string>
     */
    private static function pointedFiles(FixtureDirectory $fixtures, string $name): array
    {
        $files = [$name];
        for ($i = 0; $i < \count($files); ++$i) {
            preg_match_all('/["\']([A-Za-z0-9_][A-Za-z0-9_.-]*\.(?:json|array|php))["\']/', $fixtures->read($files[$i]), $m);
            foreach ($m[1] as $pointed) {
                if ($fixtures->has($pointed) && !\in_array($pointed, $files, true)) {
                    $files[] = $pointed;
                }
            }
        }

        return $files;
    }

    /**
     * Fixes a key the example leaves unknown by writing a value for it in the example, then imports the example again.
     */
    private function applyFix(string $api, Request $request): Response
    {
        $kind = $request->text('kind');
        $key = $request->text('key');
        $file = $request->text('file');
        $choice = $request->text('choice');
        $fixtures = new FixtureDirectory($this->config->fixturesDir($api));

        // A field the example lacks, described in a type file and added next to another one (or as a new item)
        if ('add' === $choice) {
            return $this->addMissingField($api, $request, $fixtures);
        }

        // A new type file, described here: written, then used like the type files of the list
        if ('new' === $choice) {
            $name = trim($request->text('new_name'));
            $formats = $request->post['new_format'] ?? 'array';
            $format = \is_array($formats) ? (string) reset($formats) : (string) $formats;
            $content = trim($request->text('new_content'));
            $typeFile = 'json' === $format ? $name.'-type.json' : $name.'.array';
            try {
                if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
                    throw new \InvalidArgumentException('The name of the type is lowercase letters, digits, "-" and "_" (e.g. proration).');
                }
                'json' === $format ? $this->checkJson($content) : $this->arrayFile($content, false);
                if ('list' === $kind && null !== $problem = $this->listItemProblem($fixtures, $typeFile, $content, true)) {
                    throw new \InvalidArgumentException($problem);
                }
                $existed = $fixtures->has($typeFile);
                $fixtures->write($typeFile, $content."\n");
                // A type file updated: the examples that point to it already are imported again
                if ($existed) {
                    foreach ($this->dependents($fixtures, $typeFile) as $other) {
                        str_starts_with($other, 'webhook-') ? $this->services->webhooks->import($api, null, [$fixtures->path($other)], substr($other, 8, -5), null, true) : $this->importFamily($api, $fixtures, $other, true, true);
                    }
                }
            } catch (\InvalidArgumentException $e) {
                return $this->notes($api, null, [\sprintf('The type "%s" was not saved: %s', $name, $e->getMessage())], ['choice' => 'new', 'key' => $key, 'file' => $file, 'name' => $name, 'format' => $format, 'content' => $content, 'placement' => '']);
            }
            $choice = 'type:'.$typeFile;
        }

        $literal = match (true) {
            'freeform' === $choice && 'free' === $kind => '{}',
            'string' === $choice => '"text"',
            'int' === $choice => '1',
            'float' === $choice => '1.5',
            'datetime' === $choice => '"2026-01-01T00:00:00Z"',
            'date' === $choice => '"2026-01-01"',
            'bool' === $choice => 'true',
            'object' === $choice => '{}',
            'list-string' === $choice => '["text"]',
            'list-int' === $choice => '[1]',
            str_starts_with($choice, 'type:') && $fixtures->has(substr($choice, 5)) && 1 === preg_match('/^[A-Za-z0-9._-]+$/', substr($choice, 5)) => ('list' === $kind ? '["'.substr($choice, 5).'"]' : '"'.substr($choice, 5).'"'),
            default => null,
        };
        // In a PHP array: its own syntax, and no way to say "any object"
        $array = 1 === preg_match('/\.(array|php)$/', $file);
        if ($array && null !== $literal) {
            $literal = '{}' === $literal ? null : str_replace('"', "'", $literal);
        }
        $errors = [];
        if (null === $literal && $array && \in_array($choice, ['object', 'freeform'], true)) {
            $errors[] = \sprintf('%s is a PHP array: it cannot say "any object". Write the fields of "%s" in it by hand (open it below), or choose a type file.', $file, $key);
        } elseif (null === $literal) {
            $errors[] = 'Choose what the field is.';
        } elseif (!\in_array($kind, ['null', 'list', 'free'], true) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            $errors[] = 'This field cannot be fixed from here.';
        } elseif (!$fixtures->has($file) || 1 !== preg_match('/\.(json|array|php)$/', $file)) {
            $errors[] = 'The example holding this field was not found: edit the examples by hand.';
        }
        if ([] !== $errors) {
            return $this->notes($api, null, $errors);
        }

        $content = $fixtures->read($file);
        if ('free' === $kind) {
            $fixed = JsonText::replaceValue($content, $key, '{}', '{');
            $count = null === $fixed ? 0 : 1;
        } else {
            $fixed = preg_replace((string) self::fixPattern($key, $kind, $file), '${1}'.$literal, $content, 1, $count);
        }
        if (0 === $count || null === $fixed) {
            return $this->notes($api, null, [\sprintf('"%s" is not unknown in %s anymore: import it again, or reload the notes.', $key, $file)]);
        }

        try {
            $this->checkFile('examples', $file, $fixed, $fixtures);
            $fixtures->write($file, $fixed);
            // A webhook payload, or a file payloads point to: they are imported again, replacing what they described
            $payloads = array_values(array_filter($this->dependents($fixtures, $file), static fn (string $name) => str_starts_with($name, 'webhook-')));
            if ([] !== $payloads) {
                foreach ($payloads as $payload) {
                    preg_match('/^webhook-(.+)\.json$/', $payload, $event);
                    $this->services->webhooks->import($api, null, [$fixtures->path($payload)], $event[1], null, true);
                }

                return $this->notes($api, $payloads === [$file]
                    ? \sprintf('Fixed "%s": wrote %s in %s, and imported it again.', $key, $literal, $file)
                    : \sprintf('Fixed "%s": wrote %s in %s, and imported again %s.', $key, $literal, $file, implode(', ', $payloads)));
            }
            $target = $this->importTarget($fixtures, $file);
            if (null === $target) {
                return $this->notes($api, null, [\sprintf('Written in %s, but there is no request next to it to import: import the example that uses it.', $file)]);
            }
            $results = $this->importFamily($api, $fixtures, $target, true, true);
        } catch (\InvalidArgumentException $e) {
            return $this->notes($api, null, ['Not fixed: '.$e->getMessage()]);
        }

        return $this->notes($api, \sprintf('Fixed "%s": wrote %s in %s, and imported %s.', $key, $literal, $file, implode(', ', array_map(static fn (array $r) => $r[0], $results))));
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
        return $this->page('generate', ['api' => $api, 'result' => null, 'progress' => $this->progress($api), 'command' => $this->commandLine('api-forge:generate', $api)], 'Generate');
    }

    private function generate(string $api): Response
    {
        $result = $this->services->generate->run($api);
        $result = new GenerateResult(DescriptorErrors::explainAll($result->errors, $this->config->projectDir), $result->review, $result->changes, $result->files);

        return $this->page('generate', ['api' => $api, 'result' => $result, 'progress' => $this->progress($api), 'command' => $this->commandLine('api-forge:generate', $api)], 'Generate');
    }

    private function doctor(string $api): Response
    {
        [$code, $output] = $this->runner->run([...$this->config->console, 'api-forge:doctor', $api, '--no-ansi', '--no-interaction'], $this->config->projectDir);
        // What the dashboard shows of it: the files are the state
        $marker = $this->doctorMarker($api);
        if (is_dir(\dirname($marker)) || @mkdir(\dirname($marker), 0777, true) || is_dir(\dirname($marker))) {
            @file_put_contents($marker, json_encode(['time' => time(), 'code' => $code]));
        }

        return $this->doctorPage($api, ['code' => $code, 'output' => trim($output)]);
    }

    /**
     * @param ?array{code: int, output: string} $result
     */
    private function doctorPage(string $api, ?array $result): Response
    {
        $descriptor = $this->services->store->loadApi($api) ?? [];

        // The generated code exists, but the application that runs the command does not load it
        $hint = null !== $result && str_contains($result['output'], 'No doctor for')
            ? 'The command ran in your application, which does not know this API: it has not loaded the generated code of "'.$api.'" (its HTTP client configuration and its services). In an application, generate it (step 4) and make sure config/packages/api_'.$api.'.yaml and the generated classes are loaded. In a bundle built on api-forge, the provider has to be enabled in its configuration.'
            : null;

        return $this->page('doctor', ['api' => $api, 'result' => $result, 'hint' => $hint, 'command' => $this->commandLine('api-forge:doctor', $api), 'keyEnv' => $descriptor['auth']['env'] ?? null, 'hostEnv' => $descriptor['base_uri_env'] ?? null], 'Doctor');
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
    private function checkFile(string $area, string $name, string $content, ?FixtureDirectory $examples = null): void
    {
        match (strtolower(pathinfo($name, \PATHINFO_EXTENSION))) {
            'json' => $this->checkJson($content),
            'curl' => $this->services->curl->parse($content),
            'php', 'array' => $this->arrayFile($content),
            'yaml' => $this->checkYaml($content),
            default => null,
        };
        if ('examples' === $area && null !== $examples && null !== $problem = $this->listItemProblem($examples, $name, $content)) {
            throw new \InvalidArgumentException($problem);
        }
    }

    /**
     * A data file used as one item of a list (["payments.array"]) must hold one item: a list there makes a list of
     * lists. Said before the file is saved.
     */
    private function listItemProblem(FixtureDirectory $fixtures, string $name, string $content, bool $usedAsItem = false): ?string
    {
        if (1 !== preg_match('/\.(json|array)$/', $name)) {
            return null;
        }
        if (!$usedAsItem) {
            foreach ($fixtures->names() as $other) {
                if ($other !== $name && 1 === preg_match('/\.(json|array|php)$/', $other) && 1 === preg_match('/\[\s*["\']'.preg_quote($name, '/').'["\']\s*\]/', $fixtures->read($other))) {
                    $usedAsItem = true;
                    break;
                }
            }
        }
        if (!$usedAsItem) {
            return null;
        }
        try {
            $data = str_ends_with($name, '.json') ? $this->services->decoder->decode($content) : PhpLiteral::evaluate($content);
        } catch (\Throwable) {
            return null;
        }
        if (\is_array($data) && [] !== $data && array_is_list($data) && [] === array_filter($data, static fn ($item) => !\is_array($item))) {
            return \sprintf('%s is used as one item of a list (["%s"]), but it holds a list: that makes a list of lists. Remove the outer brackets, so that the file holds one item.', $name, $name);
        }

        return null;
    }

    /**
     * The example files that can't be read (edited by hand, outside the interface): JSON, arrays.
     *
     * @return array<string, string> file => error
     */
    private function unreadableExamples(FixtureDirectory $fixtures): array
    {
        $unreadable = [];
        foreach ($fixtures->names() as $name) {
            if (1 !== preg_match('/\.(json|array|php)$/', $name)) {
                continue;
            }
            try {
                str_ends_with($name, '.json') ? $this->checkJson($fixtures->read($name)) : $this->arrayFile($fixtures->read($name));
            } catch (\InvalidArgumentException $e) {
                $unreadable[$name] = $e->getMessage();
            }
        }

        return $unreadable;
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
     * Where to fix what a review note is about: the files of its operation that hold the part concerned (the body, the
     * response, the request), at the line the note gives for a docs page, and as a last resort its descriptor.
     *
     * @return array{descriptor: ?array{label: string, url: string}, examples: list<array{label: string, url: string, name: string, role: string, key: string}>}
     */
    private function relatedFiles(string $api, string $note): array
    {
        $result = ['descriptor' => null, 'examples' => []];
        $link = static fn (string $api, string $area, string $name, int $line = 0): string => '/api/'.$api.'/file?'.http_build_query(['name' => $name, 'area' => $area] + ($line > 0 ? ['line' => $line] : []));

        $descriptors = $this->files($api, 'descriptors');
        if (1 !== preg_match('#/([A-Za-z0-9_.-]+\.yaml): #', $note, $matches) || !$descriptors->has($matches[1])) {
            return $result;
        }
        $result['descriptor'] = ['label' => $matches[1], 'url' => $link($api, 'descriptors', $matches[1])];
        $resource = basename($matches[1], '.yaml');
        // A note of an event: its payload example and the docs page of the payload
        if ('_webhooks' === $resource && 1 === preg_match('/: event "([^"]+)": /', $note, $event)) {
            $fixtures = new FixtureDirectory($this->config->fixturesDir($api));
            $line = 1 === preg_match('/\(line (\d+)/', $note, $lines) ? (int) $lines[1] : 0;
            foreach (['json' => ['response', 'the payload example'], 'docs.md' => ['responseDocs', 'the docs page of the payload']] as $ext => [$key, $role]) {
                $name = 'webhook-'.$event[1].'.'.$ext;
                if ($fixtures->has($name)) {
                    $result['examples'][] = ['label' => $name, 'name' => $name, 'role' => $role, 'key' => $key, 'url' => $link($api, 'examples', $name, 'docs.md' === $ext ? $line : 0)];
                }
            }

            return $result;
        }
        if (str_starts_with($resource, '_')) {
            return $result;
        }

        $line = 1 === preg_match('/\(line (\d+)/', $note, $lines) ? (int) $lines[1] : 0;
        $operation = 1 === preg_match('/operation "([^"]+)"/', $note, $ops) ? $ops[1] : null;
        $scope = self::scopeOf($note);
        $names = [];
        foreach ((new FixtureDirectory($this->config->fixturesDir($api)))->names() as $name) {
            if (1 === preg_match('/^([a-z0-9]+)-'.preg_quote($resource, '/').'(-[a-z0-9-]+)?(\.body)?\.(curl|json|array|php|docs\.md)$/', $name, $m) && !str_contains($name, '-type.')) {
                $body = '' !== ($m[3] ?? '') || str_contains($name, '.body.');
                $ext = $m[4];
                $role = match (true) {
                    'curl' === $ext => ['request', 'the request'],
                    'php' === $ext => ['array', 'the array example'],
                    $body && 'docs.md' === $ext => ['bodyDocs', 'the docs page of the body'],
                    $body => ['body', 'the body example'],
                    'docs.md' === $ext => ['responseDocs', 'the docs page of the response'],
                    default => ['response', 'the response example'],
                };
                $inScope = match ($scope) {
                    'body' => $body,
                    'request' => \in_array($ext, ['curl', 'php'], true),
                    'api' => false,
                    default => !$body && !\in_array($ext, ['curl'], true),
                };
                if ($inScope && (null === $operation || $m[1] === $operation)) {
                    $names[] = ['name' => $name, 'role' => $role[1], 'key' => $role[0]];
                }
            }
        }
        foreach ($names as $entry) {
            $result['examples'][] = ['label' => $entry['name'], 'name' => $entry['name'], 'role' => $entry['role'], 'key' => $entry['key'], 'url' => $link($api, 'examples', $entry['name'], str_ends_with($entry['name'], 'docs.md') ? $line : 0)];
        }

        return $result;
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
        return new Response(200, $this->view->render($template, $vars + ['token' => $this->token(), 'projectDir' => $this->config->projectDir, 'editor' => \in_array($template, ['file', 'api', 'notes', 'example_form', 'type_form', 'example_result', 'endpoint_request', 'endpoint_response', 'endpoint_import', 'webhook_form', 'webhook_result', 'webhooks'], true)], $title));
    }

    private function error(int $status, string $message): Response
    {
        return new Response($status, $this->view->render('error', ['message' => $message, 'status' => $status, 'token' => ''], 'Error'));
    }
}
