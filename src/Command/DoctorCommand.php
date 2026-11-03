<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Doctor\AbstractApiDoctor;
use Snorky22\ApiForge\Dev\Doctor\Check;
use Snorky22\ApiForge\Dev\Doctor\CheckRenderer;
use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'api-forge:doctor',
    description: 'Runs the generated doctors: configuration, reachability and credentials of each API, and its webhook chain',
    help: <<<'HELP'
        Each API gets a doctor, generated with its services (Generated/<Api>Doctor.php): it checks the
        environment variables of the API, then calls its probe (doctor.probe in _api.yaml, a harmless GET)
        and diagnoses what fails. For APIs with webhooks, the webhook chain is checked too.

          <info>php %command.full_name%</info>
          <info>php %command.full_name% lemonsqueezy --ping=https://abcd.ngrok-free.app/webhook/lemonsqueezy</info>
        HELP,
)]
final class DoctorCommand
{
    /**
     * @param ServiceLocator<AbstractApiDoctor> $doctors
     */
    public function __construct(
        #[AutowireLocator('api_forge.doctor', defaultIndexMethod: 'api')] private readonly ServiceLocator $doctors,
        private readonly WebhookDiagnostics $webhooks,
        private readonly WebhookSender $sender,
    ) {
    }

    /**
     * @param list<string> $apis
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API names (default: all the APIs with a generated doctor)')] array $apis = [],
        #[Option('Public URL to ping, e.g. a tunnel (requires one API)')] ?string $ping = null,
    ): int {
        $known = array_keys($this->doctors->getProvidedServices());
        $apis = [] !== $apis ? $apis : $known;
        if ([] === $apis) {
            $io->warning('No generated doctor: run api-forge:generate.');

            return Command::FAILURE;
        }
        if (null !== $ping && 1 !== \count($apis)) {
            $io->error('--ping requires exactly one API.');

            return Command::FAILURE;
        }

        $errors = 0;
        foreach ($apis as $api) {
            $io->section($api);
            if (!\in_array($api, $known, true)) {
                $errors += CheckRenderer::render($io, [Check::error(\sprintf('No doctor for "%s"; known APIs: %s.', $api, implode(', ', $known) ?: 'none'))]);
                continue;
            }

            $io->text('<comment>API</comment>');
            try {
                $checks = $this->doctors->get($api)->diagnose();
            } catch (\Throwable $e) {
                // e.g. an environment variable without value nor default
                $checks = [Check::error('The doctor cannot be built: '.$e->getMessage())];
            }
            $errors += CheckRenderer::render($io, $checks);

            if (\in_array($api, $this->sender->types(), true)) {
                $io->newLine();
                $io->text('<comment>Webhooks</comment>');
                $checks = $this->webhooks->check($api);
                if (null !== $ping) {
                    $checks[] = $this->webhooks->ping($api, $ping);
                }
                $errors += CheckRenderer::render($io, $checks);
            }
        }

        if ($errors > 0) {
            $io->error(\sprintf('%d problem(s) found.', $errors));

            return Command::FAILURE;
        }
        $io->success('All the APIs are reachable and configured.');

        return Command::SUCCESS;
    }
}
