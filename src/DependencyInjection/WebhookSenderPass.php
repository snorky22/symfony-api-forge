<?php

namespace Snorky22\ApiForge\DependencyInjection;

use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the webhook routing (framework.webhook.routing: parser and secret by type) to the WebhookSender, and
 * the RemoteEvent consumers, by type, to the WebhookDiagnostics.
 */
final class WebhookSenderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(WebhookSender::class)) {
            return;
        }

        $parsers = [];
        if ($container->hasDefinition('webhook.controller')) {
            $routing = $container->getDefinition('webhook.controller')->getArgument(0);
            $parsers = \is_array($routing) ? $routing : [];
        }
        $container->getDefinition(WebhookSender::class)->setArgument('$parsers', $parsers);

        if ($container->hasDefinition(WebhookDiagnostics::class)) {
            $consumers = [];
            foreach ($container->findTaggedServiceIds('remote_event.consumer') as $id => $tags) {
                foreach ($tags as $tag) {
                    if (isset($tag['consumer'])) {
                        $consumers[$tag['consumer']] = new Reference($id);
                    }
                }
            }
            $container->getDefinition(WebhookDiagnostics::class)->setArgument('$consumers', ServiceLocatorTagPass::register($container, $consumers));
        }
    }
}
