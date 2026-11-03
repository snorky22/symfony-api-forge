<?php

namespace Snorky22\ApiForge\Dev\Webhook;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;

/**
 * Records the webhook deliveries received by the application (/webhook/{type}) in the inbox, with their
 * outcome; for events consumed by a Messenger worker, the outcome is recorded when the worker is done.
 */
final class DeliveryRecorder implements EventSubscriberInterface
{
    private const SKIPPED_HEADERS = ['cookie', 'authorization', 'php-auth-user', 'php-auth-pw'];

    public function __construct(
        private readonly DeliveryInbox $inbox,
        private readonly WebhookSender $sender,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [KernelEvents::RESPONSE => ['onResponse', -1024]];
        if (class_exists(WorkerMessageHandledEvent::class)) {
            $events[WorkerMessageHandledEvent::class] = 'onWorkerHandled';
            $events[WorkerMessageFailedEvent::class] = 'onWorkerFailed';
        }

        return $events;
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ('_webhook_controller' !== $request->attributes->get('_route')) {
            return;
        }

        try {
            $type = (string) ($request->attributes->get('_route_params')['type'] ?? '');
            $status = $event->getResponse()->getStatusCode();
            $described = $this->sender->describe($type, $request);
            $headers = [];
            foreach ($request->headers->all() as $name => $values) {
                if (!\in_array($name, self::SKIPPED_HEADERS, true)) {
                    $headers[$name] = implode(', ', $values);
                }
            }

            $this->inbox->save([
                'id' => DeliveryInbox::newId(),
                'received_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'type' => $type,
                'event' => $described['name'] ?? null,
                'event_id' => $described['id'] ?? null,
                'origin' => match ($request->headers->get(WebhookSender::ORIGIN_HEADER)) {
                    'ping' => 'ping',
                    'simulated' => 'simulated',
                    default => 'provider',
                },
                'status' => match (true) {
                    404 === $status => DeliveryInbox::UNKNOWN_TYPE,
                    $status >= 500 => DeliveryInbox::FAILED,
                    202 === $status && [] !== $this->sender->asyncTransports($type) => DeliveryInbox::QUEUED,
                    $status >= 200 && $status < 300 => DeliveryInbox::CONSUMED,
                    default => DeliveryInbox::REJECTED,
                },
                'http_status' => $status,
                'reason' => $status >= 200 && $status < 300 ? null : self::reason((string) $event->getResponse()->getContent()),
                'headers' => $headers,
                'body' => $request->getContent(),
            ]);
        } catch (\Throwable $e) {
            // The inbox must never break the webhook endpoint
            $this->logger->warning('The webhook delivery could not be recorded in the inbox: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function onWorkerHandled(WorkerMessageHandledEvent $event): void
    {
        $this->update($event->getEnvelope()->getMessage(), DeliveryInbox::CONSUMED, null);
    }

    public function onWorkerFailed(WorkerMessageFailedEvent $event): void
    {
        $this->update($event->getEnvelope()->getMessage(), DeliveryInbox::FAILED, $event->getThrowable()->getMessage().($event->willRetry() ? ' (will be retried)' : ''));
    }

    private function update(object $message, string $status, ?string $reason): void
    {
        if (!$message instanceof ConsumeRemoteEventMessage) {
            return;
        }
        try {
            $delivery = $this->inbox->findByEventId($message->getType(), $message->getEvent()->getId());
            if (null !== $delivery) {
                $this->inbox->save(['status' => $status, 'reason' => null !== $reason ? mb_strimwidth($reason, 0, 300, '…') : null] + $delivery);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('The webhook delivery could not be updated in the inbox: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * The reason of a rejection or failure: the text of the response, or the title of an error page.
     */
    private static function reason(string $content): string
    {
        if (preg_match('#<title>(.*?)</title>#si', $content, $match)) {
            // Symfony's error page: "Invalid signature. (406 Not Acceptable)", the status is recorded apart
            $content = preg_replace('/\s*\(\d{3} [^)]*\)$/', '', html_entity_decode(trim($match[1])));
        }

        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($content))), 0, 300, '…') ?: 'no message';
    }
}
