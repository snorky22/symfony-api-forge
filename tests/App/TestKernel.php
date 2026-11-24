<?php

namespace Snorky22\ApiForge\Tests\App;

use Psr\Log\NullLogger;
use Snorky22\ApiForge\ApiForgeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Application used by the tests ("test": HTTP mocked) and by tests/console ("dev": real HTTP, .env.local): the
 * bundle, and the Lemon Squeezy example of examples/lemonsqueezy/.
 *
 * The hand-written REST resources of api-forge:make:resource go to test-API/src/API (App\API, git-ignored): a
 * scratch application directory, where the classes are autoloaded and autowired as in a real application; its
 * configuration (e.g. the scoped HTTP clients) goes to test-API/config/packages/*.yaml.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const WEBHOOK_SECRET = 'test-webhook-secret';
    /** The webhook inbox of the tests, emptied by them */
    public const INBOX = '/tmp/symfony-api-forge-tests/inbox';
    private const EXAMPLE = __DIR__.'/../../examples/lemonsqueezy';
    private const REST = __DIR__.'/../../test-API/src/API';
    private const REST_CONFIG = __DIR__.'/../../test-API/config/packages';

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

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $test = 'test' === $this->environment;
        $container->extension('framework', [
            'secret' => 'test',
            'test' => $test,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [ConsumeRemoteEventMessage::class => 'async'],
            ],
            // No network in the tests: the APIs answer from ApiMock. The console (tests/console, "dev") sends real
            // requests, with the keys of .env.local.
            'http_client' => $test ? ['mock_response_factory' => ApiMock::class] : [],
        ]);

        $container->extension('api_forge', [
            'webhook_inbox' => ['directory' => self::INBOX],
            'rest' => ['source_dir' => 'test-API/src/API', 'config_dir' => 'test-API/config/packages'],
        ]);

        // The example application: generated services, webhook routing, consumer
        $container->import(self::EXAMPLE.'/config/packages/api_lemonsqueezy.yaml');
        $container->parameters()->set('env(LEMONSQUEEZY_WEBHOOK_SECRET)', self::WEBHOOK_SECRET);
        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure()->load('App\\', self::EXAMPLE.'/src/');
        // The scratch application test-API (tests/console, "dev"): not in the tests, which must not depend on it
        if (!$test && is_dir(self::REST)) {
            $services->load('App\\API\\', self::REST.'/');
        }
        // The directory is tracked (its YAML files, or its creation): a new file rebuilds the container
        if (!$test && $builder->fileExists(self::REST_CONFIG, '/\.yaml$/') && [] !== glob(self::REST_CONFIG.'/*.yaml')) {
            $container->import(self::REST_CONFIG.'/*.yaml');
        }
        // Rejected webhooks are logged as errors: keep the test output clean
        $services->set('logger', NullLogger::class);
        $services->set(ApiMock::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@FrameworkBundle/Resources/config/routing/webhook.php')->prefix('/webhook');
    }
}
