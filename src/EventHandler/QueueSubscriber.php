<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\EventHandler;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\BuildQueue;
use DateTimeImmutable;
use DateTimeInterface;
use Plugin\AzuracastAnimateursVirtuels\Service\ClipAnnotations;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Launcher;
use Plugin\AzuracastAnimateursVirtuels\Service\Library;
use Plugin\AzuracastAnimateursVirtuels\Service\Planner;
use Plugin\AzuracastAnimateursVirtuels\Service\Plugin;
use Plugin\AzuracastAnimateursVirtuels\Service\PromptBuilder;
use Plugin\AzuracastAnimateursVirtuels\Service\Settings;
use Plugin\AzuracastAnimateursVirtuels\Service\Store;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Places the interventions in AzuraCast's AutoDJ queue.
 *
 * BuildQueue: when AzuraCast adds the next song to the queue, the plugin first lets AzuraCast
 * choose that song (nested dispatch), then decides whether its host speaks before it. If so,
 * an "intervention" row pointing to the future clip is put in front of the song. The clip is
 * generated in the background during the minutes before it airs (the queue is built ahead).
 *
 * AnnotateNextSong: when Liquidsoap asks for that row, the plugin sends the title/artist and
 * real duration of the clip (or half a second of silence if the clip is missing).
 *
 * Stations where the plugin is disabled are left untouched.
 */
final class QueueSubscriber implements EventSubscriberInterface
{
    /** Extra seconds counted in the queue for a clip not generated yet (intro + outro are added). */
    private const int ESTIMATE_MARGIN = 2;

    /** How long Liquidsoap's request may wait for a clip still being generated (its HTTP timeout is 10 s). */
    private const int WAIT_FOR_CLIP = 8;

    /**
     * Minimum time between the decision and the moment Liquidsoap asks for the clip, to have time
     * to generate it. Below that, the intervention waits for a later place in the queue.
     */
    public const int MIN_LEAD = 45;

    private static bool $nested = false;

    public function __construct(
        private readonly Store $store,
        private readonly Jobs $jobs,
        private readonly Library $library,
        private readonly Launcher $launcher,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before AzuraCast's own listeners (requests: 5, playlists: 0).
            BuildQueue::class => ['onBuildQueue', 100],
            // Right after AzuraCast set the file path (20), before the other annotations.
            AnnotateNextSong::class => ['onAnnotate', 18],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if (self::$nested || $event->isInterrupting()) {
            return;
        }
        $station = $event->getStation();

        try {
            $settings = $this->store->read($station);
            if (!Settings::isReady($settings) || !$this->jobs->isWritable()) {
                return;
            }
            $tz = $station->getTimezoneObject();
            $playAt = DateTimeImmutable::createFromInterface($event->getExpectedPlayTime())->setTimezone($tz);
            $onAir = Planner::onAir($settings, $playAt);
            if ($onAir === null) {
                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('Animateurs virtuels (file d\'attente) : ' . $e->getMessage(), ['station' => $station->id]);

            return;
        }

        // Let AzuraCast (and other plugins) choose the next song.
        $inner = new BuildQueue(
            $station,
            $event->getExpectedCueTime(),
            $event->getExpectedPlayTime(),
            $event->getLastPlayedSongId(),
            false
        );
        self::$nested = true;
        try {
            $this->dispatcher->dispatch($inner);
        } finally {
            self::$nested = false;
        }

        $songs = $inner->getNextSongs();
        if ($songs === []) {
            $event->stopPropagation(); // nothing to play: AzuraCast already said so

            return;
        }

        $rows = $songs;
        try {
            $intervention = $this->plan($station, $settings, $onAir, $playAt, $songs[0]);
            if ($intervention !== null) {
                $rows = [$intervention, ...$songs];
            }
        } catch (Throwable $e) {
            $this->logger->error('Animateurs virtuels (file d\'attente) : ' . $e->getMessage(), ['station' => $station->id]);
        }

        $event->setNextSongs($rows);
    }

    private function plan(Station $station, array $settings, array $onAir, DateTimeImmutable $playAt, StationQueue $next): ?StationQueue
    {
        $stationId = (int)$station->id;
        $isMusic = $next->media !== null && !($next->playlist?->is_jingle ?? false);

        // The clip goes right after the last item of the queue. Liquidsoap asks for it as soon as
        // that item starts playing: the clip must be generated before then.
        $previous = $this->lastRow($station);
        $prevIsMusic = $previous !== null && $previous['is_music'];
        $askAt = $previous['starts_at'] ?? time();
        $canPlace = $askAt - time() >= self::MIN_LEAD;

        $decision = null;
        $this->jobs->updateState(
            $stationId,
            function (array $state) use ($onAir, $playAt, $isMusic, $prevIsMusic, $canPlace, &$decision): array {
                [$decision, $state] = Planner::decide($state, $onAir, $playAt, $isMusic, $prevIsMusic, $canPlace);

                return $state;
            }
        );
        if ($decision === null) {
            return null;
        }

        $host = $onAir['host'];
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $reuseOnly = false;
        if (!empty($settings['limits']['daily_enabled'])
            && $this->jobs->countToday($stationId, $now) >= (int)$settings['limits']['daily_max']) {
            // Limit reached: the library can still provide a clip (no API call), if allowed.
            $config = Library::config($host);
            $signature = Library::voiceSignature($settings, $host);
            $available = !empty($config['enabled']) && !empty($config['fallback']) && array_filter(
                $this->library->entries($stationId, $host['id']),
                static fn(array $e): bool => ($e['signature'] ?? '') === $signature
            ) !== [];
            if (!$available) {
                $this->logger->notice('Animateurs virtuels : limite quotidienne atteinte, intervention ignorée.', ['station' => $stationId]);

                return null;
            }
            $reuseOnly = true;
        }
        $prompt = Planner::pickPrompt($host, $decision['kind']);
        if ($prompt === null) {
            return null;
        }

        // Songs around the intervention, jingles skipped. If the next item is a jingle, the next
        // song is not chosen yet: it is looked up in the queue when the clip is generated.
        $previousSong = $prevIsMusic ? $previous : ($this->lastMusicRow($station) ?? []);
        $variables = PromptBuilder::variables(
            $host,
            $station->name,
            $settings['language'],
            $playAt,
            ['title' => $previousSong['title'] ?? null, 'artist' => $previousSong['artist'] ?? null],
            $isMusic ? ['title' => $next->title, 'artist' => $next->artist] : []
        );

        $job = $this->jobs->create($stationId, $now, [
            'kind' => $decision['kind'],
            'fixed_time' => $decision['time'],
            'play_at' => $playAt->format(DATE_ATOM),
            'host_id' => $host['id'],
            'host_name' => $host['name'],
            'prompt_id' => $prompt['id'],
            'prompt_name' => $prompt['name'],
            'variables' => $variables,
            'queued' => true,
            'reuse_only' => $reuseOnly,
            'resolve_next' => !$isMusic,
            'ask_at' => (new DateTimeImmutable('@' . $askAt))->setTimezone($station->getTimezoneObject())->format(DATE_ATOM),
        ]);

        $title = str_replace(PromptBuilder::MISSING, '', PromptBuilder::fill($settings['output']['meta_title'], $variables));
        $artist = str_replace(PromptBuilder::MISSING, '', PromptBuilder::fill($settings['output']['meta_artist'], $variables));
        $row = new StationQueue($station, Song::createFromArray([
            'title' => $title !== '' ? $title : $host['name'],
            'artist' => $artist,
        ]));
        $row->autodj_custom_uri = $job['path'];
        $row->duration = (float)($host['target_seconds'] + self::ESTIMATE_MARGIN
            + (!empty($settings['bed']['enabled']) ? $settings['bed']['intro'] + $settings['bed']['outro'] : 1));
        $row->is_visible = true;

        $this->launcher->start($stationId, $job['id']);

        return $row;
    }

    /**
     * Last item of the queue (the one the intervention would follow).
     *
     * @return array{title: ?string, artist: ?string, is_music: bool, starts_at: ?int}|null
     */
    private function lastRow(Station $station): ?array
    {
        $row = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.title, sq.artist, sq.timestamp_played, sq.autodj_custom_uri,
                    IDENTITY(sq.media) AS media_id, p.is_jingle
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.playlist p
                WHERE sq.station = :station
                ORDER BY sq.timestamp_cued DESC, sq.id DESC
            DQL
        )->setParameter('station', $station)->setMaxResults(1)->getOneOrNullResult();
        if (!is_array($row)) {
            return null;
        }
        $played = $row['timestamp_played'] ?? null;

        return [
            'title' => $row['title'],
            'artist' => $row['artist'],
            'is_music' => $row['media_id'] !== null && empty($row['is_jingle']) && $row['autodj_custom_uri'] === null,
            'starts_at' => $played instanceof DateTimeInterface ? $played->getTimestamp() : (is_numeric($played) ? (int)$played : null),
        ];
    }

    /** @return array{title: ?string, artist: ?string}|null last song (not jingle) of the queue */
    private function lastMusicRow(Station $station): ?array
    {
        $row = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.title, sq.artist FROM App\Entity\StationQueue sq
                LEFT JOIN sq.playlist p
                WHERE sq.station = :station AND sq.media IS NOT NULL AND sq.autodj_custom_uri IS NULL
                    AND (p.id IS NULL OR p.is_jingle = 0)
                ORDER BY sq.timestamp_cued DESC, sq.id DESC
            DQL
        )->setParameter('station', $station)->setMaxResults(1)->getOneOrNullResult();

        return is_array($row) ? ['title' => $row['title'], 'artist' => $row['artist']] : null;
    }

    public function onAnnotate(AnnotateNextSong $event): void
    {
        $queue = $event->getQueue();
        $uri = $queue?->autodj_custom_uri;
        $prefix = Plugin::dataDir() . '/' . $event->getStation()->id . '/clips/';
        if ($queue === null || $uri === null || !str_starts_with($uri, $prefix)) {
            return;
        }

        $deadline = microtime(true) + self::WAIT_FOR_CLIP;
        while (!is_file($uri) && microtime(true) < $deadline) {
            usleep(250000);
            clearstatcache(true, $uri);
        }

        $settings = [];
        try {
            $settings = $this->store->read($event->getStation());
        } catch (Throwable) {
        }

        $hide = !empty($settings['output']['hide_metadata']);
        if (is_file($uri)) {
            $duration = (float)$queue->duration;
            $bedFadeOut = 0.0;
            $job = $this->jobs->load((int)$event->getStation()->id, basename($uri, '.mp3'));
            if (is_array($job)) {
                $duration = !empty($job['duration']) ? (float)$job['duration'] : $duration;
                $bedFadeOut = !empty($job['bed']) ? (float)($settings['bed']['fade_out'] ?? 0) : 0.0;
            }
            $annotations = ClipAnnotations::build((string)$queue->title, (string)$queue->artist, $duration, $hide, $bedFadeOut);
        } else {
            $this->logger->warning('Animateurs virtuels : clip pas prêt à temps, intervention remplacée par un silence.', [
                'station' => $event->getStation()->id,
                'clip' => basename($uri),
            ]);
            $event->setSongPath(Plugin::silenceFile());
            $annotations = ClipAnnotations::build('', '', 0.5, true);
        }
        $annotations['sq_id'] = $queue->id;

        $event->addAnnotations($annotations);
    }
}
