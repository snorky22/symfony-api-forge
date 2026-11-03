<?php

namespace Snorky22\ApiForge\Dev\Webhook;

use Symfony\Component\Filesystem\Filesystem;

/**
 * The webhook deliveries received in development, one JSON file each, the last ones kept per webhook type:
 * what arrived, and what became of it (api-forge:webhook:inbox, api-forge:webhook:replay, the doctor).
 *
 * @phpstan-type Delivery array{
 *     id: string,
 *     received_at: string,
 *     type: string,
 *     event: ?string,
 *     event_id: ?string,
 *     origin: 'provider'|'simulated'|'ping',
 *     status: 'consumed'|'queued'|'rejected'|'failed'|'unknown_type',
 *     http_status: int,
 *     reason: ?string,
 *     headers: array<string, string>,
 *     body: string,
 * }
 */
final class DeliveryInbox
{
    public const CONSUMED = 'consumed';
    public const QUEUED = 'queued';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';
    public const UNKNOWN_TYPE = 'unknown_type';

    private readonly Filesystem $filesystem;

    /**
     * @param string $directory where the deliveries are stored, e.g. var/api-forge/webhooks
     * @param int    $keep      deliveries kept per webhook type
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $keep = 50,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @param Delivery $delivery
     */
    public function save(array $delivery): void
    {
        $this->filesystem->dumpFile($this->file($delivery['type'], $delivery['id']), json_encode($delivery, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        $this->prune($delivery['type']);
    }

    /**
     * @return list<Delivery> most recent first
     */
    public function latest(?string $type = null, int $limit = 20): array
    {
        $files = glob($this->directory.'/'.(null !== $type ? self::safe($type) : '*').'/*.json') ?: [];
        // File names start with the reception time: sorting them sorts the deliveries
        usort($files, static fn (string $a, string $b) => strcmp(basename($b), basename($a)));

        return array_values(array_filter(array_map(self::read(...), \array_slice($files, 0, $limit))));
    }

    /**
     * @return ?Delivery the delivery of this id (or id prefix)
     */
    public function find(string $id): ?array
    {
        $files = glob($this->directory.'/*/'.self::safe($id).'*.json') ?: [];

        return 1 === \count($files) ? self::read($files[0]) : null;
    }

    /**
     * The last delivery of a webhook event, e.g. to record the outcome of its consumption by a worker.
     *
     * @return ?Delivery
     */
    public function findByEventId(string $type, string $eventId): ?array
    {
        foreach ($this->latest($type, $this->keep) as $delivery) {
            if ($delivery['event_id'] === $eventId) {
                return $delivery;
            }
        }

        return null;
    }

    /**
     * @return string a new delivery id: reception time and a random suffix, sortable
     */
    public static function newId(): string
    {
        return (new \DateTimeImmutable())->format('Ymd-His-v').'-'.bin2hex(random_bytes(3));
    }

    private function prune(string $type): void
    {
        $files = glob($this->directory.'/'.self::safe($type).'/*.json') ?: [];
        rsort($files);
        $this->filesystem->remove(\array_slice($files, $this->keep));
    }

    private function file(string $type, string $id): string
    {
        return $this->directory.'/'.self::safe($type).'/'.self::safe($id).'.json';
    }

    /**
     * @return ?Delivery
     */
    private static function read(string $file): ?array
    {
        $content = @file_get_contents($file);
        $delivery = false !== $content ? json_decode($content, true) : null;

        return \is_array($delivery) ? $delivery : null;
    }

    private static function safe(string $name): string
    {
        return preg_replace('/[^\w.-]/', '_', $name);
    }
}
