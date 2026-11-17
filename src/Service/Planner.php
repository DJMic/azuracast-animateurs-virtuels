<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Decides who is on air and when an intervention is due.
 * Pure functions, no AzuraCast dependency (tested by tests/run.php).
 *
 * Time slots: days 1 (Monday) to 7 (Sunday), start and end "HH:MM". An end earlier than the start
 * means the slot runs past midnight; the day is the day the slot starts. Equal start and end = 24 h.
 */
final class Planner
{
    /** A fixed-time intervention is still aired if the queue reaches that time up to this late. */
    public const int FIXED_GRACE_SECONDS = 900;

    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    public static function minutesFromStart(string $time, string $start): int
    {
        return (self::minutes($time) - self::minutes($start) + 1440) % 1440;
    }

    public static function slotLength(string $start, string $end): int
    {
        $length = (self::minutes($end) - self::minutes($start) + 1440) % 1440;

        return $length === 0 ? 1440 : $length;
    }

    public static function timeInSlot(string $time, string $start, string $end): bool
    {
        return self::minutesFromStart($time, $start) < self::slotLength($start, $end);
    }

    /**
     * Host and slot on air at $local (station time), or null.
     * Hosts are checked in list order: the first one wins when slots overlap.
     *
     * @return array{host: array, slot: array, start: DateTimeImmutable}|null
     */
    public static function onAir(array $settings, DateTimeImmutable $local): ?array
    {
        foreach ($settings['hosts'] ?? [] as $host) {
            if (empty($host['enabled']) || ($host['voice_id'] ?? '') === '') {
                continue;
            }
            foreach ($host['slots'] ?? [] as $slot) {
                foreach ([0, -1] as $offset) {
                    $day = $local->setTime(0, 0)->modify(($offset === 0 ? '+0' : '-1') . ' day');
                    $start = $day->setTime(...array_map('intval', explode(':', $slot['start'])));
                    if (!in_array((int)$start->format('N'), $slot['days'], true)) {
                        continue;
                    }
                    $end = $start->add(new DateInterval('PT' . self::slotLength($slot['start'], $slot['end']) . 'M'));
                    if ($local >= $start && $local < $end) {
                        return ['host' => $host, 'slot' => $slot, 'start' => $start];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Called each time AzuraCast adds an item (song or jingle) to the queue during a slot.
     * The intervention, if any, is placed just before that next item.
     *
     * "Every N songs": the host speaks right after the Nth song (jingles are not counted), so
     * with music / jingle / music… the intervention comes between the song and the jingle.
     * Fixed time: before the first item that starts at or after that time.
     *
     * @param array $state  scheduling state of the station (see return)
     * @param array $onAir  result of onAir() for the expected play time
     * @param DateTimeImmutable $playAt expected start of the next item (station time)
     * @param bool $nextIsMusic the next item is a song (not a jingle)
     * @param bool $prevIsMusic the item just before is a song (not a jingle nor an intervention)
     * @param bool $canPlace false if a clip could not be ready in time here: nothing is decided,
     *                       a due intervention waits for the next item
     *
     * @return array{0: ?array{kind: string, time: ?string}, 1: array} decision and new state
     */
    public static function decide(
        array $state,
        array $onAir,
        DateTimeImmutable $playAt,
        bool $nextIsMusic,
        bool $prevIsMusic = true,
        bool $canPlace = true
    ): array {
        $slot = $onAir['slot'];
        $start = $onAir['start'];
        $slotKey = $onAir['host']['id'] . '|' . $slot['id'] . '|' . $start->format('Y-m-d H:i');

        if (($state['slot'] ?? null) !== $slotKey) {
            $state['slot'] = $slotKey;
            $state['count'] = 0;
        }
        $state['count'] = (int)($state['count'] ?? 0);
        $done = is_array($state['done'] ?? null) ? $state['done'] : [];

        $decision = null;
        foreach ($slot['times'] ?? [] as $time) {
            $key = $slotKey . '|' . $time;
            if (isset($done[$key])) {
                continue;
            }
            $at = $start->add(new DateInterval('PT' . self::minutesFromStart($time, $slot['start']) . 'M'));
            $late = $playAt->getTimestamp() - $at->getTimestamp();
            if ($late >= 0 && $late <= self::FIXED_GRACE_SECONDS && $canPlace) {
                $decision = ['kind' => 'fixed', 'time' => $time];
                $done[$key] = $playAt->getTimestamp();
                break;
            }
        }

        $everyN = (int)($slot['every_n'] ?? 0);
        if ($decision === null && $canPlace && $everyN > 0 && $prevIsMusic && $state['count'] >= $everyN) {
            $decision = ['kind' => 'between', 'time' => null];
        }

        if ($decision !== null) {
            $state['count'] = $nextIsMusic ? 1 : 0;
        } elseif ($nextIsMusic) {
            $state['count']++;
        }

        $limit = $playAt->getTimestamp() - 2 * 86400;
        $state['done'] = array_filter($done, static fn(int $ts): bool => $ts >= $limit);

        return [$decision, $state];
    }

    /**
     * Random active prompt for this kind of intervention ("between", "fixed", "test"...).
     * Prompts reserved for the other kind are only used if nothing else is available.
     */
    public static function pickPrompt(array $host, string $kind, ?string $promptId = null): ?array
    {
        $active = array_values(array_filter($host['prompts'] ?? [], static fn(array $p): bool => !empty($p['enabled'])));
        if ($promptId !== null && $promptId !== '') {
            foreach ($host['prompts'] ?? [] as $prompt) {
                if ($prompt['id'] === $promptId) {
                    return $prompt;
                }
            }
        }
        if ($active === []) {
            return null;
        }
        $matching = in_array($kind, ['between', 'fixed'], true)
            ? array_values(array_filter($active, static fn(array $p): bool => in_array($p['usage'], ['all', $kind], true)))
            : $active;

        $pool = $matching !== [] ? $matching : $active;

        return $pool[array_rand($pool)];
    }
}
