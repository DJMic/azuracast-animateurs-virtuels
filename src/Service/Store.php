<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use App\Entity\Station;
use JsonException;
use RuntimeException;

/**
 * Station settings, stored in the station's configuration folder
 * (/var/azuracast/stations/<station>/config/animateurs-virtuels.json, mode 0600 because it
 * contains the API keys). This folder survives AzuraCast and plugin updates.
 */
final class Store
{
    public const string FILE = 'animateurs-virtuels.json';

    /** @var array<int, array{mtime: int, data: array}> */
    private array $cache = [];

    public function read(Station $station): array
    {
        $file = $this->path($station);
        clearstatcache(true, $file);
        $mtime = is_file($file) ? (int)filemtime($file) : 0;
        $id = (int)$station->id;
        if (isset($this->cache[$id]) && $this->cache[$id]['mtime'] === $mtime && $mtime !== 0) {
            return $this->cache[$id]['data'];
        }

        $data = Settings::withDefaults(self::load($file));
        $this->cache[$id] = ['mtime' => $mtime, 'data' => $data];

        return $data;
    }

    public function write(Station $station, array $settings): void
    {
        $file = $this->path($station);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Dossier de configuration de la station introuvable : ' . $dir);
        }
        $lock = fopen($dir . '/animateurs-virtuels.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Impossible de verrouiller les réglages des animateurs virtuels.');
        }
        try {
            self::save($file, $settings, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        unset($this->cache[(int)$station->id]);
    }

    private function path(Station $station): string
    {
        return $station->getRadioConfigDir() . '/' . self::FILE;
    }

    public static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Lecture impossible : ' . $file);
        }
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Fichier illisible (' . $e->getMessage() . ') : ' . $file);
        }

        return is_array($data) ? $data : [];
    }

    /** Atomic write (temporary file + rename). */
    public static function save(string $file, array $data, int $mode = 0644): void
    {
        $dir = dirname($file);
        $tmp = tempnam($dir, '.av-');
        if ($tmp === false) {
            throw new RuntimeException('Écriture impossible dans ' . $dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . "\n") === false || !chmod($tmp, $mode) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Écriture impossible : ' . $file);
        }
    }
}
