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

        self::assertStringStartsWith('Import a first documented request: api-forge:import shop tests/Fixtures/shop/get-<resource>.curl', $status['next']);
        self::assertSame('none in tests/Fixtures/shop', array_column($status['state'], 1, 0)['Examples']);
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
