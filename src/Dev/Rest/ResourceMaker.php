<?php

namespace Snorky22\ApiForge\Dev\Rest;

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
    public const ACTIONS = [
        'list' => ['Listable', 'Lists'],
        'get' => ['Readable', 'Reads'],
        'create' => ['Creatable', 'Creates'],
        'update' => ['Updatable', 'Updates'],
        'delete' => ['Deletable', 'Deletes'],
    ];

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
     */
    public function make(string $class, string $path, string $client, array $actions, ?string $envelope = null, ?string $docs = null): string
    {
        if ([] !== $unknown = array_diff($actions, array_keys(self::ACTIONS))) {
            throw new \InvalidArgumentException(\sprintf('Unknown action(s): %s. Known: %s.', implode(', ', $unknown), implode(', ', array_keys(self::ACTIONS))));
        }
        $actions = array_values(array_intersect(array_keys(self::ACTIONS), $actions));
        $class = trim($class, '\\');
        $namespace = str_contains($class, '\\') ? substr($class, 0, strrpos($class, '\\')) : null;
        $short = self::shortName($class);
        $item = $this->itemType($class);
        $one = null === $envelope ? $item : \sprintf('array{%s: %s, ...}', $envelope, $item);
        $many = null === $envelope ? \sprintf('list<%s>', $item) : \sprintf('array{%s: list<%s>, ...}', $envelope, $item);

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
            $implements[] = match ($action) {
                'list' => \sprintf(' * @implements Listable<%s>', $many),
                'get' => \sprintf(' * @implements Readable<%s>', $one),
                'create' => \sprintf(' * @implements Creatable<New%s, %s>', $item, $one),
                'update' => \sprintf(' * @implements Updatable<%sChanges, %s>', $item, $one),
                'delete' => null,
            };
            if ('create' === $action) {
                $types[] = \sprintf(' * @phpstan-type New%s array<string, mixed>', $item);
            } elseif ('update' === $action) {
                $types[] = \sprintf(' * @phpstan-type %sChanges array<string, mixed>', $item);
            }
        }

        $doc = [];
        if (null !== $docs) {
            $doc[] = ' * @see '.$docs;
            $doc[] = ' *';
        }
        $doc[] = ' * TODO: replace each array<string, mixed> with the keys you use, e.g. array{id: string, status: string, ...}';
        $doc[] = ' * (the "..." says the API sends more). api-forge:json-to-php --shape turns a JSON example of the docs';
        $doc[] = ' * into a shape, to trim. The new item: the fields you send; the changes: the same, all optional ("key?:").';
        $doc[] = ' *';
        array_push($doc, ...$types);
        if ([] !== $implements = array_values(array_filter($implements))) {
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

    private static function shortName(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return end($parts);
    }
}
