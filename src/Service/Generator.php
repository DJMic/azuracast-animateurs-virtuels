<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Flysystem\StationFilesystems;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Produces one intervention: DeepSeek writes the text, ElevenLabs speaks it, ffmpeg adds the bed.
 */
final class Generator
{
    /** A queued intervention not yet visible in the database (queue not saved yet) is retried for this long. */
    private const int QUEUE_ROW_GRACE = 120;

    /** An intervention whose air time is this far in the past is abandoned. */
    private const int TOO_LATE = 600;

    public function __construct(
        private readonly Store $store,
        private readonly Jobs $jobs,
        private readonly Library $library,
        private readonly DeepSeek $deepSeek,
        private readonly ElevenLabs $elevenLabs,
        private readonly Credits $credits,
        private readonly Mixer $mixer,
        private readonly StationFilesystems $filesystems,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Generates a pending job. Returns the job as it is afterwards, or null if another process
     * is already generating it.
     */
    public function generate(Station $station, string $jobId, bool $wait = false): ?array
    {
        $stationId = (int)$station->id;

        return $this->jobs->withLock($stationId, $jobId, function () use ($station, $stationId, $jobId): array {
            $job = $this->jobs->load($stationId, $jobId) ?? throw new RuntimeException('Intervention introuvable.');
            if ($job['status'] !== 'pending') {
                return $job;
            }

            $now = time();
            $onAir = in_array($job['kind'], ['between', 'fixed'], true);
            if ($onAir) {
                $row = $this->queueRow($station, (string)$job['path']);
                if ($row === null) {
                    if ($now - (int)strtotime((string)$job['created_at']) < self::QUEUE_ROW_GRACE) {
                        return $job; // the queue is not saved yet: retried later
                    }

                    return $this->finish($stationId, $job, 'skipped', 'Retirée de la file d\'attente d\'AzuraCast.');
                }
                $playAt = strtotime((string)$job['play_at']);
                if ($playAt !== false && $now - $playAt > self::TOO_LATE) {
                    return $this->finish($stationId, $job, 'skipped', 'Heure de diffusion dépassée.');
                }
            }

            if ($onAir && !empty($job['resolve_next'])) {
                $job = $this->resolveNextSong($station, $job, (int)$row['id']);
            }

            $job['status'] = 'running';
            $job['attempts'] = (int)$job['attempts'] + 1;
            $job['started_at'] = (new DateTimeImmutable('now', $station->getTimezoneObject()))->format(DATE_ATOM);
            $this->jobs->save($stationId, $job);

            try {
                $job = $this->produce($station, $job);
                $job = $this->finish($stationId, $job, 'done');
                if ($onAir) {
                    $this->updateQueueRow($station, (string)$job['path'], (float)$job['duration']);
                }
            } catch (Throwable $e) {
                $this->logger->error('Animateurs virtuels : ' . $e->getMessage(), ['station' => $stationId, 'job' => $jobId]);
                $job = $this->finish($stationId, $job, 'failed', Database::describe($e));
                if ($onAir) {
                    $this->removeQueueRow($station, (string)$job['path']);
                }
            }

            return $job;
        }, $wait);
    }

    private function produce(Station $station, array $job): array
    {
        $stationId = (int)$station->id;
        $settings = $this->store->read($station);
        $host = Settings::findHost($settings, (string)$job['host_id'])
            ?? throw new RuntimeException('Animateur introuvable (supprimé ?).');
        if ($host['voice_id'] === '') {
            throw new RuntimeException('Voice ID manquant pour « ' . $host['name'] . ' ».');
        }
        $job['host_name'] = $host['name'];
        $config = Library::config($host);
        $signature = Library::voiceSignature($settings, $host);

        // Daily limit reached: only a clip of the library can be aired.
        if (!empty($job['reuse_only'])) {
            $entry = $this->library->take($stationId, $host['id'], $signature, $config, true)
                ?? throw new RuntimeException('Limite quotidienne atteinte et bibliothèque vide.');

            return $this->fromLibrary($station, $settings, $host, $job, $entry, 'Limite quotidienne atteinte.');
        }

        // Reuse a clip of the library, more and more often as it grows.
        if ($job['kind'] === 'between' && !empty($config['enabled'])) {
            $size = count(array_filter(
                $this->library->entries($stationId, $host['id']),
                static fn(array $e): bool => ($e['signature'] ?? '') === $signature
            ));
            $share = Library::reuseShare($config, $size);
            $job['reuse_share'] = $share;
            if ($share > 0 && mt_rand() / mt_getrandmax() < $share) {
                $entry = $this->library->take($stationId, $host['id'], $signature, $config);
                if ($entry !== null) {
                    return $this->fromLibrary($station, $settings, $host, $job, $entry);
                }
            }
        }

        try {
            return $this->fresh($station, $settings, $host, $job, $signature, $config);
        } catch (Throwable $e) {
            // Service down, no credit…: a clip of the library rather than nothing.
            if (!empty($config['enabled']) && !empty($config['fallback']) && $job['kind'] !== 'test') {
                $entry = $this->library->take($stationId, $host['id'], $signature, $config, true);
                if ($entry !== null) {
                    $this->logger->warning('Animateurs virtuels : ' . $e->getMessage() . ' → clip de la bibliothèque.');

                    return $this->fromLibrary($station, $settings, $host, $job, $entry, Database::describe($e));
                }
            }
            throw $e;
        }
    }

    /** New text (DeepSeek) and new voice (ElevenLabs). Timeless results go to the library. */
    private function fresh(Station $station, array $settings, array $host, array $job, string $signature, array $config): array
    {
        $stationId = (int)$station->id;
        $account = Settings::account($settings, $host);
        if ($settings['deepseek']['api_key'] === '' || $account === null || $account['api_key'] === '') {
            throw new RuntimeException('Clé API DeepSeek ou compte ElevenLabs manquant.');
        }
        $job['account_id'] = $account['id'];
        $job['account_name'] = $account['name'];
        $remaining = $this->credits->remaining($stationId, $account['id']);
        if ($remaining === 0) {
            throw new RuntimeException('Crédits ElevenLabs épuisés sur le compte « ' . $account['name'] . ' ».');
        }
        $prompt = Planner::pickPrompt($host, (string)$job['kind'], (string)$job['prompt_id'])
            ?? throw new RuntimeException('Aucun prompt actif pour « ' . $host['name'] . ' ».');
        $job['prompt_id'] = $prompt['id'];
        $job['prompt_name'] = $prompt['name'];
        $job['reused'] = false;

        // 1. Text
        $variables = is_array($job['variables']) ? $job['variables'] : [];
        $recent = $this->jobs->recentTexts($stationId, $host['id'], (int)$settings['avoid_repeat']);
        if (!empty($prompt['timeless'])) {
            // Shows DeepSeek part of the library so that new clips bring something different.
            $known = array_column($this->library->entries($stationId, $host['id']), 'text');
            shuffle($known);
            $recent = array_values(array_unique(array_merge($recent, array_slice($known, 0, 8))));
        }
        $messages = PromptBuilder::messages($settings, $host, $prompt, $variables, $recent);
        $answer = $this->deepSeek->complete($settings['deepseek'], $messages);
        $text = PromptBuilder::clean($answer['text'], (string)$settings['elevenlabs']['model_id']);
        if (mb_strlen($text) < 3) {
            throw new RuntimeException('Le texte renvoyé par DeepSeek est vide après nettoyage.');
        }
        $job['text'] = $text;
        $job['tokens'] = $answer['tokens'];
        $this->jobs->save($stationId, $job);

        // 2. Voice
        $voicePath = $this->jobs->dir($stationId, 'tmp') . '/' . $job['id'] . '-voice.mp3';
        try {
            if ($remaining !== null && $remaining < mb_strlen($text)) {
                throw new RuntimeException(
                    'Crédits ElevenLabs insuffisants sur le compte « ' . $account['name'] . ' » ('
                    . $remaining . ' restants, ' . mb_strlen($text) . ' nécessaires).'
                );
            }
            try {
                $job['characters'] = $this->elevenLabs->speak(
                    Settings::voiceConfig($settings, $host),
                    $host['voice_id'],
                    $host['voice'],
                    $text,
                    $voicePath
                ) ?: mb_strlen($text);
            } finally {
                $this->credits->refresh($stationId, $account);
            }
            $job['voice_duration'] = round($this->mixer->duration($voicePath), 2);

            // 3. Timeless text: the voice joins the library (whatever the reuse setting).
            if (!empty($prompt['timeless'])) {
                $marker = Library::timeMarker($text);
                if ($marker !== null) {
                    $job['library_note'] = 'Non ajoutée à la bibliothèque : contient « ' . $marker . ' ».';
                } else {
                    try {
                        $entry = $this->library->add($stationId, $host['id'], $voicePath, [
                            'text' => $text,
                            'signature' => $signature,
                            'voice_duration' => $job['voice_duration'],
                            'characters' => $job['characters'],
                            'prompt_name' => $prompt['name'],
                            'source_job' => $job['id'],
                        ], (int)$config['max_size']);
                        $job['library_id'] = $entry['id'];
                        $job['library_note'] = 'Ajoutée à la bibliothèque.';
                    } catch (Throwable $e) {
                        $job['library_note'] = 'Non ajoutée à la bibliothèque : ' . $e->getMessage();
                    }
                }
            }

            // 4. Music bed and mix
            return $this->mixVoice($station, $settings, $host, $job, $voicePath);
        } finally {
            @unlink($voicePath);
        }
    }

    /** Reuses a recorded voice of the library with a new music bed: no API call. */
    private function fromLibrary(Station $station, array $settings, array $host, array $job, array $entry, string $reason = ''): array
    {
        $stationId = (int)$station->id;
        $job['reused'] = true;
        $job['library_id'] = $entry['id'];
        $job['text'] = (string)$entry['text'];
        $job['prompt_name'] = (string)$entry['prompt_name'];
        $job['characters'] = 0;
        $job['tokens'] = 0;
        $job['voice_duration'] = $entry['voice_duration'];
        $job['library_note'] = 'Réutilisée depuis la bibliothèque (diffusion n° ' . (int)$entry['uses'] . ')'
            . ($reason !== '' ? ', à la place d\'une nouvelle intervention : ' . $reason : '.');

        $job = $this->mixVoice($station, $settings, $host, $job, $this->library->audioPath($stationId, $host['id'], (string)$entry['id']));
        if (!empty($entry['retire'])) {
            $this->library->delete($stationId, $host['id'], (string)$entry['id']);
            $job['library_note'] .= ' Nombre maximal de diffusions atteint : retirée de la bibliothèque.';
        }

        return $job;
    }

    /** Mixes a voice over a music bed into the job's clip. */
    private function mixVoice(Station $station, array $settings, array $host, array $job, string $voicePath): array
    {
        $mixPath = $this->jobs->dir((int)$station->id, 'tmp') . '/' . $job['id'] . '-mix.mp3';
        try {
            // No title/artist tags in the file: Liquidsoap gets them only through the annotations,
            // so that "hide metadata" really hides them.
            $playlistId = !empty($settings['bed']['enabled'])
                ? (($host['bed_playlist_id'] ?? null) ?: ($settings['bed']['playlist_id'] ?? null))
                : null;
            $result = $this->mixWithBed($station, $settings, $playlistId ? (int)$playlistId : null, $voicePath, $mixPath, []);
            $job['bed'] = $result['bed'];

            if (!@chmod($mixPath, 0644) || !rename($mixPath, (string)$job['path'])) {
                throw new RuntimeException('Impossible d\'enregistrer le clip ' . $job['path']);
            }
            $job['duration'] = round($result['duration'], 2);
        } finally {
            @unlink($mixPath);
        }

        return $job;
    }

    /**
     * Mixes a voice over a random track of a playlist (or alone if $playlistId is null),
     * with the station's intervention mixing settings.
     *
     * @return array{duration: float, bed: ?array{title: string, artist: string, path: string}}
     */
    public function mixWithBed(Station $station, array $settings, ?int $playlistId, string $voicePath, string $outPath, array $metadata): array
    {
        $bed = $playlistId !== null ? $this->pickBedFromPlaylist($station, $playlistId) : null;

        return $this->mixOver($station, $settings['bed'], $settings['output'], $bed, $voicePath, $outPath, $metadata);
    }

    /**
     * Mixes a voice over a media file of the station (or alone if $bed is null).
     *
     * @param array{path: string, title?: string, artist?: string, length?: float}|null $bed
     *
     * @return array{duration: float, bed: ?array{title: string, artist: string, path: string}}
     */
    public function mixOver(Station $station, array $bedSettings, array $output, ?array $bed, string $voicePath, string $outPath, array $metadata): array
    {
        if ($bed === null) {
            return [
                'duration' => $this->mixer->mix($voicePath, null, null, $bedSettings, $output, $outPath, $metadata),
                'bed' => null,
            ];
        }
        $fs = $this->filesystems->getMediaFilesystem($station);
        if (!$fs->fileExists($bed['path'])) {
            throw new RuntimeException('Fond musical introuvable dans les médias : ' . $bed['path']);
        }
        $duration = $fs->withLocalFile(
            $bed['path'],
            fn(string $local): float => $this->mixer->mix(
                $voicePath,
                $local,
                !empty($bed['length']) ? (float)$bed['length'] : null,
                $bedSettings,
                $output,
                $outPath,
                $metadata
            )
        );

        return [
            'duration' => $duration,
            'bed' => ['title' => (string)($bed['title'] ?? ''), 'artist' => (string)($bed['artist'] ?? ''), 'path' => $bed['path']],
        ];
    }

    /**
     * Random track of a playlist of the station.
     *
     * @return array{path: string, title: string, artist: string, length: float}
     */
    public function pickBedFromPlaylist(Station $station, int $playlistId): array
    {
        $this->database->prepare();
        $playlist = $this->em->find(StationPlaylist::class, $playlistId);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            throw new RuntimeException('La playlist du fond musical n\'existe plus.');
        }
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sm.path, sm.title, sm.artist, sm.length
                FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media sm
                WHERE spm.playlist = :playlist
            DQL
        )->setParameter('playlist', $playlist)->getArrayResult();

        if ($rows === []) {
            throw new RuntimeException('La playlist du fond musical « ' . $playlist->name . ' » est vide.');
        }
        $row = $rows[array_rand($rows)];

        return [
            'path' => (string)$row['path'],
            'title' => (string)($row['title'] ?? ''),
            'artist' => (string)($row['artist'] ?? ''),
            'length' => (float)($row['length'] ?? 0),
        ];
    }

    private function finish(int $stationId, array $job, string $status, string $error = ''): array
    {
        $job['status'] = $status;
        $job['error'] = $error;
        $job['finished_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $this->jobs->save($stationId, $job);

        return $job;
    }

    /**
     * The intervention was placed before a jingle: the song after that jingle is found in the
     * queue (waiting a little for AzuraCast to choose it, as long as there is time).
     */
    private function resolveNextSong(Station $station, array $job, int $rowId): array
    {
        $job['resolve_next'] = false;
        $settings = $this->store->read($station);
        $host = Settings::findHost($settings, (string)$job['host_id']);
        $prompt = $host !== null ? Planner::pickPrompt($host, (string)$job['kind'], (string)$job['prompt_id']) : null;
        $text = (string)($prompt['text'] ?? '') . (string)($host['personality'] ?? '');
        if (!preg_match('/\{(titre|artiste)_suivant\}/i', $text)) {
            return $job; // the prompt does not talk about the next song
        }

        $askAt = strtotime((string)($job['ask_at'] ?? '')) ?: time();
        $deadline = min(time() + 120, $askAt - 35);
        do {
            $this->database->prepare();
            $next = $this->em->createQuery(
                <<<'DQL'
                    SELECT sq.title, sq.artist FROM App\Entity\StationQueue sq
                    LEFT JOIN sq.playlist p
                    WHERE sq.station = :station AND sq.id > :id AND sq.media IS NOT NULL
                        AND sq.autodj_custom_uri IS NULL AND (p.id IS NULL OR p.is_jingle = 0)
                    ORDER BY sq.id ASC
                DQL
            )->setParameter('station', $station)->setParameter('id', $rowId)->setMaxResults(1)->getOneOrNullResult();
            if (is_array($next)) {
                $job['variables']['titre_suivant'] = trim((string)$next['title']);
                $job['variables']['artiste_suivant'] = trim((string)$next['artist']);

                return $job;
            }
            if (time() + 5 >= $deadline) {
                break;
            }
            sleep(5);
        } while (true);

        $job['library_note'] = trim(($job['library_note'] ?? '') . ' Morceau suivant pas encore choisi par AzuraCast : non annoncé.');

        return $job;
    }

    /** @return array{id: int, sent: bool}|null */
    private function queueRow(Station $station, string $uri): ?array
    {
        $this->database->prepare();
        $row = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id, sq.sent_to_autodj FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.autodj_custom_uri = :uri
            DQL
        )->setParameter('station', $station)->setParameter('uri', $uri)->setMaxResults(1)->getOneOrNullResult();

        return is_array($row) ? ['id' => (int)$row['id'], 'sent' => (bool)$row['sent_to_autodj']] : null;
    }

    private function updateQueueRow(Station $station, string $uri, float $duration): void
    {
        try {
            $this->database->prepare();
            $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationQueue sq SET sq.duration = :duration
                    WHERE sq.station = :station AND sq.autodj_custom_uri = :uri AND sq.sent_to_autodj = 0
                DQL
            )->setParameter('duration', $duration)->setParameter('station', $station)->setParameter('uri', $uri)->execute();
        } catch (Throwable $e) {
            $this->logger->warning('Animateurs virtuels : durée non mise à jour dans la file : ' . $e->getMessage());
        }
    }

    private function removeQueueRow(Station $station, string $uri): void
    {
        try {
            $this->database->prepare();
            $this->em->createQuery(
                <<<'DQL'
                    DELETE FROM App\Entity\StationQueue sq
                    WHERE sq.station = :station AND sq.autodj_custom_uri = :uri AND sq.sent_to_autodj = 0
                DQL
            )->setParameter('station', $station)->setParameter('uri', $uri)->execute();
        } catch (Throwable $e) {
            $this->logger->warning('Animateurs virtuels : intervention non retirée de la file : ' . $e->getMessage());
        }
    }
}
