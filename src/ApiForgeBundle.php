<?php

namespace Snorky22\ApiForge;

use Snorky22\ApiForge\Command\ApiGenerateCommand;
use Snorky22\ApiForge\Command\ApiImportCommand;
use Snorky22\ApiForge\Command\ApiImportObjectCommand;
use Snorky22\ApiForge\Command\ApiImportWebhookCommand;
use Snorky22\ApiForge\Command\DoctorCommand;
use Snorky22\ApiForge\Command\GuideCommand;
use Snorky22\ApiForge\Command\JsonToPhpCommand;
use Snorky22\ApiForge\Command\ServeCommand;
use Snorky22\ApiForge\Command\WebhookCheckCommand;
use Snorky22\ApiForge\Command\WebhookInboxCommand;
use Snorky22\ApiForge\Command\WebhookReplayCommand;
use Snorky22\ApiForge\Command\WebhookSendCommand;
use Snorky22\ApiForge\Command\YamlToPhpCommand;
use Snorky22\ApiForge\DependencyInjection\WebhookSenderPass;
use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Descriptor\ShapeValidator;
use Snorky22\ApiForge\Dev\Api\Docs\DocsAnnotator;
use Snorky22\ApiForge\Dev\Api\Docs\DocsCapture;
use Snorky22\ApiForge\Dev\Api\Example\ExampleFormat;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;
use Snorky22\ApiForge\Dev\Api\Generator\GenerateRunner;
use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Snorky22\ApiForge\Dev\Api\Import\DescriptorMerger;
use Snorky22\ApiForge\Dev\Api\Import\EndpointImporter;
use Snorky22\ApiForge\Dev\Api\Import\ImportRunner;
use Snorky22\ApiForge\Dev\Api\Import\WebhookImporter;
use Snorky22\ApiForge\Dev\Api\Import\WebhookImportRunner;
use Snorky22\ApiForge\Dev\Api\Import\WebhookMerger;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\Doctor\AbstractApiDoctor;
use Snorky22\ApiForge\Dev\Doctor\ApiDiagnosis;
use Snorky22\ApiForge\Dev\Guide;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;
use Snorky22\ApiForge\Dev\PhpArrayDumper;
use Snorky22\ApiForge\Dev\Webhook\DeliveryInbox;
use Snorky22\ApiForge\Dev\Webhook\DeliveryRecorder;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Snorky22\ApiForge\Dev\YamlToPhpConverter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

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
                ->arrayNode('webhook_inbox')
                    ->info('Records the webhook deliveries received (api-forge:webhook:inbox, api-forge:webhook:replay, the doctor)')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('directory')->info('One per environment by default: the tests post webhooks too')->defaultValue('%kernel.project_dir%/var/api-forge/%kernel.environment%/webhooks')->end()
                        ->integerNode('keep')->info('Deliveries kept per webhook type')->defaultValue(50)->min(1)->end()
                    ->end()
                ->end()
                ->scalarNode('config_dir')
                    ->info('Directory of the generated configuration (HTTP client, webhook routing), relative to the project directory')
                    ->defaultValue('config/packages')
                ->end()
            ->end();
    }

    /**
     * @param array{descriptor_dir: string, namespace: string, source_dir: string, config_dir: string, webhook_inbox: array{enabled: bool, directory: string, keep: int}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        // The generated doctors (Generated/<Api>Doctor.php), run by api-forge:doctor
        $builder->registerForAutoconfiguration(AbstractApiDoctor::class)->addTag('api_forge.doctor');
        // The formats of documentation examples (curl and JSON are read as they are)
        $builder->registerForAutoconfiguration(ExampleFormat::class)->addTag('api_forge.example_format');

        foreach ([
            LenientJsonDecoder::class,
            ArrayShapeGenerator::class,
            PhpArrayDumper::class,
            DocsCapture::class,
            DocsAnnotator::class,
            YamlToPhpConverter::class,
            CurlParser::class,
            EndpointImporter::class,
            ImportRunner::class,
            DescriptorMerger::class,
            WebhookImporter::class,
            WebhookImportRunner::class,
            WebhookMerger::class,
            ShapeValidator::class,
            DescriptorLoader::class,
            JsonToPhpCommand::class,
            YamlToPhpCommand::class,
            ApiImportCommand::class,
            ApiImportObjectCommand::class,
            ApiImportWebhookCommand::class,
            WebhookSendCommand::class,
            WebhookCheckCommand::class,
            DoctorCommand::class,
            ApiDiagnosis::class,
            GuideCommand::class,
        ] as $class) {
            $services->set($class);
        }

        $services->set(PhpArrayExample::class);
        $services->set(ExampleReader::class)->arg('$formats', tagged_iterator('api_forge.example_format'));
        $services->set(DescriptorStore::class)->arg('$directory', $config['descriptor_dir']);
        $services->set(ServiceGenerator::class)->args([$config['namespace'], $config['source_dir'], $config['config_dir']]);
        $services->set(Guide::class)
            ->arg('$projectDir', '%kernel.project_dir%')
            ->arg('$descriptorDir', $config['descriptor_dir']);
        $services->set(GenerateRunner::class)
            ->arg('$projectDir', '%kernel.project_dir%')
            ->arg('$descriptorDir', $config['descriptor_dir']);
        $services->set(ApiGenerateCommand::class);
        $services->set(ServeCommand::class)
            ->args(['%kernel.project_dir%', $config['descriptor_dir'], $config['namespace'], $config['source_dir'], $config['config_dir'], '%kernel.environment%']);
        // The webhook routing is set by WebhookSenderPass
        if ($config['webhook_inbox']['enabled']) {
            $services->set(DeliveryInbox::class)->args([$config['webhook_inbox']['directory'], $config['webhook_inbox']['keep']]);
            $services->set(DeliveryRecorder::class);
            $services->set(WebhookInboxCommand::class);
            $services->set(WebhookReplayCommand::class);
        }
        $services->set(WebhookDiagnostics::class)->arg('$inbox', service(DeliveryInbox::class)->nullOnInvalid());
        $services->set(WebhookSender::class)
            ->arg('$parsers', [])
            ->arg('$sendersLocator', service('messenger.senders_locator')->nullOnInvalid());
    }
}
