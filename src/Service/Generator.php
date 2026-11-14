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
        private readonly DeepSeek $deepSeek,
        private readonly ElevenLabs $elevenLabs,
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
        if ($settings['deepseek']['api_key'] === '' || $settings['elevenlabs']['api_key'] === '') {
            throw new RuntimeException('Clé API DeepSeek ou ElevenLabs manquante.');
        }
        $host = Settings::findHost($settings, (string)$job['host_id'])
            ?? throw new RuntimeException('Animateur introuvable (supprimé ?).');
        if ($host['voice_id'] === '') {
            throw new RuntimeException('Voice ID manquant pour « ' . $host['name'] . ' ».');
        }
        $prompt = Planner::pickPrompt($host, (string)$job['kind'], (string)$job['prompt_id'])
            ?? throw new RuntimeException('Aucun prompt actif pour « ' . $host['name'] . ' ».');
        $job['prompt_id'] = $prompt['id'];
        $job['prompt_name'] = $prompt['name'];
        $job['host_name'] = $host['name'];

        // 1. Text
        $variables = is_array($job['variables']) ? $job['variables'] : [];
        $recent = $this->jobs->recentTexts($stationId, $host['id'], (int)$settings['avoid_repeat']);
        $messages = PromptBuilder::messages($settings, $host, $prompt, $variables, $recent);
        $answer = $this->deepSeek->complete($settings['deepseek'], $messages);
        $text = PromptBuilder::clean($answer['text'], $settings['elevenlabs']['model_id'] === 'eleven_v3');
        if (mb_strlen($text) < 3) {
            throw new RuntimeException('Le texte renvoyé par DeepSeek est vide après nettoyage.');
        }
        $job['text'] = $text;
        $job['tokens'] = $answer['tokens'];
        $this->jobs->save($stationId, $job);

        // 2. Voice
        $tmpDir = $this->jobs->dir($stationId, 'tmp');
        $voicePath = $tmpDir . '/' . $job['id'] . '-voice.mp3';
        $mixPath = $tmpDir . '/' . $job['id'] . '-mix.mp3';
        try {
            $job['characters'] = $this->elevenLabs->speak(
                $settings['elevenlabs'],
                $host['voice_id'],
                $host['voice'],
                $text,
                $voicePath
            ) ?: mb_strlen($text);
            $job['voice_duration'] = round($this->mixer->duration($voicePath), 2);

            // 3. Music bed and mix
            $metadata = [
                'title' => PromptBuilder::fill($settings['output']['meta_title'], $variables),
                'artist' => PromptBuilder::fill($settings['output']['meta_artist'], $variables),
            ];
            $metadata = array_map(static fn(string $v): string => str_replace(PromptBuilder::MISSING, '', $v), $metadata);
            $bed = $this->pickBed($station, $settings, $host);
            if ($bed === null) {
                $job['bed'] = null;
                $duration = $this->mixer->mix($voicePath, null, null, $settings['bed'], $settings['output'], $mixPath, $metadata);
            } else {
                $job['bed'] = ['title' => $bed['title'], 'artist' => $bed['artist'], 'path' => $bed['path']];
                $fs = $this->filesystems->getMediaFilesystem($station);
                $duration = $fs->withLocalFile(
                    $bed['path'],
                    fn(string $local): float => $this->mixer->mix(
                        $voicePath,
                        $local,
                        $bed['length'] > 0 ? (float)$bed['length'] : null,
                        $settings['bed'],
                        $settings['output'],
                        $mixPath,
                        $metadata
                    )
                );
            }

            if (!@chmod($mixPath, 0644) || !rename($mixPath, (string)$job['path'])) {
                throw new RuntimeException('Impossible d\'enregistrer le clip ' . $job['path']);
            }
            $job['duration'] = round($duration, 2);
        } finally {
            @unlink($voicePath);
            @unlink($mixPath);
        }

        return $job;
    }

    /**
     * Random track of the bed playlist (the host's own playlist, else the station's one).
     *
     * @return array{path: string, title: string, artist: string, length: float}|null
     */
    public function pickBed(Station $station, array $settings, array $host): ?array
    {
        if (empty($settings['bed']['enabled'])) {
            return null;
        }
        $playlistId = ($host['bed_playlist_id'] ?? null) ?: ($settings['bed']['playlist_id'] ?? null);
        if (!$playlistId) {
            return null;
        }
        $this->database->prepare();
        $playlist = $this->em->find(StationPlaylist::class, (int)$playlistId);
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
