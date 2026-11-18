<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use App\Entity\Station;
use App\Flysystem\StationFilesystems;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Produces one chronicle: sources → DeepSeek → ElevenLabs → music bed (+ opening/closing jingles)
 * → media library → playlist (replacing the previous version) → cleanup of old versions.
 *
 * If no source can be read, or if DeepSeek finds nothing usable in them, nothing is published:
 * the previous version stays in the playlist.
 */
final class ChronicleGenerator
{
    public function __construct(
        private readonly Store $store,
        private readonly Jobs $jobs,
        private readonly Sources $sources,
        private readonly DeepSeek $deepSeek,
        private readonly ElevenLabs $elevenLabs,
        private readonly Credits $credits,
        private readonly Generator $generator,
        private readonly Mixer $mixer,
        private readonly MediaLibrary $media,
        private readonly StationFilesystems $filesystems,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Creates a pending run for a chronicle (manual or scheduled). */
    public function queue(Station $station, array $chronicle, string $trigger, ?string $slot = null): array
    {
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());

        return $this->jobs->create((int)$station->id, $now, [
            'kind' => 'chronicle',
            'chronicle_id' => $chronicle['id'],
            'chronicle_name' => $chronicle['name'],
            'host_name' => $chronicle['name'],
            'prompt_name' => $trigger === 'manual' ? 'Génération manuelle' : 'Planifiée (' . substr((string)$slot, 11) . ')',
            'trigger' => $trigger,
            'slot' => $slot,
        ]);
    }

    /**
     * Runs a pending chronicle job. Returns the job afterwards, or null if it is already running.
     */
    public function run(Station $station, string $jobId): ?array
    {
        $stationId = (int)$station->id;

        return $this->jobs->withLock($stationId, $jobId, function () use ($station, $stationId, $jobId): array {
            $job = $this->jobs->load($stationId, $jobId) ?? throw new RuntimeException('Chronique introuvable.');
            if ($job['status'] !== 'pending') {
                return $job;
            }
            $job['status'] = 'running';
            $job['attempts'] = (int)$job['attempts'] + 1;
            $job['started_at'] = (new DateTimeImmutable('now', $station->getTimezoneObject()))->format(DATE_ATOM);
            $this->jobs->save($stationId, $job);

            try {
                $job = $this->produce($station, $job);
                $job['status'] = 'done';
                $job['error'] = '';
            } catch (Throwable $e) {
                $this->logger->error('Animateurs virtuels (chronique) : ' . $e->getMessage(), ['station' => $stationId, 'job' => $jobId]);
                $job['status'] = 'failed';
                $job['error'] = Database::describe($e);
            }
            $job['finished_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
            $this->jobs->save($stationId, $job);

            return $job;
        });
    }

    private function produce(Station $station, array $job): array
    {
        $stationId = (int)$station->id;
        $settings = $this->store->read($station);
        $chronicle = null;
        foreach ($settings['chronicles'] as $item) {
            if ($item['id'] === $job['chronicle_id']) {
                $chronicle = $item;
            }
        }
        if ($chronicle === null) {
            throw new RuntimeException('Cette chronique a été supprimée.');
        }
        $voice = ChronicleSettings::voice($settings, $chronicle)
            ?? throw new RuntimeException('Voix de la chronique introuvable (animateur, compte ElevenLabs ou Voice ID).');
        if ($settings['deepseek']['api_key'] === '') {
            throw new RuntimeException('Clé API DeepSeek manquante.');
        }
        $job['account_id'] = $voice['account']['id'];
        $job['account_name'] = $voice['account']['name'];
        $remaining = $this->credits->remaining($stationId, $voice['account']['id']);
        if ($remaining === 0) {
            throw new RuntimeException('Crédits ElevenLabs épuisés sur le compte « ' . $voice['account']['name'] . ' ».');
        }

        // 1. Sources
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $read = $this->sources->readAll($chronicle, $now);
        $job['sources'] = array_map(static fn(array $s): array => [
            'label' => $s['label'],
            'url' => $s['url'],
            'ok' => $s['ok'],
            'kind' => $s['kind'],
            'items' => $s['items'],
            'chars' => mb_strlen($s['text']),
            'error' => $s['error'],
        ], $read);
        if (!array_filter($read, static fn(array $s): bool => $s['ok'])) {
            throw new RuntimeException('Aucune source n\'a pu être lue : ' . implode(' ; ', array_map(
                static fn(array $s): string => $s['label'] . ' (' . $s['error'] . ')',
                $read
            )) . '. La version précédente reste en place.');
        }
        $this->jobs->save($stationId, $job);

        // 2. Text
        $variables = PromptBuilder::variables(
            ['name' => $voice['name'], 'target_seconds' => $chronicle['target_seconds']],
            $station->name,
            $settings['language'],
            $now
        ) + ['chronique' => $chronicle['name']];
        $messages = PromptBuilder::chronicleMessages($settings, $chronicle, $voice, $variables, $read);
        $config = $settings['deepseek'];
        $config['max_tokens'] = max((int)$config['max_tokens'], (int)ceil((int)$variables['mots'] * 2.5) + 300);
        $answer = $this->deepSeek->complete($config, $messages);
        if (str_contains($answer['text'], PromptBuilder::NO_CONTENT)) {
            throw new RuntimeException('Les sources ne contiennent aucune information utile pour cette chronique. '
                . 'La version précédente reste en place.');
        }
        $text = PromptBuilder::clean($answer['text'], (string)$settings['elevenlabs']['model_id'], 9000);
        if (mb_strlen($text) < 20) {
            throw new RuntimeException('Le texte renvoyé par DeepSeek est trop court.');
        }
        $job['text'] = $text;
        $job['tokens'] = $answer['tokens'];
        $this->jobs->save($stationId, $job);

        // 3. Voice
        if ($remaining !== null && $remaining < mb_strlen($text)) {
            throw new RuntimeException('Crédits ElevenLabs insuffisants sur le compte « ' . $voice['account']['name']
                . ' » (' . $remaining . ' restants, ' . mb_strlen($text) . ' nécessaires).');
        }
        $tmp = $this->jobs->dir($stationId, 'tmp') . '/' . $job['id'];
        $files = [$tmp . '-voice.mp3', $tmp . '-mix.mp3', $tmp . '-final.mp3'];
        try {
            try {
                $job['characters'] = $this->elevenLabs->speak(
                    [
                        'api_key' => $voice['account']['api_key'],
                        'model_id' => $settings['elevenlabs']['model_id'],
                        'language_code' => $settings['elevenlabs']['language_code'],
                    ],
                    $voice['voice_id'],
                    $voice['voice'],
                    $text,
                    $files[0]
                ) ?: mb_strlen($text);
            } finally {
                $this->credits->refresh($stationId, $voice['account']);
            }
            $job['voice_duration'] = round($this->mixer->duration($files[0]), 2);

            // 4. Music bed, then opening/closing jingles
            $metadata = array_map(
                static fn(string $v): string => trim(str_replace(PromptBuilder::MISSING, '', $v)),
                [
                    'title' => PromptBuilder::fill((string)$chronicle['meta_title'], $variables),
                    'artist' => PromptBuilder::fill((string)$chronicle['meta_artist'], $variables),
                    'album' => $chronicle['name'],
                ]
            );
            [$bedSettings, $output] = ChronicleSettings::mixSettings($chronicle);
            $hasJingles = $chronicle['intro_media'] !== '' || $chronicle['outro_media'] !== '';
            $mix = $this->generator->mixOver(
                $station,
                $bedSettings,
                $output,
                $chronicle['bed_media'] !== '' ? ['path' => $chronicle['bed_media'], 'title' => basename($chronicle['bed_media'])] : null,
                $files[0],
                $hasJingles ? $files[1] : $files[2],
                $hasJingles ? [] : $metadata
            );
            $job['bed'] = $mix['bed'];
            $duration = $mix['duration'];
            if ($hasJingles) {
                $duration = $this->addJingles($station, $chronicle, $files[1], $files[2], (int)$output['bitrate'], $metadata);
            }
            $job['duration'] = round($duration, 2);

            // 5. Media library and playlist
            $published = $this->media->publish($station, $chronicle, $files[2], ChronicleSettings::fileName($chronicle['name'], $now));
            $job['media_path'] = $published['path'];
            $job['media_id'] = $published['media_id'];
            $playlistName = $this->media->replaceInPlaylist($station, $chronicle, $published['media_id']);
            $job['library_note'] = 'Enregistrée dans les médias : ' . $published['path']
                . ($playlistName !== null ? ' — placée dans la playlist « ' . $playlistName . ' » (version précédente retirée).' : '.');
            $deleted = $this->media->cleanup($station, $chronicle, (int)$chronicle['retention_days']);
            if ($deleted > 0) {
                $job['library_note'] .= ' ' . $deleted . ' ancienne(s) version(s) supprimée(s).';
            }

            // A copy is kept for listening in the plugin's history.
            @copy($files[2], (string)$job['path']);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        return $job;
    }

    private function addJingles(Station $station, array $chronicle, string $main, string $out, int $bitrate, array $metadata): float
    {
        $fs = $this->filesystems->getMediaFilesystem($station);
        $build = function (array $paths) use ($out, $bitrate, $metadata): float {
            return $this->mixer->concat($paths, $out, $bitrate, $metadata);
        };
        $withOutro = function (array $paths) use ($fs, $chronicle, $build): float {
            if ($chronicle['outro_media'] === '') {
                return $build($paths);
            }
            if (!$fs->fileExists($chronicle['outro_media'])) {
                throw new RuntimeException('Jingle de fermeture introuvable dans les médias : ' . $chronicle['outro_media']);
            }

            return $fs->withLocalFile($chronicle['outro_media'], static fn(string $local): float => $build([...$paths, $local]));
        };
        if ($chronicle['intro_media'] === '') {
            return $withOutro([$main]);
        }
        if (!$fs->fileExists($chronicle['intro_media'])) {
            throw new RuntimeException('Jingle d\'ouverture introuvable dans les médias : ' . $chronicle['intro_media']);
        }

        return $fs->withLocalFile($chronicle['intro_media'], static fn(string $local): float => $withOutro([$local, $main]));
    }
}
