<?php

namespace Snorky22\ApiForge\Tests\Dev\Webhook;

use Snorky22\ApiForge\Dev\Webhook\DeliveryInbox;
use Snorky22\ApiForge\Dev\Webhook\DeliveryRecorder;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Snorky22\ApiForge\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The status a delivery gets in the inbox, from the response of the endpoint ("lemonsqueezy" is queued on "async").
 */
final class DeliveryRecorderTest extends KernelTestCase
{
    /**
     * @return array{status: string}
     */
    private static function record(int $status): array
    {
        (new Filesystem())->remove(TestKernel::INBOX);
        self::bootKernel();
        $inbox = self::getContainer()->get(DeliveryInbox::class);
        $recorder = new DeliveryRecorder($inbox, self::getContainer()->get(WebhookSender::class));
        $request = Request::create('/webhook/lemonsqueezy', 'POST', content: '{"meta":{"event_name":"order_created"}}');
        $request->attributes->set('_route', '_webhook_controller');
        $request->attributes->set('_route_params', ['type' => 'lemonsqueezy']);

        $recorder->onResponse(new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', $status)));

        return $inbox->latest('lemonsqueezy')[0];
    }

    public function testAcceptedWith202OrAnother2xxIsQueued(): void
    {
        self::assertSame(DeliveryInbox::QUEUED, self::record(202)['status']);
        // The status an API expects (SUCCESS_STATUS, e.g. 200 for Paddle) doesn't change what happens to the event
        self::assertSame(DeliveryInbox::QUEUED, self::record(200)['status']);
    }

    public function testRejected(): void
    {
        self::assertSame(DeliveryInbox::REJECTED, self::record(406)['status']);
    }
}
