<?php

namespace Snorky22\ApiForge\Command;

use Snorky22\ApiForge\Dev\Doctor\ApiDiagnosis;
use Snorky22\ApiForge\Dev\Doctor\CheckRenderer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'api-forge:doctor',
    description: 'Runs the generated doctors: configuration, reachability and credentials of each API, and its webhook chain',
    help: <<<'HELP'
        Each API gets a doctor, generated with its services (Generated/<Api>Doctor.php): it checks the
        environment variables of the API, calls its probe (doctor.probe in _api.yaml, a harmless GET),
        diagnoses what fails and compares the response with the descriptor. For APIs with webhooks, the
        webhook chain is checked too, and the webhooks registered at the provider (registration in
        _webhooks.yaml) are checked and pinged.

          <info>php %command.full_name%</info>
          <info>php %command.full_name% lemonsqueezy --ping=https://abcd.ngrok-free.app/webhook/lemonsqueezy</info>
        HELP,
)]
final class DoctorCommand
{
    public function __construct(
        private readonly ApiDiagnosis $diagnosis,
    ) {
    }

    /**
     * @param list<string> $apis
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('API names (default: all the APIs with a generated doctor)')] array $apis = [],
        #[Option('Public URL of the webhook endpoint to ping, e.g. a tunnel (requires one API)')] ?string $ping = null,
        #[Option('Do not ping the webhook URLs registered at the provider')] bool $noPing = false,
    ): int {
        $apis = [] !== $apis ? $apis : $this->diagnosis->apis();
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
            foreach ($this->diagnosis->diagnose($api, $ping, !$noPing) as $section => $checks) {
                $io->text('<comment>'.$section.'</comment>');
                $errors += CheckRenderer::render($io, $checks);
                $io->newLine();
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
