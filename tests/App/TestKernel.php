<?php

namespace Snorky22\ApiForge\Tests\App;

use Psr\Log\NullLogger;
use Snorky22\ApiForge\ApiForgeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Application used by the tests: the bundle, and the Lemon Squeezy example of examples/lemonsqueezy/.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const WEBHOOK_SECRET = 'test-webhook-secret';
    /** The webhook inbox of the tests, emptied by them */
    public const INBOX = '/tmp/symfony-api-forge-tests/inbox';
    private const EXAMPLE = __DIR__.'/../../examples/lemonsqueezy';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ApiForgeBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/symfony-api-forge-tests/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/symfony-api-forge-tests/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [ConsumeRemoteEventMessage::class => 'async'],
            ],
            // No network in the tests: the APIs answer from ApiMock
            'http_client' => ['mock_response_factory' => ApiMock::class],
        ]);

        $container->extension('api_forge', ['webhook_inbox' => ['directory' => self::INBOX]]);

        // The example application: generated services, webhook routing, consumer
        $container->import(self::EXAMPLE.'/config/packages/api_lemonsqueezy.yaml');
        $container->parameters()->set('env(LEMONSQUEEZY_WEBHOOK_SECRET)', self::WEBHOOK_SECRET);
        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure()->load('App\\', self::EXAMPLE.'/src/');
        // Rejected webhooks are logged as errors: keep the test output clean
        $services->set('logger', NullLogger::class);
        $services->set(ApiMock::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@FrameworkBundle/Resources/config/routing/webhook.php')->prefix('/webhook');
    }
}
