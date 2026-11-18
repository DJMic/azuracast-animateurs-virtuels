<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Sync;

use App\Entity\Station;
use App\Sync\Task\AbstractTask;
use DateTimeImmutable;
use Plugin\AzuracastAnimateursVirtuels\Service\ChronicleGenerator;
use Plugin\AzuracastAnimateursVirtuels\Service\ChronicleSettings;
use Plugin\AzuracastAnimateursVirtuels\Service\Database;
use Plugin\AzuracastAnimateursVirtuels\Service\Generator;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Launcher;
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
        private readonly ChronicleGenerator $chronicles,
        private readonly Launcher $launcher,
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
        $settings = $this->store->read($station);
        if (!is_dir($this->jobs->root() . '/' . $stationId) && $settings['chronicles'] === []) {
            return; // the plugin was never used on this station
        }

        // Chronicles due now: queued and started in the background.
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        foreach ($settings['chronicles'] as $chronicle) {
            $slot = null;
            $this->jobs->updateState($stationId, function (array $state) use ($chronicle, $now, &$slot): array {
                $done = $state['chronicles_done'][$chronicle['id']] ?? [];
                $slot = ChronicleSettings::dueSlot($chronicle, $now, $done);
                if ($slot !== null) {
                    $done[] = $slot;
                    $state['chronicles_done'][$chronicle['id']] = array_slice($done, -50);
                }

                return $state;
            });
            if ($slot !== null) {
                $job = $this->chronicles->queue($station, $chronicle, 'schedule', $slot);
                $this->launcher->start($stationId, (string)$job['id'], 0);
            }
        }

        // Interventions and chronicles not generated yet (immediate start failed or still waiting).
        foreach ($this->jobs->pending($stationId) as $job) {
            if (($job['kind'] ?? '') === 'chronicle') {
                $created = strtotime((string)$job['created_at']) ?: 0;
                if (time() - $created >= 45) { // leaves time for the background start
                    $this->chronicles->run($station, (string)$job['id']);
                }
                continue;
            }
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

        $this->jobs->updateState($stationId, function (array $state) use ($stationId, $settings): array {
            if (time() - (int)($state['cleanup_at'] ?? 0) >= 3600) {
                $this->jobs->cleanup($stationId, (int)$settings['retention_days']);
                $state['cleanup_at'] = time();
            }

            return $state;
        });
    }
}
