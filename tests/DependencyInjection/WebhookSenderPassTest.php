<?php

namespace Snorky22\ApiForge\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\DependencyInjection\WebhookSenderPass;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class WebhookSenderPassTest extends TestCase
{
    public function testSeveralConsumersOfOneType(): void
    {
        $container = new ContainerBuilder();
        $container->register(WebhookSender::class, WebhookSender::class);
        $container->register(WebhookDiagnostics::class, WebhookDiagnostics::class);
        $container->register('generated.consumer')->addTag('remote_event.consumer', ['consumer' => 'paddle']);
        $container->register('hand.consumer')->addTag('remote_event.consumer', ['consumer' => 'paddle']);
        $container->register('other.consumer')->addTag('remote_event.consumer', ['consumer' => 'stripe']);

        (new WebhookSenderPass())->process($container);

        self::assertSame(['paddle' => ['generated.consumer', 'hand.consumer']], $container->getDefinition(WebhookDiagnostics::class)->getArgument('$duplicateConsumers'));
    }
}
