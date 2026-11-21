<?php

namespace Snorky22\ApiForge\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Command\CallCommand;
use Snorky22\ApiForge\DependencyInjection\ResourceLocatorPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;

final class ResourceLocatorPassTest extends TestCase
{
    public function testResourcesWithAMissingServiceAreLeftOut(): void
    {
        $container = new ContainerBuilder();
        $container->register('kagi.client', MockHttpClient::class);
        $container->register('App\API\Kagi\Search')->addArgument(new Reference('kagi.client'))->addTag('api_forge.resource');
        $container->register('App\API\Paddle\Prices')->addArgument(new Reference('paddle.client'))->addTag('api_forge.resource');
        $container->register(CallCommand::class, CallCommand::class)->setArguments([null, 'App\API', []]);

        (new ResourceLocatorPass())->process($container);

        $arguments = $container->getDefinition(CallCommand::class)->getArguments();
        self::assertSame(['App\API\Paddle\Prices' => 'paddle.client'], $arguments[2]);
        $locator = $container->getDefinition((string) $arguments[0]);
        self::assertSame(['App\API\Kagi\Search'], array_keys($locator->getArgument(0)));
    }
}
