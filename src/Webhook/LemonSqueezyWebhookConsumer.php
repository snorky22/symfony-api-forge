<?php

namespace App\Webhook;

use App\Api\LemonSqueezy\Generated\Webhook\AbstractLemonSqueezyWebhookConsumer;
use Psr\Log\LoggerInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Handles the Lemon Squeezy webhooks received on /webhook/lemonsqueezy.
 *
 * Override the on<Event>() methods of the events to handle; the others are ignored.
 */
#[AsRemoteEventConsumer('lemonsqueezy')]
final class LemonSqueezyWebhookConsumer extends AbstractLemonSqueezyWebhookConsumer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function onOrderCreated(array $payload, RemoteEvent $event): void
    {
        $this->logger->info('Lemon Squeezy order #{number} created for {email}.', [
            'number' => $payload['data']['attributes']['order_number'],
            'email' => $payload['data']['attributes']['user_email'],
        ]);
    }

    protected function onUnknownEvent(RemoteEvent $event): void
    {
        $this->logger->warning('Unhandled Lemon Squeezy webhook event "{name}".', ['name' => $event->getName()]);
    }
}
