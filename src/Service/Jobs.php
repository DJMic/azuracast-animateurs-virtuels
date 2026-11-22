<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * Interventions ("jobs") and generated clips, in the plugin's data folder:
 *
 *   <data>/<station id>/jobs/<job id>.json   one file per intervention (also the history)
 *   <data>/<station id>/clips/<job id>.mp3   the clip aired by Liquidsoap
 *   <data>/<station id>/state.json           scheduling state (song counter, fixed times done)
 *
 * Job ids start with the station's local date and time (YYYYMMDD-HHMMSS-xxxxxx), so the
 * newest jobs come first in reverse name order and "today" is a simple file name prefix.
 *
 * Statuses: pending → running → done | failed | skipped.
 */
final class Jobs
{
    public const array STATUSES = ['pending', 'running', 'done', 'failed', 'skipped'];

    public function __construct(
        private ?string $root = null
    ) {
    }

    public function root(): string
    {
        return $this->root ?? Plugin::dataDir();
    }

    public function isWritable(): bool
    {
        $root = $this->root();

        return is_dir($root) && is_writable($root);
    }

    public function dir(int $stationId, string $sub = ''): string
    {
        $dir = $this->root() . '/' . $stationId . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(
                'Le dossier de données du plugin n\'est pas accessible en écriture (' . $this->root()
                . '). Relancez bin/setup.sh dans le dossier du plugin.'
            );
        }

        return $dir;
    }

    public function clipPath(int $stationId, string $id): string
    {
        return $this->dir($stationId, 'clips') . '/' . $id . '.mp3';
    }

    public static function isValidId(string $id): bool
    {
        return (bool)preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id);
    }

    public function create(int $stationId, DateTimeImmutable $localNow, array $job): array
    {
        $id = $localNow->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $job = array_merge([
            'id' => $id,
            'station_id' => $stationId,
            'kind' => 'between',
            'status' => 'pending',
            'created_at' => $localNow->format(DATE_ATOM),
            'play_at' => null,
            'host_id' => '',
            'host_name' => '',
            'prompt_id' => '',
            'prompt_name' => '',
            'variables' => [],
            'text' => '',
            'error' => '',
            'duration' => null,
            'voice_duration' => null,
            'bed' => null,
            'characters' => 0,
            'attempts' => 0,
            'queued' => false,
            'finished_at' => null,
        ], $job, ['id' => $id, 'station_id' => $stationId]);
        $job['path'] = $this->clipPath($stationId, $id);
        $this->save($stationId, $job);

        return $job;
    }

    public function load(int $stationId, string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $file = $this->dir($stationId, 'jobs') . '/' . $id . '.json';
        if (!is_file($file)) {
            return null;
        }
        try {
            $job = Store::load($file);
        } catch (RuntimeException) {
            return null;
        }

        return $job !== [] ? $job : null;
    }

    public function save(int $stationId, array $job): void
    {
        Store::save($this->dir($stationId, 'jobs') . '/' . $job['id'] . '.json', $job);
    }

    /**
     * Runs $callback while holding the job's lock. Returns null without running it if another
     * process holds the lock (unless $wait).
     */
    public function withLock(int $stationId, string $id, callable $callback, bool $wait = false): mixed
    {
        $lock = fopen($this->dir($stationId, 'jobs') . '/' . $id . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Verrouillage impossible de l\'intervention ' . $id);
        }
        try {
            if (!flock($lock, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
                return null;
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Newest first.
     *
     * @return array<int, array>
     */
    public function list(int $stationId, int $limit = 200, ?string $prefix = null): array
    {
        $files = glob($this->dir($stationId, 'jobs') . '/' . ($prefix ?? '') . '*.json') ?: [];
        rsort($files, SORT_STRING);
        $jobs = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            try {
                $job = Store::load($file);
            } catch (RuntimeException) {
                continue;
            }
            if ($job !== []) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * One page of the history, most recent first.
     *
     * @param string $filter "all", "interventions" or "chronicles"
     *
     * @return array{rows: array[], total: int, page: int}
     */
    public function page(int $stationId, int $page, int $perPage, string $filter = 'all'): array
    {
        $files = glob($this->dir($stationId, 'jobs') . '/*.json') ?: [];
        rsort($files, SORT_STRING);

        if ($filter === 'interventions' || $filter === 'chronicles') {
            // The kind is only in the file: read them all (kept a limited number of days).
            $jobs = [];
            foreach ($files as $file) {
                try {
                    $job = Store::load($file);
                } catch (RuntimeException) {
                    continue;
                }
                if ($job !== [] && (($job['kind'] ?? '') === 'chronicle') === ($filter === 'chronicles')) {
                    $jobs[] = $job;
                }
            }
            $result = self::slice(count($jobs), $page, $perPage);

            return ['rows' => array_slice($jobs, $result['offset'], $perPage), 'total' => count($jobs), 'page' => $result['page']];
        }

        $result = self::slice(count($files), $page, $perPage);
        $jobs = [];
        foreach (array_slice($files, $result['offset'], $perPage) as $file) {
            try {
                $job = Store::load($file);
            } catch (RuntimeException) {
                continue;
            }
            if ($job !== []) {
                $jobs[] = $job;
            }
        }

        return ['rows' => $jobs, 'total' => count($files), 'page' => $result['page']];
    }

    /**
     * Page actually shown (the last one if $page is past the end) and its first item.
     * Pure function, tested.
     *
     * @return array{page: int, offset: int}
     */
    public static function slice(int $total, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return ['page' => $page, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * Interventions counted against the daily limit: everything that was or will be generated
     * through the APIs. Clips reused from the library cost nothing and are not counted.
     */
    public function countToday(int $stationId, DateTimeImmutable $localNow): int
    {
        return $this->todayStats($stationId, $localNow)['generated'];
    }

    /** @return array{generated: int, reused: int} */
    public function todayStats(int $stationId, DateTimeImmutable $localNow): array
    {
        $stats = ['generated' => 0, 'reused' => 0];
        foreach ($this->list($stationId, 10000, $localNow->format('Ymd') . '-') as $job) {
            $status = $job['status'] ?? '';
            if ($status === 'skipped' || ($job['kind'] ?? '') === 'chronicle') {
                continue; // chronicles are scheduled on purpose: not counted against the limit
            }
            if (!empty($job['reused'])) {
                if ($status === 'done') {
                    $stats['reused']++;
                }
            } elseif (empty($job['reuse_only'])) {
                $stats['generated']++;
            }
        }

        return $stats;
    }

    /** @return string[] last texts of a host, most recent first */
    public function recentTexts(int $stationId, string $hostId, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $texts = [];
        foreach ($this->list($stationId, 300) as $job) {
            if (($job['host_id'] ?? '') === $hostId && ($job['status'] ?? '') === 'done' && ($job['text'] ?? '') !== '') {
                $texts[] = (string)$job['text'];
                if (count($texts) >= $count) {
                    break;
                }
            }
        }

        return $texts;
    }

    /** @return array<int, array> jobs waiting for generation, oldest first */
    public function pending(int $stationId): array
    {
        $jobs = array_filter($this->list($stationId, 300), static fn(array $j): bool => ($j['status'] ?? '') === 'pending');

        return array_reverse(array_values($jobs));
    }

    /**
     * @param callable(array): array $callback
     */
    public function updateState(int $stationId, callable $callback): array
    {
        $dir = $this->dir($stationId);
        $lock = fopen($dir . '/state.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrouillage impossible de l\'état de la station.');
        }
        try {
            $state = Store::load($dir . '/state.json');
            $new = $callback($state);
            if ($new !== $state) {
                Store::save($dir . '/state.json', $new);
            }

            return $new;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function readState(int $stationId): array
    {
        return Store::load($this->dir($stationId) . '/state.json');
    }

    /**
     * Deletes jobs and clips older than $days, plus leftovers of interrupted work.
     * A clip is never deleted while it may still be in AzuraCast's queue (play time in the future).
     *
     * @return int number of deleted interventions
     */
    public function cleanup(int $stationId, int $days, ?int $now = null): int
    {
        $now ??= time();
        $limit = $now - $days * 86400;
        $deleted = 0;
        foreach (glob($this->dir($stationId, 'jobs') . '/*.json') ?: [] as $file) {
            if (filemtime($file) >= $limit) {
                continue;
            }
            $job = Store::load($file);
            $playAt = isset($job['play_at']) ? strtotime((string)$job['play_at']) : false;
            if ($playAt !== false && $playAt > $now - 3600) {
                continue;
            }
            @unlink($this->dir($stationId, 'clips') . '/' . basename($file, '.json') . '.mp3');
            @unlink($file);
            $deleted++;
        }
        foreach (glob($this->dir($stationId, 'clips') . '/*.mp3') ?: [] as $clip) {
            if (filemtime($clip) < $limit && !is_file($this->dir($stationId, 'jobs') . '/' . basename($clip, '.mp3') . '.json')) {
                @unlink($clip);
            }
        }
        foreach (array_merge(
            glob($this->dir($stationId, 'tmp') . '/*') ?: [],
            glob($this->dir($stationId, 'jobs') . '/.av-*') ?: [],
            glob($this->dir($stationId, 'jobs') . '/*.lock') ?: [],
            glob($this->dir($stationId, 'clips') . '/.av-*') ?: []
        ) as $leftover) {
            if (is_file($leftover) && filemtime($leftover) < $now - 86400) {
                @unlink($leftover);
            }
        }

        return $deleted;
    }

    /**
     * Deletes the history, except interventions still waiting to be aired.
     */
    public function clear(int $stationId, ?int $now = null): int
    {
        $now ??= time();
        $deleted = 0;
        foreach ($this->list($stationId, 100000) as $job) {
            $status = $job['status'] ?? '';
            $playAt = isset($job['play_at']) ? strtotime((string)$job['play_at']) : false;
            if (in_array($status, ['pending', 'running'], true) || ($playAt !== false && $playAt > $now - 1800)) {
                continue;
            }
            @unlink($this->dir($stationId, 'clips') . '/' . $job['id'] . '.mp3');
            @unlink($this->dir($stationId, 'jobs') . '/' . $job['id'] . '.json');
            $deleted++;
        }

        return $deleted;
    }
}
