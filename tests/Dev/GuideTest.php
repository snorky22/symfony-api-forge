<?php

namespace Snorky22\ApiForge\Tests\Dev;

use Snorky22\ApiForge\Dev\Guide;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GuideTest extends KernelTestCase
{
    private const EXAMPLE = __DIR__.'/../../examples/lemonsqueezy';

    /**
     * Guide is the single source of the conventions: the documentation must say the same.
     */
    public function testDocumentationMatchesTheConventions(): void
    {
        $workflow = file_get_contents(__DIR__.'/../../docs/workflow.md');

        self::assertStringContainsString('`'.Guide::FIXTURES_DIR.'/`', $workflow);
        foreach (Guide::FIXTURES as [$file, $content, $examples]) {
            self::assertStringContainsString(\sprintf('| `%s` | %s | %s |', $file, $content, $examples), $workflow, 'docs/workflow.md and Guide::FIXTURES differ: update the documentation.');
        }
    }

    public function testStateAndNextStepOfAnApi(): void
    {
        self::bootKernel();
        $guide = self::getContainer()->get(Guide::class);

        $status = $guide->status('lemonsqueezy', self::EXAMPLE.'/config/api', self::EXAMPLE, __DIR__.'/../Fixtures');
        $state = array_column($status['state'], 1, 0);

        self::assertSame('customers.list', $state['Doctor probe']);
        self::assertSame('up to date', $state['Generated code']);
        self::assertStringStartsWith('6 resource(s), 7 operation(s): checkouts, customers', $state['Resources']);
        self::assertStringStartsWith('17 event(s), secret in LEMONSQUEEZY_WEBHOOK_SECRET', $state['Webhooks']);
        self::assertStringStartsWith('Review the 22 note(s) left in the descriptors', $status['next']);
    }

    public function testNewApi(): void
    {
        self::bootKernel();

        $status = self::getContainer()->get(Guide::class)->status('shop', self::EXAMPLE.'/config/api');

        self::assertStringStartsWith('Import a first documented request: php bin/console api-forge:import shop tests/Fixtures/shop/get-<resource>.curl', $status['next']);
        self::assertSame('none in tests/Fixtures/shop', array_column($status['state'], 1, 0)['Examples']);
    }

    public function testExamplesNotImportedYet(): void
    {
        self::bootKernel();
        $fixtures = sys_get_temp_dir().'/guide-test-'.bin2hex(random_bytes(4));
        mkdir($fixtures);
        file_put_contents($fixtures.'/list-customers.curl', 'curl "https://api.lemonsqueezy.com/v1/customers" -H "Accept: application/vnd.api+json"');
        file_put_contents($fixtures.'/list-stores.curl', 'curl "https://api.lemonsqueezy.com/v1/stores" -H "Accept: application/vnd.api+json"');
        file_put_contents($fixtures.'/list-stores.json', '{"data": []}');

        $status = self::getContainer()->get(Guide::class)->status('lemonsqueezy', self::EXAMPLE.'/config/api', self::EXAMPLE, $fixtures, 'php tests/console');

        // customers.list is an operation already (same method and path): only the stores are pending
        self::assertSame('list-stores.curl', array_column($status['state'], 1, 0)['Not imported']);
        self::assertStringStartsWith("Import the examples not imported yet:\nphp tests/console api-forge:import lemonsqueezy ", $status['next']);
        self::assertStringEndsWith('/list-stores.curl --response='.substr($fixtures, 1).'/list-stores.json', str_replace('../', '', $status['next']));
        array_map('unlink', glob($fixtures.'/*'));
        rmdir($fixtures);
    }

    public function testArrayExamplesAreListedToo(): void
    {
        self::bootKernel();
        $fixtures = sys_get_temp_dir().'/guide-test-'.bin2hex(random_bytes(4));
        mkdir($fixtures);
        file_put_contents($fixtures.'/list-customers.php', "<?php return ['request' => 'GET /customers'];");
        file_put_contents($fixtures.'/list-stores.php', "<?php return ['request' => 'GET /stores', 'response' => ['data' => []]];");
        file_put_contents($fixtures.'/webhook-order.php', '<?php return [];');
        file_put_contents($fixtures.'/order-object.php', '<?php return [];');
        file_put_contents($fixtures.'/broken.php', '<?php return [phpinfo()];');

        $status = self::getContainer()->get(Guide::class)->status('lemonsqueezy', self::EXAMPLE.'/config/api', self::EXAMPLE, $fixtures, 'php tests/console');

        self::assertSame('list-stores.php', array_column($status['state'], 1, 0)['Not imported']);
        self::assertStringEndsWith('/list-stores.php', str_replace('../', '', $status['next']));
        array_map('unlink', glob($fixtures.'/*'));
        rmdir($fixtures);
    }

    public function testProgressAsData(): void
    {
        self::bootKernel();
        $guide = self::getContainer()->get(Guide::class);

        $progress = $guide->progress('lemonsqueezy', self::EXAMPLE.'/config/api', self::EXAMPLE, self::EXAMPLE.'/none');
        self::assertTrue($progress['described']);
        self::assertSame([], $progress['errors']);
        self::assertContains('customers', $progress['resources']);
        self::assertGreaterThan(0, $progress['operations']);
        self::assertSame([], $progress['pending']);
        self::assertSame([], $progress['stale']);
        self::assertTrue($progress['webhooks']);

        $none = $guide->progress('shop', self::EXAMPLE.'/config/api', self::EXAMPLE, self::EXAMPLE.'/none');
        self::assertFalse($none['described']);
        self::assertSame([], $none['resources']);
    }

    public function testCommand(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('api-forge:guide'));

        $tester->execute([]);
        self::assertStringContainsString('<operation>-<resource>.curl', $tester->getDisplay());
        self::assertStringContainsString('api-forge:doctor <api>', $tester->getDisplay());

        $tester->execute(['api' => 'lemonsqueezy', '--dir' => self::EXAMPLE.'/config/api', '--output' => self::EXAMPLE]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Next step', $tester->getDisplay());
    }
}
