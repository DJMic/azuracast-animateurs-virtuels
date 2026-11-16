<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use RuntimeException;

/**
 * Library of timeless interventions, per host: the text and the recorded voice (before mixing).
 * A reused intervention takes a voice from here and gets a new music bed: no DeepSeek or
 * ElevenLabs call.
 *
 *   <data>/<station id>/library/<host id>/<entry id>.json   text, voice signature, uses
 *   <data>/<station id>/library/<host id>/<entry id>.mp3    voice only
 *
 * Entries recorded with other voice settings (another Voice ID, model or voice tuning) are
 * "obsolete": kept and listed, but never reused.
 * Pure PHP (no AzuraCast dependency), tested by tests/run.php.
 */
final class Library
{
    /** Variables that make a prompt depend on the moment: such a prompt cannot be timeless. */
    public const array CONTEXT_VARIABLES = [
        'heure', 'jour', 'date', 'moment',
        'titre_precedent', 'artiste_precedent', 'titre_suivant', 'artiste_suivant',
    ];

    /** Added to the request of a timeless prompt. */
    public const string TIMELESS_INSTRUCTION = 'Important : ce texte sera rediffusé plus tard, à n\'importe quel moment. '
        . 'Ne mentionne ni l\'heure, ni le moment de la journée, ni le jour, ni la date, ni la saison, ni la météo, '
        . 'ni aucun morceau ou artiste. Pas de « bonjour », « bonsoir » ou « bonne journée ».';

    /**
     * Words and expressions that tie a text to a moment or to the songs around it
     * (checked on whole words, case-insensitive).
     */
    private const array TIME_MARKERS = [
        'bonjour', 'bonsoir', 'bonne nuit', 'bonne journée', 'bonne soirée', 'bonne matinée', 'bon après-midi',
        'bon week-end', 'bon weekend', 'bonne semaine', 'bonne année', 'joyeux noël',
        'matin', 'matinée', 'matinale', 'midi', 'après-midi', 'soir', 'soirée', 'nuit', 'minuit', 'aube', 'réveil',
        'aujourd\'hui', 'demain', 'hier', 'tout à l\'heure', 'ce week-end', 'ce weekend', 'cette semaine',
        'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche', 'week-end', 'weekend',
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre',
        'novembre', 'décembre', 'printemps', 'cet été', 'l\'été', 'automne', 'hiver', 'noël', 'nouvel an', 'vacances',
        'météo', 'soleil', 'pluie', 'neige', 'températures', 'il fait chaud', 'il fait froid',
        'heure', 'heures', 'pile',
        'on vient d\'écouter', 'vous venez d\'écouter', 'juste avant', 'juste après', 'titre suivant',
        'morceau suivant', 'chanson suivante', 'prochain titre', 'prochain morceau', 'prochaine chanson',
        'titre précédent', 'morceau précédent', 'chanson précédente', 'tout de suite après', 'on enchaîne avec',
    ];

    public const array DEFAULTS = [
        'enabled' => false,
        'min_size' => 20,
        'max_share' => 60,
        'min_gap_days' => 3,
        'max_uses' => 0,
        'max_size' => 200,
        'fallback' => true,
    ];

    public function __construct(
        private ?string $root = null
    ) {
    }

    // --- pure helpers ------------------------------------------------------------------------------

    /** @return string[] context variables used in a prompt (empty: can be timeless) */
    public static function contextVariables(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $text, $m);

        return array_values(array_unique(array_intersect(array_map('strtolower', $m[1]), self::CONTEXT_VARIABLES)));
    }

    /** First word or expression that ties the text to a moment, or null if the text is timeless. */
    public static function timeMarker(string $text): ?string
    {
        $lower = mb_strtolower(str_replace(['’', '‘'], '\'', $text));
        foreach (self::TIME_MARKERS as $marker) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($marker, '/') . '(?![\p{L}\p{N}])/u', $lower)) {
                return $marker;
            }
        }
        if (preg_match('/\b\d{1,2}\s*h(?:\s*\d{2})?\b|\b\d{1,2}:\d{2}\b|\b(?:19|20)\d{2}\b/u', $lower, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    /** Identifies the voice a clip was recorded with (Voice ID, model, language, tuning). */
    public static function voiceSignature(array $settings, array $host): string
    {
        $voice = $host['voice'] ?? [];
        ksort($voice);

        return substr(sha1(json_encode([
            $host['voice_id'] ?? '',
            $settings['elevenlabs']['model_id'] ?? '',
            $settings['elevenlabs']['language_code'] ?? '',
            $voice,
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    public static function config(array $host): array
    {
        return array_replace(self::DEFAULTS, is_array($host['library'] ?? null) ? $host['library'] : []);
    }

    /**
     * Probability of reusing a clip instead of generating a new one: 0 below the minimum size,
     * then growing with the library up to the maximum share (reached at 3 × the minimum size).
     */
    public static function reuseShare(array $config, int $size): float
    {
        $min = max(1, (int)$config['min_size']);
        if (empty($config['enabled']) || $size < $min) {
            return 0.0;
        }
        $progress = min(1.0, ($size - $min + 1) / (2 * $min + 1));

        return round((int)$config['max_share'] / 100 * $progress, 3);
    }

    /**
     * Entry to reuse: valid voice, not aired for min_gap_days, least recently used first.
     *
     * @param array<int, array> $entries
     */
    public static function choose(array $entries, string $signature, array $config, int $now, bool $ignoreGap = false): ?array
    {
        $gap = (int)$config['min_gap_days'] * 86400;
        $candidates = array_values(array_filter($entries, static function (array $e) use ($signature, $gap, $now, $ignoreGap): bool {
            if (($e['signature'] ?? '') !== $signature) {
                return false;
            }
            $last = isset($e['last_used_at']) ? (int)$e['last_used_at'] : 0;

            return $ignoreGap || $last === 0 || $now - $last >= $gap;
        }));
        if ($candidates === []) {
            return null;
        }
        shuffle($candidates);
        usort($candidates, static fn(array $a, array $b): int => (int)($a['last_used_at'] ?? 0) <=> (int)($b['last_used_at'] ?? 0));

        return $candidates[0];
    }

    // --- storage -----------------------------------------------------------------------------------

    public function root(): string
    {
        return $this->root ?? Plugin::dataDir();
    }

    public static function isValidId(string $id): bool
    {
        return (bool)preg_match('/^[a-z0-9]{6,32}$/', $id);
    }

    private function dir(int $stationId, string $hostId): string
    {
        if (!self::isValidId($hostId)) {
            throw new RuntimeException('Animateur invalide.');
        }
        $dir = $this->root() . '/' . $stationId . '/library/' . $hostId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Bibliothèque non accessible en écriture : ' . $dir);
        }

        return $dir;
    }

    public function audioPath(int $stationId, string $hostId, string $id): string
    {
        return $this->dir($stationId, $hostId) . '/' . $id . '.mp3';
    }

    /** @return array<int, array> newest first */
    public function entries(int $stationId, string $hostId): array
    {
        if (!self::isValidId($hostId) || !is_dir($this->root() . '/' . $stationId . '/library/' . $hostId)) {
            return [];
        }
        $files = glob($this->dir($stationId, $hostId) . '/*.json') ?: [];
        $entries = [];
        foreach ($files as $file) {
            try {
                $entry = Store::load($file);
            } catch (RuntimeException) {
                continue;
            }
            if ($entry !== [] && is_file(substr($file, 0, -5) . '.mp3')) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn(array $a, array $b): int => (int)$b['created_at'] <=> (int)$a['created_at']);

        return $entries;
    }

    /** @return string[] host ids that have a library folder */
    public function hosts(int $stationId): array
    {
        $dirs = glob($this->root() . '/' . $stationId . '/library/*', GLOB_ONLYDIR) ?: [];

        return array_values(array_filter(array_map('basename', $dirs), self::isValidId(...)));
    }

    /**
     * Adds a voice to the library (the mp3 is copied). Beyond max_size, the most aired entry
     * (the oldest one if equal) makes room.
     */
    public function add(int $stationId, string $hostId, string $voicePath, array $entry, int $maxSize): array
    {
        return $this->withLock($stationId, $hostId, function () use ($stationId, $hostId, $voicePath, $entry, $maxSize): array {
            $entries = $this->entries($stationId, $hostId);
            while (count($entries) >= max(1, $maxSize)) {
                usort($entries, static fn(array $a, array $b): int => [(int)$b['uses'], (int)$a['created_at']]
                    <=> [(int)$a['uses'], (int)$b['created_at']]);
                $this->deleteFiles($stationId, $hostId, (string)array_shift($entries)['id']);
            }

            $entry = array_merge([
                'id' => bin2hex(random_bytes(6)),
                'host_id' => $hostId,
                'text' => '',
                'signature' => '',
                'created_at' => time(),
                'uses' => 0,
                'last_used_at' => null,
                'voice_duration' => null,
                'characters' => 0,
                'prompt_name' => '',
                'source_job' => '',
            ], $entry);
            $dir = $this->dir($stationId, $hostId);
            $tmp = $dir . '/.av-' . $entry['id'] . '.mp3';
            if (!copy($voicePath, $tmp) || !chmod($tmp, 0644) || !rename($tmp, $dir . '/' . $entry['id'] . '.mp3')) {
                @unlink($tmp);
                throw new RuntimeException('Impossible d\'ajouter la voix à la bibliothèque.');
            }
            Store::save($dir . '/' . $entry['id'] . '.json', $entry);

            return $entry;
        });
    }

    /**
     * Picks an entry and records the use (under lock, so two generations never take the same one).
     * Entries that reach max_uses are retired after this use.
     */
    public function take(int $stationId, string $hostId, string $signature, array $config, bool $ignoreGap = false): ?array
    {
        return $this->withLock($stationId, $hostId, function () use ($stationId, $hostId, $signature, $config, $ignoreGap): ?array {
            $entry = self::choose($this->entries($stationId, $hostId), $signature, $config, time(), $ignoreGap);
            if ($entry === null) {
                return null;
            }
            $entry['uses'] = (int)$entry['uses'] + 1;
            $entry['last_used_at'] = time();
            $entry['retire'] = (int)$config['max_uses'] > 0 && $entry['uses'] >= (int)$config['max_uses'];
            Store::save($this->dir($stationId, $hostId) . '/' . $entry['id'] . '.json', $entry);

            return $entry;
        });
    }

    public function delete(int $stationId, string $hostId, string $id): bool
    {
        if (!self::isValidId($id)) {
            return false;
        }

        return $this->withLock($stationId, $hostId, fn(): bool => $this->deleteFiles($stationId, $hostId, $id));
    }

    /** Deletes the entries recorded with another voice than $signature. */
    public function deleteObsolete(int $stationId, string $hostId, string $signature): int
    {
        return $this->withLock($stationId, $hostId, function () use ($stationId, $hostId, $signature): int {
            $count = 0;
            foreach ($this->entries($stationId, $hostId) as $entry) {
                if (($entry['signature'] ?? '') !== $signature && $this->deleteFiles($stationId, $hostId, (string)$entry['id'])) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /** Deletes the library of a host that no longer exists. */
    public function deleteHost(int $stationId, string $hostId): void
    {
        if (!self::isValidId($hostId)) {
            return;
        }
        $dir = $this->root() . '/' . $stationId . '/library/' . $hostId;
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function deleteFiles(int $stationId, string $hostId, string $id): bool
    {
        $dir = $this->dir($stationId, $hostId);
        $found = is_file($dir . '/' . $id . '.json');
        @unlink($dir . '/' . $id . '.mp3');
        @unlink($dir . '/' . $id . '.json');

        return $found;
    }

    private function withLock(int $stationId, string $hostId, callable $callback): mixed
    {
        $lock = fopen($this->dir($stationId, $hostId) . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrouillage impossible de la bibliothèque.');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
