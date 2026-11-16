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

    /** How long Liquidsoap's request may wait for a clip still being generated. */
    private const int WAIT_FOR_CLIP = 6;

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

        $decision = null;
        $this->jobs->updateState($stationId, function (array $state) use ($onAir, $playAt, $isMusic, &$decision): array {
            [$decision, $state] = Planner::decide($state, $onAir, $playAt, $isMusic);

            return $state;
        });
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

        $previous = $this->previousSong($station);
        $variables = PromptBuilder::variables(
            $host,
            $station->name,
            $settings['language'],
            $playAt,
            $previous,
            ['title' => $next->title, 'artist' => $next->artist]
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

    /** @return array{title: ?string, artist: ?string} last song already in the queue */
    private function previousSong(Station $station): array
    {
        $row = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.title, sq.artist FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.autodj_custom_uri IS NULL
                ORDER BY sq.timestamp_cued DESC
            DQL
        )->setParameter('station', $station)->setMaxResults(1)->getOneOrNullResult();

        return is_array($row) ? ['title' => $row['title'], 'artist' => $row['artist']] : [];
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

        $annotations = [
            'title' => (string)$queue->title,
            'artist' => (string)$queue->artist,
            'sq_id' => $queue->id,
        ];
        if (!empty($settings['output']['hide_metadata'])) {
            $annotations['jingle_mode'] = 'true';
        }

        if (is_file($uri)) {
            $annotations['duration'] = (float)$queue->duration;
            $job = $this->jobs->load((int)$event->getStation()->id, basename($uri, '.mp3'));
            if (is_array($job) && !empty($job['duration'])) {
                $annotations['duration'] = (float)$job['duration'];
            }
        } else {
            $this->logger->warning('Animateurs virtuels : clip pas prêt à temps, intervention remplacée par un silence.', [
                'station' => $event->getStation()->id,
                'clip' => basename($uri),
            ]);
            $event->setSongPath(Plugin::silenceFile());
            $annotations['duration'] = 0.5;
            $annotations['jingle_mode'] = 'true';
        }

        $event->addAnnotations($annotations);
    }
}
