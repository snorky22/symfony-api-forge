<?php

namespace Snorky22\ApiForge\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class MakeWebhookCommandTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/api-forge-make-webhook-'.bin2hex(random_bytes(4));
        // Warnings raised in the Console component are not reported by PHPUnit (restrictWarnings): make them fail here
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }, \E_WARNING | \E_NOTICE | \E_USER_WARNING);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        (new Filesystem())->remove($this->dir);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('api-forge:make:webhook'));
    }

    private function file(string $path): string
    {
        return (string) file_get_contents($this->dir.'/test-API/'.$path);
    }

    public function testPaddleWithoutInteraction(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'Paddle', '--scheme' => 'paddle', '--events' => 'transaction.completed', '--output' => $this->dir], ['interactive' => false]));
        $parser = $this->file('src/API/Paddle/Webhook/PaddleRequestParser.php');
        self::assertStringContainsString("namespace App\\API\\Paddle\\Webhook;\n", $parser);
        self::assertStringContainsString("    protected const SIGNATURE_HEADER = 'Paddle-Signature';\n", $parser);
        self::assertStringContainsString("    protected const SUCCESS_STATUS = 200;\n", $parser);
        $consumer = $this->file('src/API/Paddle/Webhook/PaddleWebhookConsumer.php');
        self::assertStringContainsString("#[AsRemoteEventConsumer('paddle')]", $consumer);
        self::assertStringContainsString("'transaction.completed' => 'onTransactionCompleted',", $consumer);
        self::assertSame('App\API\Paddle\Webhook\PaddleRequestParser', Yaml::parse($this->file('config/packages/paddle_webhook.yaml'))['framework']['webhook']['routing']['paddle']['service']);
        $display = $tester->getDisplay();
        self::assertStringContainsString('PADDLE_WEBHOOK_SECRET=...', $display);
        self::assertStringContainsString('api-forge:webhook:send paddle var/paddle-event.json', $display);
        // The test application has the route /webhook/{type}: no route file
        self::assertFileDoesNotExist($this->dir.'/test-API/config/routes/webhook.yaml');
    }

    public function testAsksWhatIsMissing(): void
    {
        $tester = $this->tester();
        // API, scheme, events
        $tester->setInputs(['Shops\LemonSqueezy', 'lemonsqueezy', 'order_created, order_refunded']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => $this->dir]));
        self::assertStringContainsString('Known events: order_created, order_refunded', $tester->getDisplay());
        self::assertStringContainsString("'order_refunded' => 'onOrderRefunded',", $this->file('src/API/Shops/LemonSqueezy/Webhook/LemonSqueezyWebhookConsumer.php'));
        self::assertStringContainsString("    protected const EVENT_NAME_PATH = 'meta.event_name';\n", $this->file('src/API/Shops/LemonSqueezy/Webhook/LemonSqueezyRequestParser.php'));
        self::assertFileExists($this->dir.'/test-API/config/packages/lemon_squeezy_webhook.yaml');
    }

    public function testRunAgainToAddEvents(): void
    {
        $this->tester()->execute(['api' => 'Paddle', '--scheme' => 'paddle', '--events' => 'transaction.completed', '--output' => $this->dir], ['interactive' => false]);
        $parser = $this->file('src/API/Paddle/Webhook/PaddleRequestParser.php');
        $tester = $this->tester();
        $tester->setInputs(['transaction.paid, transaction.completed']);

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'Paddle', '--output' => $this->dir]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('PaddleWebhookConsumer.php exists, with: transaction.completed.', $display);
        self::assertStringContainsString('transaction.paid', $display);
        self::assertStringContainsString('Already there, left as they are: transaction.completed.', $display);
        self::assertStringContainsString('The webhook type "paddle" is routed already (', $display);
        self::assertStringContainsString("'transaction.paid' => 'onTransactionPaid',", $this->file('src/API/Paddle/Webhook/PaddleWebhookConsumer.php'));
        self::assertSame($parser, $this->file('src/API/Paddle/Webhook/PaddleRequestParser.php'));
    }

    public function testRunAgainKeepsTheTypeOfTheConsumer(): void
    {
        $this->tester()->execute(['api' => 'Paddle', '--scheme' => 'paddle', '--type' => 'billing', '--events' => 'transaction.completed', '--output' => $this->dir], ['interactive' => false]);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'Paddle', '--events' => 'transaction.paid', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('The webhook type "billing" is routed already (', $tester->getDisplay());
        self::assertFileExists($this->dir.'/test-API/config/packages/billing_webhook.yaml');
        self::assertFileDoesNotExist($this->dir.'/test-API/config/packages/paddle_webhook.yaml');
    }

    public function testAConsumerWithoutParserGetsNoRouting(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/test-API/src/API/Paddle/Webhook/PaddleWebhookConsumer.php', (new \Snorky22\ApiForge\Dev\Webhook\WebhookMaker())->consumer('App\API\Paddle\Webhook\PaddleWebhookConsumer', 'paddle', ['transaction.completed']));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'Paddle', '--events' => 'transaction.paid', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('is missing: the routing of the webhook type', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/test-API/config/packages/paddle_webhook.yaml');
    }

    public function testHasPriorityOverAGeneratedRouting(): void
    {
        (new Filesystem())->dumpFile($this->dir.'/test-API/config/packages/api_lemonsqueezy.yaml', "# Generated by api-forge:generate from config/api/lemonsqueezy.\nframework:\n    webhook:\n        routing:\n            lemonsqueezy: { service: App\\Api\\LemonSqueezy\\Generated\\Webhook\\LemonSqueezyRequestParser, secret: s }\n");
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'LemonSqueezy', '--scheme' => 'lemonsqueezy', '--events' => 'order_created', '--output' => $this->dir], ['interactive' => false]));
        $display = $tester->getDisplay();
        // The type of the generated routing is reused, and the routing written: the parser of make:webhook wins
        self::assertStringContainsString('The webhook type "lemonsqueezy" is routed already for this API', $display);
        self::assertStringContainsString("#[AsRemoteEventConsumer('lemonsqueezy')]", $this->file('src/API/LemonSqueezy/Webhook/LemonSqueezyWebhookConsumer.php'));
        self::assertSame('App\API\LemonSqueezy\Webhook\LemonSqueezyRequestParser', Yaml::parse($this->file('config/packages/lemonsqueezy_webhook.yaml'))['framework']['webhook']['routing']['lemonsqueezy']['service']);
        self::assertStringContainsString('generate it again', $display);
    }

    public function testTheRoutingIsReadFromTheTargetProject(): void
    {
        // The test application routes "lemonsqueezy" (the example): an empty target project doesn't
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'Shop', '--scheme' => 'lemonsqueezy', '--type' => 'lemonsqueezy', '--output' => $this->dir], ['interactive' => false]));
        self::assertFileExists($this->dir.'/test-API/config/packages/lemonsqueezy_webhook.yaml');
    }

    public function testCustomScheme(): void
    {
        $tester = $this->tester();
        // header, timestamp in the header?, prefix, algorithm, encoding, event name, event id, status, events
        $tester->setInputs(['X-Hub-Signature-256', 'no', 'sha256=', '', 'hex', 'header:X-Event', 'id', '', 'push']);

        self::assertSame(Command::SUCCESS, $tester->execute(['api' => 'Forge', '--scheme' => 'custom', '--output' => $this->dir]));
        $parser = $this->file('src/API/Forge/Webhook/ForgeRequestParser.php');
        self::assertStringContainsString("    protected const SIGNATURE_PREFIX = 'sha256=';\n", $parser);
        self::assertStringContainsString("    protected const EVENT_NAME_HEADER = 'X-Event';\n", $parser);
        self::assertStringContainsString("    protected const EVENT_ID_PATH = 'id';\n", $parser);
        self::assertStringNotContainsString('SUCCESS_STATUS', $parser);
        // The event name is read from a header: the test delivery gives it
        self::assertStringContainsString('api-forge:webhook:send forge var/forge-event.json --event=push', $tester->getDisplay());
    }

    public function testErrors(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['api' => 'paddle', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('is not a namespace', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['api' => 'Paddle', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('Give the signature scheme with --scheme', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['api' => 'Paddle', '--scheme' => 'custom', '--output' => $this->dir], ['interactive' => false]));
        self::assertStringContainsString('run the command interactively', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->dir.'/test-API/src');
    }
}
