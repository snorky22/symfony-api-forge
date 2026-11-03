<?php

namespace Snorky22\ApiForge\DependencyInjection;

use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Gives the webhook routing (framework.webhook.routing: parser and secret by type) to the WebhookSender.
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
    }
}
