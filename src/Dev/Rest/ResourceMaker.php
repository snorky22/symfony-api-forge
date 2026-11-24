<?php

namespace Snorky22\ApiForge\Dev\Rest;

use Snorky22\ApiForge\Dev\ConfigDeclarations;
use Symfony\Component\Finder\Finder;
use Symfony\Component\String\Inflector\EnglishInflector;

use function Symfony\Component\String\u;

/**
 * Writes the code of a hand-written REST resource (see Snorky22\ApiForge\Core\Rest), with TODOs for what only the
 * API's docs can tell: the keys you read and send.
 */
final class ResourceMaker
{
    /**
     * The actions, in the order of the generated code: interface, trait.
     */
    private const TODO_BASE_URI = '   # TODO: the base URI of the API';

    public const ACTIONS = [
        'list' => ['Listable', 'Lists'],
        'get' => ['Readable', 'Reads'],
        'create' => ['Creatable', 'Creates'],
        'update' => ['Updatable', 'Updates'],
        'delete' => ['Deletable', 'Deletes'],
    ];

    /**
     * The names to suggest when asking for a class: the namespaces ("Paddle\\") and the classes ("Paddle\\Prices")
     * found in a directory, relative to it.
     *
     * @return list<string>
     */
    public function existingNames(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $names = [];
        foreach ((new Finder())->in($directory)->name('*.php')->files() as $file) {
            $parts = explode('/', substr($file->getRelativePathname(), 0, -4));
            for ($i = 1; $i < \count($parts); ++$i) {
                $names[] = implode('\\', \array_slice($parts, 0, $i)).'\\';
            }
            $names[] = implode('\\', $parts);
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * An endpoint as found in the docs, normalized: "https://" added when missing, the query string and a trailing
     * item placeholder ({id}, :id, <id>) removed. "api.paddle.com/prices/{price_id}?x=1" gives
     * "https://api.paddle.com/prices".
     *
     * @throws \InvalidArgumentException when there is no host
     */
    public function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) {
            $endpoint = 'https://'.ltrim($endpoint, '/');
        }
        $parts = parse_url($endpoint);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !str_contains($parts['host'], '.')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an endpoint URL, e.g. https://api.example.com/prices.', $endpoint));
        }
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $segment) => '' !== $segment));
        if ([] !== $segments && preg_match('/^(\{\w+\}|:\w+|<\w+>)$/', end($segments))) {
            array_pop($segments);
        }

        return \sprintf('%s://%s%s%s', strtolower($parts['scheme']), strtolower($parts['host']), isset($parts['port']) ? ':'.$parts['port'] : '', [] === $segments ? '' : '/'.implode('/', $segments));
    }

    /**
     * Default path of a normalized endpoint: its last segment, "prices" for "https://api.paddle.com/prices".
     */
    public function pathOf(string $endpoint): ?string
    {
        $path = trim((string) parse_url($endpoint, \PHP_URL_PATH), '/');

        return '' === $path ? null : substr($path, (int) strrpos('/'.$path, '/'));
    }

    /**
     * Base URI of the API: the normalized endpoint without the path of the resource, "https://api.paddle.com/" for
     * "https://api.paddle.com/prices" and "prices"; null when the endpoint does not end with the path.
     */
    public function baseUri(string $endpoint, string $path): ?string
    {
        $path = trim($path, '/');
        if ('' === $path) {
            return $endpoint.'/';
        }

        return str_ends_with($endpoint, '/'.$path) ? substr($endpoint, 0, -\strlen($path)) : null;
    }

    /**
     * Name of the configuration file of a client: "paddle.client" gives "paddle_client.yaml" (not "api_paddle.yaml",
     * which api-forge:generate writes and overwrites).
     */
    public function clientFile(string $client): string
    {
        return u($client)->replace('.', '_')->snake()->append('.yaml')->toString();
    }

    /**
     * The configuration of a scoped client, yours to edit. The base URI and the API key come from the environment, so
     * that another one (a sandbox) is a line of .env.local.
     *
     * @param ?string $baseUri null when unknown: a placeholder, marked TODO
     */
    public function clientConfiguration(string $client, ?string $baseUri): string
    {
        $key = $this->envVariable($client);
        $base = $this->envVariable($client, 'BASE_URI');

        return \sprintf(<<<'YAML'
            # Scoped HTTP client "%1$s", written by api-forge:make:resource: yours to edit (the authentication
            # depends on the API: auth_bearer, auth_basic or headers). The base URI and the API key come from the
            # environment, e.g. in .env.local:
            #     %3$s=https://sandbox-api.example.com/
            #     %2$s=...
            # The key is empty by default, so that the application boots without it.

            parameters:
                env(%3$s): '%4$s'%5$s
                env(%2$s): ''
            framework:
                http_client:
                    scoped_clients:
                        %1$s:
                            base_uri: '%%env(%3$s)%%'
                            auth_bearer: '%%env(%2$s)%%'

            YAML, $client, $key, $base, $baseUri ?? 'https://api.example.com/', null === $baseUri ? self::TODO_BASE_URI : '');
    }

    /**
     * The configuration of a client written without its base URI (see clientConfiguration()), with it; null when the
     * base URI is not the placeholder any more.
     */
    public function withBaseUri(string $configuration, string $baseUri): ?string
    {
        $placeholder = "'https://api.example.com/'".self::TODO_BASE_URI;

        return str_contains($configuration, $placeholder) ? str_replace($placeholder, \sprintf("'%s'", $baseUri), $configuration) : null;
    }

    /**
     * The YAML file of directories (and their subdirectories) declaring a scoped client for every environment, or
     * null; a file written by api-forge:generate counts too (the client is then reused, not declared twice).
     *
     * @param string|list<string> $directories
     */
    public function declaringFile(string|array $directories, string $client): ?string
    {
        $declarations = new ConfigDeclarations();

        return $declarations->everywhere($declarations->clients($directories))[$client][0]['file'] ?? null;
    }

    /**
     * The scoped client already declared (for every environment) in directories for the API of a class, whatever the
     * case and separators ("LemonSqueezy\Customers" and "lemonsqueezy.client"), or null.
     *
     * @param string|list<string> $directories
     */
    public function declaredClient(string|array $directories, string $class): ?string
    {
        $parts = explode('\\', trim($class, '\\'));
        $declarations = new ConfigDeclarations();

        return $declarations->matching($declarations->everywhere($declarations->clients($directories)), $parts[\count($parts) > 1 ? \count($parts) - 2 : 0], '.client')[0] ?? null;
    }

    /**
     * Environment variable of a client, from its service id: "paddle.client" gives "PADDLE_API_KEY", or
     * "PADDLE_BASE_URI" for the suffix "BASE_URI".
     */
    public function envVariable(string $client, string $suffix = 'API_KEY'): string
    {
        return u(explode('.', $client)[0])->snake()->upper()->append('_', $suffix)->toString();
    }

    /**
     * Default path of a class: "LicenseKeys" gives "license-keys".
     */
    public function defaultPath(string $class): string
    {
        return u(self::shortName($class))->snake()->replace('_', '-')->toString();
    }

    /**
     * Default client service of a class: "Paddle\Prices" gives "paddle.client".
     */
    public function defaultClient(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return u($parts[\count($parts) > 1 ? \count($parts) - 2 : 0])->snake()->append('.client')->toString();
    }

    /**
     * Name of the type of one item: "Prices" gives "Price"; "Search" gives "SearchItem".
     */
    public function itemType(string $class): string
    {
        $short = self::shortName($class);
        // Of the inflector's candidates ("Prices": Prex, Prix, Price), the one sharing the longest start with the word
        $singular = $short;
        $best = -1;
        foreach ((new EnglishInflector())->singularize($short) as $candidate) {
            for ($common = 0; $common < min(\strlen($candidate), \strlen($short)) && $candidate[$common] === $short[$common]; ++$common) {
            }
            if ($common > $best) {
                [$singular, $best] = [$candidate, $common];
            }
        }

        return $singular === $short ? $short.'Item' : $singular;
    }

    /**
     * @param string       $class    fully qualified class name
     * @param list<string> $actions  keys of ACTIONS
     * @param ?string      $envelope key wrapping the responses, e.g. "data", or null
     * @param ?string      $docs     URL of the API's docs for this resource
     * @param ?string      $endpoint endpoint of the resource, normalized (see normalizeEndpoint())
     */
    public function make(string $class, string $path, string $client, array $actions, ?string $envelope = null, ?string $docs = null, ?string $endpoint = null): string
    {
        if ([] !== $unknown = array_diff($actions, array_keys(self::ACTIONS))) {
            throw new \InvalidArgumentException(\sprintf('Unknown action(s): %s. Known: %s.', implode(', ', $unknown), implode(', ', array_keys(self::ACTIONS))));
        }
        $actions = array_values(array_intersect(array_keys(self::ACTIONS), $actions));
        $class = trim($class, '\\');
        $namespace = str_contains($class, '\\') ? substr($class, 0, strrpos($class, '\\')) : null;
        $short = self::shortName($class);
        $item = $this->itemType($class);

        $uses = [
            'Snorky22\ApiForge\Core\Rest\AbstractRestResource',
            'Symfony\Component\DependencyInjection\Attribute\Autowire',
            'Symfony\Contracts\HttpClient\HttpClientInterface',
        ];
        $interfaces = $traits = [];
        foreach ($actions as $action) {
            [$interface, $trait] = self::ACTIONS[$action];
            $uses[] = 'Snorky22\ApiForge\Core\Rest\\'.$interface;
            $uses[] = 'Snorky22\ApiForge\Core\Rest\\'.$trait;
            $interfaces[] = $interface;
            $traits[] = $trait;
        }
        sort($uses);
        sort($traits);

        $types = [\sprintf(' * @phpstan-type %s array<string, mixed>', $item)];
        $implements = [];
        foreach ($actions as $action) {
            if (null !== $line = self::implementsLine($action, $item, $envelope)) {
                $implements[] = $line;
            }
            if (null !== $line = self::typeLine($action, $item)) {
                $types[] = $line;
            }
        }

        $doc = [];
        if (null !== $endpoint) {
            $doc[] = ' * Endpoint: '.$endpoint;
            $doc[] = ' *';
        }
        if (null !== $docs) {
            $doc[] = ' * @see '.$docs;
            $doc[] = ' *';
        }
        $doc[] = ' * TODO: replace each array<string, mixed> with the keys you use, e.g. array{id: string, status: string, ...}';
        $doc[] = ' * (the "..." says the API sends more). api-forge:json-to-php --shape turns a JSON example of the docs';
        $doc[] = ' * into a shape, to trim. The new item: the fields you send; the changes: the same, all optional ("key?:").';
        $doc[] = ' *';
        array_push($doc, ...$types);
        if ([] !== $implements) {
            $doc[] = ' *';
            array_push($doc, ...$implements);
        }

        $code = "<?php\n\n";
        if (null !== $namespace) {
            $code .= \sprintf("namespace %s;\n\n", $namespace);
        }
        $code .= implode('', array_map(static fn (string $use) => \sprintf("use %s;\n", $use), $uses))."\n";
        $code .= "/**\n".implode("\n", $doc)."\n */\n";
        $code .= \sprintf('final class %s extends AbstractRestResource', $short);
        $code .= [] === $interfaces ? "\n{\n" : \sprintf(" implements %s\n{\n", implode(', ', $interfaces));
        $code .= implode('', array_map(static fn (string $trait) => \sprintf("    use %s;\n", $trait), $traits));
        $code .= [] === $traits ? '' : "\n";
        $code .= \sprintf(<<<'PHP'
                public function __construct(#[Autowire(service: '%s')] HttpClientInterface $client)
                {
                    parent::__construct($client);
                }

                // TODO: the other actions of the API you use, on top of $this->request(), e.g.
                // public function activate(int|string $id): array
                // {
                //     return $this->request('POST', $this->itemPath().'/activate', ['id' => $id]);
                // }

                protected function path(): string
                {
                    return '%s';
                }
            }

            PHP, $client, $path);

        return $code;
    }

    /**
     * The actions an existing class has: its interface in the implements clause, or its trait used.
     *
     * @return list<string> keys of ACTIONS
     */
    public function implementedActions(string $code): array
    {
        $declaration = self::declaration($code);
        $implements = preg_match('/\bimplements\s+([^{]+)/', $declaration, $m) ? array_map('trim', explode(',', $m[1])) : [];
        $traits = self::traits($code);
        $found = [];
        foreach (self::ACTIONS as $action => [$interface, $trait]) {
            if (\in_array($interface, $implements, true) || \in_array($trait, $traits, true)) {
                $found[] = $action;
            }
        }

        return $found;
    }

    /**
     * The key wrapping the responses, as the @implements lines of an existing class say it: a string, null for none,
     * or false when the class has no such line to tell.
     */
    public function envelopeOf(string $code): string|false|null
    {
        if (!preg_match('/@implements\s+(?:Listable|Readable|Creatable|Updatable)<(.*)>\s*$/m', $code, $m)) {
            return false;
        }

        return preg_match('/array\{(\w+):\s*(?:list<)?\w+>?,\s*\.\.\.\}\s*$/', $m[1], $e) ? $e[1] : null;
    }

    /**
     * The endpoint an existing class records ("Endpoint: https://…" in its docblock), or null.
     */
    public function endpointOf(string $code): ?string
    {
        return preg_match('/^ \* Endpoint: (\S+)$/m', $code, $m) ? $m[1] : null;
    }

    /**
     * Records the endpoint in the docblock of an existing class, as its first line (a description comes before the
     * tags); replaces the one it has.
     */
    public function withEndpoint(string $code, string $endpoint): string
    {
        if (null !== $this->endpointOf($code)) {
            return (string) preg_replace('/^ \* Endpoint: \S+$/m', ' * Endpoint: '.$endpoint, $code, 1);
        }
        $declaration = self::declaration($code);
        $before = substr($code, 0, (int) strpos($code, $declaration));
        if (false === $open = strrpos($before, "/**\n")) {
            return substr_replace($code, \sprintf("/**\n * Endpoint: %s\n */\n", $endpoint), (int) strpos($code, $declaration), 0);
        }
        $after = $open + 4;
        $blank = str_starts_with(substr($code, $after), ' */') || str_starts_with(substr($code, $after), " *\n") ? '' : " *\n";

        return substr_replace($code, \sprintf(" * Endpoint: %s\n%s", $endpoint, $blank), $after, 0);
    }

    /**
     * The path an existing class returns from path(), or null when it is not a plain string.
     */
    public function pathIn(string $code): ?string
    {
        return preg_match("/function path\\(\\): string\\s*\\{\\s*return '([^']*)';/", $code, $m) ? $m[1] : null;
    }

    /**
     * The client service an existing class is autowired with, or null.
     */
    public function clientIn(string $code): ?string
    {
        return preg_match("/#\\[Autowire\\(service: '([^']+)'\\)\\]/", $code, $m) ? $m[1] : null;
    }

    /**
     * Adds actions to the code of an existing resource: the use statements, the interfaces, the traits and the
     * annotations. Everything else (shapes filled in, methods, comments) is kept as it is.
     *
     * @param string       $class   fully qualified class name
     * @param list<string> $actions keys of ACTIONS, not implemented yet
     */
    public function addActions(string $code, string $class, array $actions, ?string $envelope = null): string
    {
        if ([] !== $unknown = array_diff($actions, array_keys(self::ACTIONS))) {
            throw new \InvalidArgumentException(\sprintf('Unknown action(s): %s. Known: %s.', implode(', ', $unknown), implode(', ', array_keys(self::ACTIONS))));
        }
        $declaration = self::declaration($code);
        if (!preg_match('/\bextends\s+AbstractRestResource\b/', $declaration)) {
            throw new \InvalidArgumentException('The class does not extend AbstractRestResource: add the actions by hand.');
        }
        $actions = array_values(array_diff(array_intersect(array_keys(self::ACTIONS), $actions), $this->implementedActions($code)));
        if ([] === $actions) {
            return $code;
        }
        $item = $this->itemType($class);

        // use statements: all of them, sorted, where the first one was
        preg_match_all('/^use [^;]+;\n/m', substr($code, 0, (int) strpos($code, $declaration)), $m, \PREG_OFFSET_CAPTURE);
        $uses = array_map(static fn (array $use) => $use[0], $m[0]);
        foreach ($actions as $action) {
            foreach (self::ACTIONS[$action] as $name) {
                $uses[] = \sprintf("use Snorky22\\ApiForge\\Core\\Rest\\%s;\n", $name);
            }
        }
        $uses = array_unique($uses);
        sort($uses);
        if ([] !== $m[0]) {
            $first = $m[0][0][1];
            foreach (array_reverse($m[0]) as [$use, $offset]) {
                $code = substr_replace($code, '', $offset, \strlen($use));
            }
            $code = substr_replace($code, implode('', $uses), $first, 0);
        }

        // implements clause: the CRUD interfaces in the order of ACTIONS, the others after them
        $declaration = self::declaration($code);
        $current = preg_match('/\bimplements\s+([^{]+)/', $declaration, $m) ? array_map('trim', explode(',', $m[1])) : [];
        $crud = array_column(self::ACTIONS, 0);
        $wanted = array_merge($current, array_map(static fn (string $action) => self::ACTIONS[$action][0], $actions));
        $interfaces = array_merge(array_values(array_intersect($crud, $wanted)), array_values(array_diff($current, $crud)));
        $newDeclaration = rtrim(preg_replace('/\s+implements\s+[^{]+/', '', $declaration)).' implements '.implode(', ', $interfaces)."\n";
        $code = str_replace($declaration, $newDeclaration, $code);

        // traits: all of them, sorted, where the first one was, or at the top of the class
        $traits = self::traits($code);
        foreach ($actions as $action) {
            $traits[] = self::ACTIONS[$action][1];
        }
        $traits = array_unique($traits);
        sort($traits);
        $block = implode('', array_map(static fn (string $trait) => \sprintf("    use %s;\n", $trait), $traits));
        $body = strpos($code, '{', (int) strpos($code, $newDeclaration)) + 1;
        if (preg_match('/^(    use \w+;\n)+/m', $code, $m, \PREG_OFFSET_CAPTURE, $body)) {
            $code = substr_replace($code, $block, $m[0][1], \strlen($m[0][0]));
        } else {
            $code = substr_replace($code, "\n".$block."\n", $body, 1);
        }

        // annotations: types after the last @phpstan-type, @implements after the last @implements (or the types)
        $types = $implements = [];
        foreach ($actions as $action) {
            if (null !== $line = self::typeLine($action, $item)) {
                if (!preg_match('/@phpstan-type\s+'.preg_quote(substr($line, \strlen(' * @phpstan-type ')), '/').'/', $code)) {
                    $types[] = $line;
                }
            }
            if (null !== $line = self::implementsLine($action, $item, $envelope)) {
                $implements[] = $line;
            }
        }
        $code = self::insertAfterLast($code, '@phpstan-type', $types, null);
        $code = self::insertAfterLast($code, '@implements', $implements, '@phpstan-type');

        return $code;
    }

    private static function implementsLine(string $action, string $item, ?string $envelope): ?string
    {
        $one = null === $envelope ? $item : \sprintf('array{%s: %s, ...}', $envelope, $item);
        $many = null === $envelope ? \sprintf('list<%s>', $item) : \sprintf('array{%s: list<%s>, ...}', $envelope, $item);

        return match ($action) {
            'list' => \sprintf(' * @implements Listable<%s>', $many),
            'get' => \sprintf(' * @implements Readable<%s>', $one),
            'create' => \sprintf(' * @implements Creatable<New%s, %s>', $item, $one),
            'update' => \sprintf(' * @implements Updatable<%sChanges, %s>', $item, $one),
            default => null,
        };
    }

    private static function typeLine(string $action, string $item): ?string
    {
        return match ($action) {
            'create' => \sprintf(' * @phpstan-type New%s array<string, mixed>', $item),
            'update' => \sprintf(' * @phpstan-type %sChanges array<string, mixed>', $item),
            default => null,
        };
    }

    /**
     * Inserts docblock lines after the last line holding a tag (a tag spans its continuation lines, e.g. a multi-line
     * shape), or else after the last line holding the fallback tag, separated by an empty line.
     *
     * @param list<string> $lines
     */
    private static function insertAfterLast(string $code, string $tag, array $lines, ?string $fallback): string
    {
        if ([] === $lines) {
            return $code;
        }
        $end = strpos($code, '*/');
        if (false === $end) {
            return $code;
        }
        $doc = substr($code, 0, $end);
        $separator = '';
        $at = strrpos($doc, ' * '.$tag);
        if (false === $at && null !== $fallback) {
            $at = strrpos($doc, ' * '.$fallback);
            $separator = " *\n";
        }
        if (false === $at) {
            return substr_replace($code, " *\n".implode("\n", $lines)."\n ", $end, 0);
        }
        // the end of the tag: the next line that is empty or starts another tag, or the end of the docblock
        $next = preg_match('/\n \*(?: @|\s*\n|\/)/', $code, $m, \PREG_OFFSET_CAPTURE, $at + 1) ? $m[0][1] + 1 : $end;

        return substr_replace($code, $separator.implode("\n", $lines)."\n", $next, 0);
    }

    /**
     * The class declaration line(s), up to the opening brace.
     */
    private static function declaration(string $code): string
    {
        if (!preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+[^{]*/m', $code, $m)) {
            throw new \InvalidArgumentException('No class found in the file.');
        }

        return $m[0];
    }

    /**
     * @return list<string> the traits used at the top of the class
     */
    private static function traits(string $code): array
    {
        $declaration = self::declaration($code);
        $body = substr($code, (int) strpos($code, $declaration) + \strlen($declaration));
        preg_match_all('/^    use (\w+);$/m', $body, $m);

        return $m[1];
    }

    private static function shortName(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return end($parts);
    }
}
