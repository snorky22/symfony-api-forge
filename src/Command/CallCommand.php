<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Core\AbstractResource;
use Snorky22\ApiForge\Core\ApiException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'api-forge:call',
    description: 'Calls a method of an API resource (hand-written or generated) and prints the response',
    help: <<<'HELP'
        Sends a real request, with the scoped client of the resource (its base URI and key, e.g. from .env.local):

          <info>php %command.full_name%</info>
          <info>php %command.full_name% Paddle\\Prices list '{"per_page": 5}'</info>
          <info>php %command.full_name% Paddle\\Prices get pri_01h…</info>

        The class is relative to api_forge.rest.namespace (App\API), or fully qualified. The arguments are the method's,
        in order: JSON ({…}, […], numbers, true, false, null) or plain text. What is not given is asked.
        HELP,
)]
final class CallCommand
{
    /**
     * @param ServiceLocator<AbstractResource> $resources   the API resources, by class
     * @param array<string, string>            $unavailable the resources depending on a missing service: class => service
     */
    public function __construct(
        private readonly ServiceLocator $resources,
        private readonly string $namespace,
        private readonly array $unavailable = [],
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        #[Argument('Class of the resource, e.g. "Paddle\Prices"')] ?string $class = null,
        #[Argument('Method, e.g. "list"')] ?string $method = null,
        #[Argument('Arguments of the method, in order: JSON or plain text')] array $arguments = [],
        #[Option('Items printed at most when the method returns an iterator (e.g. iterate())')] int $max = 20,
    ): int {
        $interactive = $input->isInteractive();
        $names = $this->names();
        foreach ($this->unavailable as $id => $absent) {
            $io->note(\sprintf('%s is left out: it needs the service "%s", which does not exist.', $this->relative($id), $absent));
        }
        if ([] === $names) {
            $io->error('No API resource found: create one with api-forge:make:resource.');

            return Command::FAILURE;
        }

        if (null === $class && $interactive) {
            $question = new Question('Resource');
            $question->setAutocompleterValues(array_keys($names));
            $question->setValidator(static fn (?string $value): string => isset($names[trim((string) $value, '\\ ')]) ? trim((string) $value, '\\ ') : throw new \InvalidArgumentException(\sprintf('Unknown resource. Known: %s.', implode(', ', array_keys($names)))));
            $class = $io->askQuestion($question);
        }
        $service = null === $class ? null : $names[trim($class, '\\ ')] ?? null;
        foreach ($this->unavailable as $id => $absent) {
            if (null !== $class && \in_array(trim($class, '\\ '), [$id, $this->relative($id)], true)) {
                $io->error(\sprintf('%s needs the service "%s", which does not exist: declare it (a scoped HTTP client, e.g. with api-forge:make:resource %s).', $id, $absent, $this->relative($id)));

                return Command::FAILURE;
            }
        }
        if (null === $service) {
            $io->error(null === $class ? \sprintf('Give the resource. Known: %s.', implode(', ', array_keys($names))) : \sprintf('Unknown resource "%s". Known: %s.', $class, implode(', ', array_keys($names))));

            return Command::FAILURE;
        }

        $methods = self::methods($service);
        if (null === $method && $interactive) {
            $method = $io->askQuestion(new ChoiceQuestion('Method', array_keys($methods)));
        }
        if (null === $method || !isset($methods[$method])) {
            $io->error(\sprintf('Unknown method "%s". Known: %s.', $method, implode(', ', array_keys($methods))));

            return Command::FAILURE;
        }

        $values = [];
        foreach ($methods[$method]->getParameters() as $i => $parameter) {
            $type = (string) $parameter->getType();
            if (\array_key_exists($i, $arguments)) {
                $values[] = self::value($arguments[$i]);
                continue;
            }
            if (!$interactive) {
                if ($parameter->isOptional()) {
                    break;
                }
                $io->error(\sprintf('Missing argument $%s (%s).', $parameter->getName(), $type));

                return Command::FAILURE;
            }
            $label = \sprintf('$%s (%s%s)', $parameter->getName(), $type, $parameter->isOptional() ? ', empty: '.self::export($parameter->getDefaultValue()) : '');
            $answer = $io->ask($label, null, $parameter->isOptional() ? null : static fn (?string $value): string => null === $value || '' === $value ? throw new \InvalidArgumentException('A value is required.') : $value);
            if (null === $answer || '' === $answer) {
                // optional: its default, and the following ones too unless asked
                $values[] = $parameter->getDefaultValue();
                continue;
            }
            $values[] = self::value($answer);
        }

        $io->text(\sprintf('<comment>%s::%s(%s)</comment>', $service::class, $method, implode(', ', array_map(self::export(...), $values))));
        try {
            $result = $service->{$method}(...$values);
        } catch (ApiException $e) {
            $io->error($e->getMessage());
            if ([] !== $e->content) {
                $output->writeln(self::json($e->content), OutputInterface::OUTPUT_RAW);
            }

            return Command::FAILURE;
        }

        if ($result instanceof \Traversable) {
            $items = [];
            foreach ($result as $item) {
                if (\count($items) === $max) {
                    $io->note(\sprintf('More items: the first %d are printed (--max).', $max));
                    break;
                }
                $items[] = $item;
            }
            $result = $items;
        }
        if (null === $result) {
            $io->success('Done (no content).');
        } else {
            $output->writeln(self::json($result), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    /**
     * The resources by the name to type: relative to the namespace of the hand-written ones, else fully qualified.
     *
     * @return array<string, AbstractResource>
     */
    private function names(): array
    {
        $names = [];
        foreach (array_keys($this->resources->getProvidedServices()) as $id) {
            $names[$this->relative($id)] = $this->resources->get($id);
        }
        ksort($names);

        return $names;
    }

    /**
     * A class relative to the namespace of the hand-written resources, else as it is.
     */
    private function relative(string $id): string
    {
        return str_starts_with($id, $this->namespace.'\\') ? substr($id, \strlen($this->namespace) + 1) : $id;
    }

    /**
     * The public methods of a resource, its own and those of its traits.
     *
     * @return array<string, \ReflectionMethod>
     */
    private static function methods(AbstractResource $resource): array
    {
        $methods = [];
        foreach ((new \ReflectionClass($resource))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && !$method->isConstructor() && !is_a(AbstractResource::class, $method->getDeclaringClass()->getName(), true)) {
                $methods[$method->getName()] = $method;
            }
        }
        ksort($methods);

        return $methods;
    }

    /**
     * An argument typed on the command line: JSON when it reads as such, else the text.
     */
    private static function value(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    private static function export(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: get_debug_type($value);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
