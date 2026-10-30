<?php

namespace App\Api\Core\Webhook;

use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Base class of the generated webhook consumers: calls the handler method of each event.
 */
abstract class AbstractWebhookConsumer implements ConsumerInterface
{
    /** Event name => handler method, called with the payload and the event. */
    protected const HANDLERS = [];

    public function consume(RemoteEvent $event): void
    {
        $method = static::HANDLERS[$event->getName()] ?? null;
        if (null === $method) {
            $this->onUnknownEvent($event);

            return;
        }

        $this->{$method}($event->getPayload(), $event);
    }

    /**
     * Called for the events without handler method, e.g. new events of the API. Ignores them by default.
     */
    protected function onUnknownEvent(RemoteEvent $event): void
    {
    }
}
