<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use App\Entity\Station;
use App\Flysystem\StationFilesystems;
use RuntimeException;
use Throwable;

/**
 * Speaks a text with the voice of a host or a chronicle, whatever the service:
 * ElevenLabs (credits checked and read again) or Mistral Voxtral (characters counted per month).
 *
 * The voice is given resolved, as returned by Settings::speech() or ChronicleSettings::voice().
 */
final class Speech
{
    /** Voxtral requests are cut at sentence ends beyond this many characters. */
    private const int VOXTRAL_PART = 1800;

    public function __construct(
        private readonly ElevenLabs $elevenLabs,
        private readonly Voxtral $voxtral,
        private readonly Credits $credits,
        private readonly Mixer $mixer,
        private readonly Jobs $jobs,
        private readonly StationFilesystems $filesystems
    ) {
    }

    /**
     * Refuses to start when the credits are known to be insufficient ($needed = 0: exhausted).
     * Nothing to check with Voxtral (billed by usage).
     */
    public function assertCredits(int $stationId, array $speech, int $needed = 0): void
    {
        if ($speech['provider'] !== 'elevenlabs') {
            return;
        }
        $account = $speech['account'];
        $remaining = $this->credits->remaining($stationId, $account['id']);
        if ($remaining === 0) {
            throw new RuntimeException('Crédits ElevenLabs épuisés sur le compte « ' . $account['name'] . ' ».');
        }
        if ($remaining !== null && $needed > 0 && $remaining < $needed) {
            throw new RuntimeException('Crédits ElevenLabs insuffisants sur le compte « ' . $account['name'] . ' » ('
                . $remaining . ' restants, ' . $needed . ' nécessaires).');
        }
    }

    /**
     * Writes the speech as mp3 to $outPath.
     *
     * @return int characters billed
     */
    public function speak(Station $station, array $speech, string $text, string $outPath): int
    {
        $stationId = (int)$station->id;
        if ($speech['provider'] === 'voxtral') {
            $characters = $this->voxtral($station, $speech, $text, $outPath);
            $this->countUsage($stationId, $characters);

            return $characters;
        }

        try {
            return $this->elevenLabs->speak($speech['config'], $speech['voice_id'], $speech['voice'], $text, $outPath)
                ?: mb_strlen($text);
        } finally {
            $this->credits->refresh($stationId, $speech['account']);
        }
    }

    /** Characters sent to Voxtral this month and the previous month. */
    public function voxtralUsage(int $stationId, ?int $now = null): array
    {
        $usage = $this->loadUsage($stationId);
        $now ??= time();

        return [
            'month' => (int)($usage[date('Y-m', $now)] ?? 0),
            'previous' => (int)($usage[date('Y-m', strtotime('first day of last month', $now))] ?? 0),
        ];
    }

    private function voxtral(Station $station, array $speech, string $text, string $outPath): int
    {
        $voice = $speech['voxtral'];
        $refAudio = $voice['mode'] === 'sample' ? $this->sample($station, $voice) : null;
        $parts = VoxtralVoice::split($text, self::VOXTRAL_PART);
        if (count($parts) === 1) {
            return $this->voxtral->speak($speech['api_key'], $speech['model'], $voice, $refAudio, $parts[0], $outPath);
        }

        // Long text (chronicle): one request per part, then joined.
        $files = [];
        $characters = 0;
        try {
            foreach ($parts as $i => $part) {
                $file = $outPath . '.part' . $i . '.mp3';
                $files[] = $file;
                $characters += $this->voxtral->speak($speech['api_key'], $speech['model'], $voice, $refAudio, $part, $file);
            }
            $this->mixer->concat($files, $outPath, 192);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        return $characters;
    }

    /**
     * Reference recording (base64) for a voice cloned from a media file: an extract in mono WAV,
     * made once and kept in <data>/<station id>/voices/.
     */
    private function sample(Station $station, array $voice): string
    {
        $stationId = (int)$station->id;
        $fs = $this->filesystems->getMediaFilesystem($station);
        $path = (string)$voice['sample_media'];
        if (!$fs->fileExists($path)) {
            throw new RuntimeException('Voxtral : fichier de voix de référence introuvable dans les médias : ' . $path);
        }
        try {
            $modified = $fs->lastModified($path);
        } catch (Throwable) {
            $modified = 0;
        }
        $dir = $this->jobs->dir($stationId, 'voices');
        $file = $dir . '/' . substr(sha1(json_encode(
            [$path, $modified, $voice['sample_start'], $voice['sample_seconds']],
            JSON_THROW_ON_ERROR
        )), 0, 20) . '.wav';

        if (!is_file($file)) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.wav';
            try {
                $fs->withLocalFile($path, function (string $local) use ($voice, $tmp): void {
                    $this->mixer->extract($local, $tmp, (float)$voice['sample_start'], (int)$voice['sample_seconds']);
                });
                if (!rename($tmp, $file)) {
                    throw new RuntimeException('Voxtral : écriture de la voix de référence impossible.');
                }
            } finally {
                @unlink($tmp);
            }
            // Extracts of older settings are no longer needed.
            foreach (glob($dir . '/*.wav') ?: [] as $old) {
                if ($old !== $file && filemtime($old) < time() - 7 * 86400) {
                    @unlink($old);
                }
            }
        }
        @touch($file);

        return base64_encode((string)file_get_contents($file));
    }

    private function countUsage(int $stationId, int $characters): void
    {
        try {
            $file = $this->jobs->dir($stationId) . '/voxtral-usage.json';
            $usage = Store::load($file);
            $month = date('Y-m');
            $usage[$month] = (int)($usage[$month] ?? 0) + $characters;
            krsort($usage);
            Store::save($file, array_slice($usage, 0, 24, true));
        } catch (Throwable) {
            // Display only.
        }
    }

    private function loadUsage(int $stationId): array
    {
        try {
            return Store::load($this->jobs->dir($stationId) . '/voxtral-usage.json');
        } catch (Throwable) {
            return [];
        }
    }
}
