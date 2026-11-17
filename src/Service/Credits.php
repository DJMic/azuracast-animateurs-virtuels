<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use DateTimeImmutable;
use Throwable;

/**
 * ElevenLabs credits of each account of a station: read through the API (free call), kept for
 * a few minutes in <data>/<station id>/credits.json, refreshed after each generation.
 */
final class Credits
{
    /** Credits are read again from ElevenLabs after this many seconds. */
    public const int MAX_AGE = 600;

    public function __construct(
        private readonly ElevenLabs $elevenLabs,
        private readonly Jobs $jobs
    ) {
    }

    /**
     * Credits of all the accounts, refreshed if older than MAX_AGE (or if $force).
     *
     * @return array<int, array>
     */
    public function all(int $stationId, array $settings, bool $force = false): array
    {
        $cache = $this->load($stationId);
        $rows = [];
        $changed = false;
        foreach ($settings['elevenlabs']['accounts'] ?? [] as $account) {
            $entry = $cache[$account['id']] ?? null;
            $stale = !is_array($entry) || ($entry['key_hint'] ?? '') !== self::hint($account['api_key'])
                || time() - (int)($entry['checked_at'] ?? 0) >= self::MAX_AGE;
            if ($force || $stale) {
                $entry = $this->fetch($account);
                $cache[$account['id']] = $entry;
                $changed = true;
            }
            $rows[] = self::describe($account, $entry, (int)($settings['elevenlabs']['low_credits'] ?? 0), time());
        }
        $cache = array_intersect_key($cache, array_flip(array_column($settings['elevenlabs']['accounts'] ?? [], 'id')));
        if ($changed) {
            $this->save($stationId, $cache);
        }

        return $rows;
    }

    /** Reads one account again (after a generation). Errors are kept in the cache, never thrown. */
    public function refresh(int $stationId, array $account): void
    {
        try {
            $cache = $this->load($stationId);
            $cache[$account['id']] = $this->fetch($account);
            $this->save($stationId, $cache);
        } catch (Throwable) {
            // Display only.
        }
    }

    /** Remaining credits known for an account (null if unknown or unreadable). */
    public function remaining(int $stationId, string $accountId): ?int
    {
        $entry = $this->load($stationId)[$accountId] ?? null;
        if (!is_array($entry) || !isset($entry['limit']) || ($entry['error'] ?? '') !== '') {
            return null;
        }
        if (time() - (int)($entry['checked_at'] ?? 0) > 6 * 3600) {
            return null; // too old to block anything
        }

        return max(0, (int)$entry['limit'] - (int)$entry['used']);
    }

    private function fetch(array $account): array
    {
        $entry = ['checked_at' => time(), 'key_hint' => self::hint($account['api_key']), 'error' => ''];
        try {
            $entry += $this->elevenLabs->subscription((string)$account['api_key']);
        } catch (Throwable $e) {
            $entry['error'] = $e->getMessage();
        }

        return $entry;
    }

    /**
     * Row shown in the interface (pure function, tested): credits, reset date and the date the
     * credits would run out at the current pace (null if they last until the reset).
     */
    public static function describe(array $account, ?array $entry, int $lowThreshold, int $now): array
    {
        $row = [
            'id' => $account['id'],
            'name' => $account['name'],
            'checked_at' => $entry['checked_at'] ?? null,
            'error' => (string)($entry['error'] ?? ''),
            'tier' => (string)($entry['tier'] ?? ''),
            'used' => null,
            'limit' => null,
            'remaining' => null,
            'reset' => $entry['reset'] ?? null,
            'empty_at' => null,
            'low' => false,
            'exhausted' => false,
        ];
        if ($entry === null || $row['error'] !== '' || !isset($entry['limit'])) {
            return $row;
        }

        $row['used'] = (int)$entry['used'];
        $row['limit'] = (int)$entry['limit'];
        $row['remaining'] = max(0, $row['limit'] - $row['used']);
        $row['exhausted'] = $row['limit'] > 0 && $row['remaining'] === 0;
        $row['low'] = !$row['exhausted'] && $row['remaining'] <= $lowThreshold;

        // Pace since the start of the monthly period (reset date minus one month).
        if ($row['reset'] !== null && $row['used'] > 0 && $row['remaining'] > 0) {
            $start = (new DateTimeImmutable('@' . $row['reset']))->modify('-1 month')->getTimestamp();
            $elapsed = $now - $start;
            if ($elapsed >= 6 * 3600) {
                $emptyAt = $now + (int)round($row['remaining'] / ($row['used'] / $elapsed));
                if ($emptyAt < $row['reset']) {
                    $row['empty_at'] = $emptyAt;
                }
            }
        }

        return $row;
    }

    private static function hint(string $key): string
    {
        return substr(sha1($key), 0, 8);
    }

    private function load(int $stationId): array
    {
        try {
            return Store::load($this->jobs->dir($stationId) . '/credits.json');
        } catch (Throwable) {
            return [];
        }
    }

    private function save(int $stationId, array $cache): void
    {
        try {
            Store::save($this->jobs->dir($stationId) . '/credits.json', $cache);
        } catch (Throwable) {
            // Display only.
        }
    }
}
