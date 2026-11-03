<?php

namespace Snorky22\ApiForge\Test;

use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * For functional tests (WebTestCase): posts webhook deliveries signed like the API, with the secret of the
 * webhook routing of the test environment.
 */
trait WebhookTestTrait
{
    /**
     * @param string|array<mixed> $payload JSON, or an array encoded to JSON
     */
    protected static function sendWebhook(KernelBrowser $client, string $type, string|array $payload, ?string $eventName = null): Response
    {
        $body = \is_array($payload) ? json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : $payload;
        /** @var WebhookSender $sender */
        $sender = $client->getContainer()->get(WebhookSender::class);

        $client->request('POST', $sender->url($type), server: WebhookSender::serverParameters($sender->headers($type, $body, $eventName)), content: $body);

        return $client->getResponse();
    }
}
