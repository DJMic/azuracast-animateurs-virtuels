<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

final class Plugin
{
    public const string NAME = 'azuracast-animateurs-virtuels';

    /** Writable folder mounted by bin/setup.sh (host: <plugin>/data). */
    public const string DATA_DIR = '/var/azuracast/plugin-data/animateurs-virtuels';

    public static function dir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function version(): string
    {
        static $version = null;
        if ($version === null) {
            $raw = @file_get_contents(self::dir() . '/VERSION');
            $version = is_string($raw) && trim($raw) !== '' ? trim($raw) : 'dev';
        }

        return $version;
    }

    /**
     * Folder for generated clips, jobs and scheduling state.
     * Outside Docker (tests, development) the plugin's own data/ folder is used.
     */
    public static function dataDir(): string
    {
        $env = getenv('ANIMATEURS_VIRTUELS_DATA_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }

        return is_dir(self::DATA_DIR) ? self::DATA_DIR : self::dir() . '/data';
    }

    /** Half a second of silence, aired if a clip is missing when Liquidsoap asks for it. */
    public static function silenceFile(): string
    {
        return self::dir() . '/assets/silence.mp3';
    }
}
