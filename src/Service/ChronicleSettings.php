<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use InvalidArgumentException;

/**
 * Chronicles: validation of the settings sent by the browser (pure functions, tested).
 *
 * A chronicle is written by DeepSeek from its sources (web pages, RSS feeds, weather), read by
 * ElevenLabs, mixed over a music bed with optional opening/closing jingles, then saved in the
 * station's media library and put in a playlist (replacing the previous version).
 */
final class ChronicleSettings
{
    public const int MAX_SOURCES = 10;

    public const string DEFAULT_PROMPT = 'Présente les informations les plus importantes des sources, '
        . 'dans l\'ordre qui te paraît le plus intéressant pour les auditeurs.';

    public static function defaults(): array
    {
        return [
            'id' => '',
            'name' => '',
            'enabled' => true,
            'voice_mode' => 'host',
            'host_id' => '',
            // Own voice: "elevenlabs" (account_id, voice_id, voice) or "voxtral" (voxtral).
            'provider' => 'elevenlabs',
            'voxtral' => VoxtralVoice::defaults(),
            'account_id' => '',
            'voice_id' => '',
            'voice_name' => '',
            'voice' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.0,
                'speed' => 1.0,
                'speaker_boost' => true,
            ],
            'style' => '',
            'prompt' => self::DEFAULT_PROMPT,
            'target_seconds' => 60,
            'sources' => [],
            'freshness_hours' => 24,
            'bed_media' => '',
            'bed_media_length' => null,
            'sound' => self::defaultSound(),
            'intro_media' => '',
            'outro_media' => '',
            'playlist_id' => null,
            'folder' => '',
            'retention_days' => 7,
            // Hours after which a version leaves the playlist if no newer one replaced it (0 = never).
            'valid_hours' => 0,
            'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'times' => []],
            'meta_title' => '{chronique} du {date}',
            'meta_artist' => '{station}',
        ];
    }

    /** Sound settings of a chronicle (its own, independent from the interventions). */
    public static function defaultSound(): array
    {
        return [
            'duck_db' => 15.0,
            'bed_gain_db' => 0.0,
            'intro' => 3.0,
            'outro' => 3.0,
            'fade_in' => 1.0,
            'fade_out' => 2.0,
            'ramp' => 0.8,
            'random_start' => false,
            'normalize' => true,
            'lufs' => -14.0,
            'bitrate' => 192,
        ];
    }

    /**
     * Mixer settings for a chronicle: the bed is played once, never looped.
     *
     * @return array{0: array, 1: array} bed settings, output settings
     */
    public static function mixSettings(array $chronicle): array
    {
        $sound = array_replace(self::defaultSound(), is_array($chronicle['sound'] ?? null) ? $chronicle['sound'] : []);

        return [
            [
                'duck_db' => $sound['duck_db'],
                'bed_gain_db' => $sound['bed_gain_db'],
                'intro' => $sound['intro'],
                'outro' => $sound['outro'],
                'fade_in' => $sound['fade_in'],
                'fade_out' => $sound['fade_out'],
                'ramp' => $sound['ramp'],
                'random_start' => $sound['random_start'],
                'loop' => false,
            ],
            ['normalize' => $sound['normalize'], 'lufs' => $sound['lufs'], 'bitrate' => $sound['bitrate']],
        ];
    }

    public static function defaultSource(): array
    {
        return [
            'id' => '',
            'type' => 'url',
            'url' => '',
            'label' => '',
            'location' => '',
            'latitude' => null,
            'longitude' => null,
            'location_label' => '',
        ];
    }

    /** "Chroniques/<name>" with characters safe for every storage. */
    public static function defaultFolder(string $name): string
    {
        return 'Chroniques/' . (self::slug($name, ' ') ?: 'Chronique');
    }

    /**
     * @param string[] $hostIds    ids of the station's hosts
     * @param string[] $accountIds ids of the station's ElevenLabs accounts
     * @param bool     $voxtral    whether the Mistral key is set
     */
    public static function validate(array $in, array $hostIds, array $accountIds, bool $voxtral = false): array
    {
        $d = self::defaults();
        $name = self::text($in['name'] ?? '', 80);
        if ($name === '') {
            throw new InvalidArgumentException('Chaque chronique doit avoir un nom.');
        }
        $label = 'Chronique « ' . $name . ' »';
        $enabled = self::bool($in['enabled'] ?? true);

        $voiceMode = ($in['voice_mode'] ?? 'host') === 'own' ? 'own' : 'host';
        $voice = is_array($in['voice'] ?? null) ? $in['voice'] : [];
        $out = [
            'id' => Settings::uid($in['id'] ?? ''),
            'name' => $name,
            'enabled' => $enabled,
            'voice_mode' => $voiceMode,
            'host_id' => (string)($in['host_id'] ?? ''),
            'provider' => ($in['provider'] ?? 'elevenlabs') === 'voxtral' ? 'voxtral' : 'elevenlabs',
            'voxtral' => VoxtralVoice::validate($in['voxtral'] ?? [], 'Chronique « ' . $name . ' »'),
            'account_id' => (string)($in['account_id'] ?? ''),
            'voice_id' => self::text($in['voice_id'] ?? '', 64),
            'voice_name' => self::text($in['voice_name'] ?? '', 80),
            'voice' => [
                'stability' => self::float($voice['stability'] ?? 0.5, 0.0, 1.0),
                'similarity_boost' => self::float($voice['similarity_boost'] ?? 0.75, 0.0, 1.0),
                'style' => self::float($voice['style'] ?? 0.0, 0.0, 1.0),
                'speed' => self::float($voice['speed'] ?? 1.0, 0.7, 1.2),
                'speaker_boost' => self::bool($voice['speaker_boost'] ?? true),
            ],
            'style' => self::text($in['style'] ?? '', 4000, true),
            'prompt' => self::text($in['prompt'] ?? '', 6000, true) ?: self::DEFAULT_PROMPT,
            'target_seconds' => self::int($in['target_seconds'] ?? 60, 10, 600),
            'sources' => [],
            'freshness_hours' => self::int($in['freshness_hours'] ?? 24, 0, 24 * 31),
            'bed_media' => self::mediaPath($in['bed_media'] ?? '', $label . ' : fond musical'),
            'bed_media_length' => is_numeric($in['bed_media_length'] ?? null) ? round((float)$in['bed_media_length'], 1) : null,
            'sound' => self::sound(is_array($in['sound'] ?? null) ? $in['sound'] : []),
            'intro_media' => self::mediaPath($in['intro_media'] ?? '', $label . ' : jingle d\'ouverture'),
            'outro_media' => self::mediaPath($in['outro_media'] ?? '', $label . ' : jingle de fermeture'),
            'playlist_id' => self::id($in['playlist_id'] ?? null),
            'folder' => self::folder((string)($in['folder'] ?? ''), $name, $label),
            'retention_days' => self::int($in['retention_days'] ?? 7, 0, 3650),
            'valid_hours' => self::int($in['valid_hours'] ?? 0, 0, 720),
            'schedule' => self::schedule(is_array($in['schedule'] ?? null) ? $in['schedule'] : [], $label),
            'meta_title' => self::text($in['meta_title'] ?? '', 120) ?: $d['meta_title'],
            'meta_artist' => self::text($in['meta_artist'] ?? '', 120),
        ];

        // Voice
        if ($voiceMode === 'host') {
            if (!in_array($out['host_id'], $hostIds, true)) {
                if ($enabled) {
                    throw new InvalidArgumentException($label . ' : choisissez l\'animateur dont la voix est utilisée.');
                }
                $out['host_id'] = '';
            }
        } elseif ($out['provider'] === 'voxtral') {
            if (!in_array($out['account_id'], $accountIds, true)) {
                $out['account_id'] = '';
            }
            if ($enabled && !VoxtralVoice::isSet($out['voxtral'])) {
                throw new InvalidArgumentException($out['voxtral']['mode'] === 'sample'
                    ? $label . ' : choisissez le fichier de voix de référence.'
                    : $label . ' : choisissez la voix Voxtral.');
            }
            if ($enabled && !$voxtral) {
                throw new InvalidArgumentException($label . ' : renseignez la clé API Mistral (Services IA) pour utiliser Voxtral.');
            }
        } else {
            if ($out['voice_id'] !== '' && !preg_match('/^[A-Za-z0-9_-]{5,64}$/', $out['voice_id'])) {
                throw new InvalidArgumentException($label . ' : Voice ID invalide.');
            }
            if (!in_array($out['account_id'], $accountIds, true)) {
                $out['account_id'] = $accountIds[0] ?? '';
            }
            if ($enabled && ($out['voice_id'] === '' || $out['account_id'] === '')) {
                throw new InvalidArgumentException($label . ' : indiquez un compte ElevenLabs et un Voice ID.');
            }
        }

        // Sources
        $sources = is_array($in['sources'] ?? null) ? $in['sources'] : [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $type = ($source['type'] ?? 'url') === 'weather' ? 'weather' : 'url';
            $item = self::defaultSource();
            $item['id'] = Settings::uid($source['id'] ?? '');
            $item['type'] = $type;
            $item['label'] = self::text($source['label'] ?? '', 80);
            if ($type === 'url') {
                $url = trim((string)($source['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if (!preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url) || strlen($url) > 2000) {
                    throw new InvalidArgumentException($label . ' : adresse de source invalide (' . mb_substr($url, 0, 80) . ').');
                }
                $item['url'] = $url;
            } else {
                $item['location'] = self::text($source['location'] ?? '', 120);
                if ($item['location'] === '') {
                    continue;
                }
                $lat = $source['latitude'] ?? null;
                $lon = $source['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lon) && abs((float)$lat) <= 90 && abs((float)$lon) <= 180) {
                    $item['latitude'] = round((float)$lat, 4);
                    $item['longitude'] = round((float)$lon, 4);
                    $item['location_label'] = self::text($source['location_label'] ?? '', 160);
                }
            }
            $out['sources'][] = $item;
        }
        if (count($out['sources']) > self::MAX_SOURCES) {
            throw new InvalidArgumentException($label . ' : au maximum ' . self::MAX_SOURCES . ' sources.');
        }
        if ($enabled && $out['sources'] === []) {
            throw new InvalidArgumentException($label . ' : ajoutez au moins une source.');
        }

        return $out;
    }

    /**
     * Voice of the chronicle, resolved for Speech::speak() (see Settings::speech()); null if it
     * cannot be used. In mode "host", the host's voice and service.
     */
    public static function voice(array $settings, array $chronicle): ?array
    {
        if ($chronicle['voice_mode'] === 'host') {
            $host = Settings::findHost($settings, (string)$chronicle['host_id']);

            return $host === null ? null : Settings::speech($settings, $host);
        }

        $name = $chronicle['voice_name'] !== '' ? $chronicle['voice_name'] : $chronicle['name'];
        if (($chronicle['provider'] ?? 'elevenlabs') === 'voxtral') {
            return Settings::voxtralSpeech($settings, is_array($chronicle['voxtral'] ?? null) ? $chronicle['voxtral'] : [], $name, '');
        }
        $account = Settings::findAccount($settings, (string)$chronicle['account_id'])
            ?? ($settings['elevenlabs']['accounts'][0] ?? null);
        if ($account === null || $account['api_key'] === '' || $chronicle['voice_id'] === '') {
            return null;
        }

        return Settings::elevenLabsSpeech($settings, $account, $chronicle['voice_id'], $chronicle['voice'], $name, '');
    }

    /**
     * Versions to take out of the playlist: published more than valid_hours ago and not taken out
     * yet (only those older than the newest version are left to replaceInPlaylist()). Pure, tested.
     *
     * @param array<int, array{media_id: int, created_at: int}> $files registry of the chronicle
     *
     * @return int[] media ids
     */
    public static function expired(array $chronicle, array $files, int $now): array
    {
        $hours = (int)($chronicle['valid_hours'] ?? 0);
        if ($hours <= 0 || empty($chronicle['playlist_id'])) {
            return [];
        }
        $ids = [];
        foreach ($files as $file) {
            if (empty($file['withdrawn_at']) && (int)($file['created_at'] ?? 0) <= $now - $hours * 3600) {
                $ids[] = (int)$file['media_id'];
            }
        }

        return $ids;
    }

    /**
     * Scheduled generation due at $local (station time): the slot "Y-m-d H:i" or null.
     * A time is still generated up to 30 minutes late (server restart…), never twice.
     *
     * @param string[] $done slots already generated
     */
    public static function dueSlot(array $chronicle, \DateTimeImmutable $local, array $done): ?string
    {
        if (empty($chronicle['enabled'])) {
            return null;
        }
        foreach ([0, -1] as $offset) {
            $day = $local->setTime(0, 0)->modify($offset . ' day');
            if (!in_array((int)$day->format('N'), $chronicle['schedule']['days'], true)) {
                continue;
            }
            foreach ($chronicle['schedule']['times'] as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $at = $day->setTime($h, $m);
                $late = $local->getTimestamp() - $at->getTimestamp();
                $key = $at->format('Y-m-d H:i');
                if ($late >= 0 && $late < 1800 && !in_array($key, $done, true)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** File name in the media library: Meteo_2026-11-18_0630.mp3 */
    public static function fileName(string $name, \DateTimeImmutable $local): string
    {
        return (self::slug($name, '_') ?: 'Chronique') . '_' . $local->format('Y-m-d_Hi') . '.mp3';
    }

    // --- helpers ------------------------------------------------------------------------------------

    public static function slug(string $text, string $space): string
    {
        $ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
        $ascii = (string)preg_replace('/[^A-Za-z0-9 _-]+/', '', $ascii);
        $ascii = trim((string)preg_replace('/[\s_]+/', ' ', $ascii));

        return str_replace(' ', $space, mb_substr($ascii, 0, 60));
    }

    private static function sound(array $in): array
    {
        $bitrate = (int)($in['bitrate'] ?? 192);

        return [
            'duck_db' => self::float($in['duck_db'] ?? 15, 0.0, 60.0),
            'bed_gain_db' => self::float($in['bed_gain_db'] ?? 0, -40.0, 12.0),
            'intro' => self::float($in['intro'] ?? 3, 0.0, 30.0),
            'outro' => self::float($in['outro'] ?? 3, 0.0, 30.0),
            'fade_in' => self::float($in['fade_in'] ?? 1, 0.0, 10.0),
            'fade_out' => self::float($in['fade_out'] ?? 2, 0.0, 15.0),
            'ramp' => self::float($in['ramp'] ?? 0.8, 0.05, 5.0),
            'random_start' => self::bool($in['random_start'] ?? false),
            'normalize' => self::bool($in['normalize'] ?? true),
            'lufs' => self::float($in['lufs'] ?? -14, -30.0, -6.0),
            'bitrate' => in_array($bitrate, [128, 160, 192, 256, 320], true) ? $bitrate : 192,
        ];
    }

    private static function folder(string $folder, string $name, string $label): string
    {
        $folder = trim(str_replace('\\', '/', $folder), " /\t");
        if ($folder === '') {
            return self::defaultFolder($name);
        }
        $parts = [];
        foreach (explode('/', $folder) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || preg_match('/[<>:"|?*\x00-\x1F]/', $part)) {
                throw new InvalidArgumentException($label . ' : dossier invalide (« ' . $part . ' »).');
            }
            $parts[] = mb_substr($part, 0, 80);
        }
        if ($parts === [] || count($parts) > 6) {
            throw new InvalidArgumentException($label . ' : dossier invalide.');
        }

        return implode('/', $parts);
    }

    public static function mediaPath(mixed $value, string $label): string
    {
        $path = trim((string)$value);
        if ($path === '') {
            return '';
        }
        if (str_contains($path, '..') || str_starts_with($path, '/') || strlen($path) > 500) {
            throw new InvalidArgumentException($label . ' invalide.');
        }

        return $path;
    }

    private static function schedule(array $in, string $label): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', is_array($in['days'] ?? null) ? $in['days'] : []),
            static fn(int $d): bool => $d >= 1 && $d <= 7
        )));
        sort($days);
        $times = [];
        foreach (is_array($in['times'] ?? null) ? $in['times'] : [] as $time) {
            $time = trim((string)$time);
            if ($time === '') {
                continue;
            }
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
                throw new InvalidArgumentException($label . ' : heure de génération invalide (' . $time . ').');
            }
            $times[] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        $times = array_values(array_unique($times));
        sort($times);
        if ($times !== [] && $days === []) {
            throw new InvalidArgumentException($label . ' : choisissez au moins un jour de génération.');
        }

        return ['days' => $days, 'times' => $times];
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        $value = $multiline
            ? trim(str_replace("\r\n", "\n", $value))
            : trim((string)preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $max);
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    private static function int(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int)$value : $min));
    }

    private static function float(mixed $value, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($value) ? (float)$value : $min)), 2);
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }
}
