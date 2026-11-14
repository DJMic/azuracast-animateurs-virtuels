<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Sync;

use App\Entity\Station;
use App\Sync\Task\AbstractTask;
use Plugin\AzuracastAnimateursVirtuels\Service\Database;
use Plugin\AzuracastAnimateursVirtuels\Service\Generator;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Store;
use Throwable;

/**
 * Every minute (AzuraCast synchronization):
 *  - generates the queued interventions that were not generated right away;
 *  - once an hour, deletes clips and history older than the retention period.
 */
final class GenerateTask extends AbstractTask
{
    public function __construct(
        private readonly Generator $generator,
        private readonly Jobs $jobs,
        private readonly Store $store,
        private readonly Database $database
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public static function isLongTask(): bool
    {
        return true;
    }

    public function run(bool $force = false): void
    {
        if (!$this->jobs->isWritable()) {
            return;
        }
        $this->database->recover();
        $ids = $this->em->createQuery(
            <<<'DQL'
                SELECT s.id FROM App\Entity\Station s WHERE s.is_enabled = 1
            DQL
        )->getSingleColumnResult();

        foreach ($ids as $id) {
            try {
                $this->database->recover();
                $station = $this->em->find(Station::class, (int)$id);
                if ($station instanceof Station) {
                    $this->processStation($station);
                }
            } catch (Throwable $e) {
                $this->logger->error('Animateurs virtuels : ' . Database::describe($e), ['station' => $id, 'exception' => $e]);
            }
        }
        $this->database->recover();
    }

    private function processStation(Station $station): void
    {
        $stationId = (int)$station->id;
        if (!is_dir($this->jobs->root() . '/' . $stationId)) {
            return; // the plugin was never used on this station
        }

        foreach ($this->jobs->pending($stationId) as $job) {
            $this->generator->generate($station, (string)$job['id']);
        }

        // Interventions interrupted while running (container restart…) for more than 10 minutes.
        foreach ($this->jobs->list($stationId, 100) as $job) {
            $started = strtotime((string)($job['started_at'] ?? ''));
            if (($job['status'] ?? '') === 'running' && $started !== false && time() - $started > 600) {
                $this->jobs->withLock($stationId, (string)$job['id'], function () use ($stationId, $job): void {
                    $job['status'] = 'failed';
                    $job['error'] = 'Génération interrompue.';
                    $this->jobs->save($stationId, $job);
                });
            }
        }

        $settings = $this->store->read($station);
        $this->jobs->updateState($stationId, function (array $state) use ($stationId, $settings): array {
            if (time() - (int)($state['cleanup_at'] ?? 0) >= 3600) {
                $this->jobs->cleanup($stationId, (int)$settings['retention_days']);
                $state['cleanup_at'] = time();
            }

            return $state;
        });
    }
}
