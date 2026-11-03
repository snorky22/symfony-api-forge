<?php

namespace Snorky22\ApiForge;

use Snorky22\ApiForge\Command\ApiGenerateCommand;
use Snorky22\ApiForge\Command\ApiImportCommand;
use Snorky22\ApiForge\Command\ApiImportObjectCommand;
use Snorky22\ApiForge\Command\ApiImportWebhookCommand;
use Snorky22\ApiForge\Command\JsonToPhpCommand;
use Snorky22\ApiForge\Command\WebhookSendCommand;
use Snorky22\ApiForge\DependencyInjection\WebhookSenderPass;
use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Descriptor\ShapeValidator;
use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Snorky22\ApiForge\Dev\Api\Import\DescriptorMerger;
use Snorky22\ApiForge\Dev\Api\Import\EndpointImporter;
use Snorky22\ApiForge\Dev\Api\Import\WebhookImporter;
use Snorky22\ApiForge\Dev\Api\Import\WebhookMerger;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Provides the api-forge:* commands, which import API documentation into descriptors, generate services,
 * and simulate webhook deliveries.
 *
 * The generated code only depends on the classes of the Core namespace, not on this bundle: the bundle is
 * a development tool, and can be enabled in the dev environment only.
 */
final class ApiForgeBundle extends AbstractBundle
{
    protected string $extensionAlias = 'api_forge';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new WebhookSenderPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('descriptor_dir')
                    ->info('Directory of the API descriptors')
                    ->defaultValue('%kernel.project_dir%/config/api')
                ->end()
                ->scalarNode('namespace')
                    ->info('PHP namespace of the generated code; the namespace of each API is appended to it')
                    ->defaultValue('App\\Api')
                ->end()
                ->scalarNode('source_dir')
                    ->info('Directory of this namespace, relative to the project directory')
                    ->defaultValue('src/Api')
                ->end()
                ->scalarNode('config_dir')
                    ->info('Directory of the generated configuration (HTTP client, webhook routing), relative to the project directory')
                    ->defaultValue('config/packages')
                ->end()
            ->end();
    }

    /**
     * @param array{descriptor_dir: string, namespace: string, source_dir: string, config_dir: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();

        foreach ([
            LenientJsonDecoder::class,
            ArrayShapeGenerator::class,
            CurlParser::class,
            EndpointImporter::class,
            DescriptorMerger::class,
            WebhookImporter::class,
            WebhookMerger::class,
            ShapeValidator::class,
            DescriptorLoader::class,
            JsonToPhpCommand::class,
            ApiImportCommand::class,
            ApiImportObjectCommand::class,
            ApiImportWebhookCommand::class,
            WebhookSendCommand::class,
        ] as $class) {
            $services->set($class);
        }

        $services->set(DescriptorStore::class)->arg('$directory', $config['descriptor_dir']);
        $services->set(ServiceGenerator::class)->args([$config['namespace'], $config['source_dir'], $config['config_dir']]);
        $services->set(ApiGenerateCommand::class)
            ->arg('$projectDir', '%kernel.project_dir%')
            ->arg('$descriptorDir', $config['descriptor_dir']);
        // The webhook routing is set by WebhookSenderPass
        $services->set(WebhookSender::class)
            ->arg('$parsers', [])
            ->arg('$sendersLocator', service('messenger.senders_locator')->nullOnInvalid());
    }
}
