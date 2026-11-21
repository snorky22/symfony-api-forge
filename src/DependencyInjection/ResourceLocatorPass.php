<?php

namespace Snorky22\ApiForge\DependencyInjection;

use Snorky22\ApiForge\Command\CallCommand;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the API resources (tag api_forge.resource) to api-forge:call, except those depending on a service that does
 * not exist (e.g. a scoped client not declared yet): kept, they would stop the container from compiling, while an
 * unused service is otherwise removed silently. Those are given with the missing service, to be said.
 *
 * Runs before the removing passes, when the autowired arguments are references.
 */
final class ResourceLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CallCommand::class)) {
            return;
        }

        $available = $missing = [];
        foreach (array_keys($container->findTaggedServiceIds('api_forge.resource')) as $id) {
            $definition = $container->getDefinition($id);
            if ($definition->isAbstract()) {
                continue;
            }
            if (null !== $absent = self::missingService($container, $definition)) {
                $missing[$id] = $absent;
            } else {
                $available[$id] = new Reference($id);
            }
        }

        // By position: the named arguments are resolved before this pass
        $container->getDefinition(CallCommand::class)
            ->replaceArgument(0, ServiceLocatorTagPass::register($container, $available))
            ->replaceArgument(2, $missing);
    }

    /**
     * The first service a value of a definition (arguments, properties, method calls, nested definitions) needs that
     * does not exist, or null.
     */
    private static function missingService(ContainerBuilder $container, mixed $value): ?string
    {
        if ($value instanceof Reference) {
            return ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE === $value->getInvalidBehavior() && !$container->has((string) $value) ? (string) $value : null;
        }
        if ($value instanceof Definition) {
            $value = [$value->getArguments(), $value->getProperties(), array_column($value->getMethodCalls(), 1)];
        } elseif ($value instanceof ArgumentInterface) {
            $value = $value->getValues();
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (null !== $missing = self::missingService($container, $item)) {
                    return $missing;
                }
            }
        }

        return null;
    }
}
