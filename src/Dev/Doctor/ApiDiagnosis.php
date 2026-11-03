<?php

namespace Snorky22\ApiForge\Dev\Doctor;

use Snorky22\ApiForge\Dev\Webhook\WebhookDiagnostics;
use Snorky22\ApiForge\Dev\Webhook\WebhookSender;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The whole diagnosis of an API: its generated doctor, its webhook chain, and the webhooks registered at the
 * provider (when the descriptors say how to read them), pinged.
 */
final class ApiDiagnosis
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
     * @return list<string> the APIs with a generated doctor
     */
    public function apis(): array
    {
        return array_keys($this->doctors->getProvidedServices());
    }

    /**
     * @param ?string      $ping           a public URL of the endpoint to ping, besides the registered ones
     * @param list<string> $requiredEvents events the registered webhooks must send (errors, not warnings)
     *
     * @return array<string, list<Check>> the checks by section: "API", "Webhooks", "Registered webhooks"
     */
    public function diagnose(string $api, ?string $ping = null, bool $pingRegistered = true, array $requiredEvents = []): array
    {
        if (!$this->doctors->has($api)) {
            return ['API' => [Check::error(\sprintf('No doctor for "%s"; known APIs: %s.', $api, implode(', ', $this->apis()) ?: 'none'))]];
        }

        $doctor = null;
        try {
            $doctor = $this->doctors->get($api);
            $sections = ['API' => $doctor->diagnose()];
        } catch (\Throwable $e) {
            // e.g. an environment variable without value nor default
            $sections = ['API' => [Check::error('The doctor cannot be built: '.$e->getMessage())]];
        }

        if (!\in_array($api, $this->sender->types(), true)) {
            return $sections;
        }

        $checks = $this->webhooks->check($api);
        if (null !== $ping) {
            $checks[] = $this->webhooks->ping($api, $ping);
        }
        $sections['Webhooks'] = $checks;

        try {
            $registrations = $doctor?->registeredWebhooks();
        } catch (\Throwable $e) {
            $sections['Registered webhooks'] = [Check::error('The webhooks registered at the provider cannot be read (see the API checks): '.$e->getMessage())];

            return $sections;
        }
        if (null !== $registrations) {
            [$checks, $urls] = $this->webhooks->registrations($api, $registrations, $requiredEvents);
            if ($pingRegistered) {
                foreach (array_diff($urls, [$ping]) as $url) {
                    $checks[] = $this->webhooks->ping($api, $url);
                }
            }
            $sections['Registered webhooks'] = $checks;
        }

        return $sections;
    }
}
