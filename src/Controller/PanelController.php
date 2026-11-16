<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Controller;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Enums\LiquidsoapQueues;
use DateTimeImmutable;
use InvalidArgumentException;
use Plugin\AzuracastAnimateursVirtuels\Service\DeepSeek;
use Plugin\AzuracastAnimateursVirtuels\Service\ElevenLabs;
use Plugin\AzuracastAnimateursVirtuels\Service\Generator;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Library;
use Plugin\AzuracastAnimateursVirtuels\Service\Planner;
use Plugin\AzuracastAnimateursVirtuels\Service\Plugin;
use Plugin\AzuracastAnimateursVirtuels\Service\PromptBuilder;
use Plugin\AzuracastAnimateursVirtuels\Service\Settings;
use Plugin\AzuracastAnimateursVirtuels\Service\Shell;
use Plugin\AzuracastAnimateursVirtuels\Service\Store;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class PanelController
{
    public const string CSRF_NAMESPACE = 'animateurs_virtuels';

    public function __construct(
        private readonly Store $store,
        private readonly Jobs $jobs,
        private readonly Library $library,
        private readonly Generator $generator,
        private readonly DeepSeek $deepSeek,
        private readonly ElevenLabs $elevenLabs,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em
    ) {
    }

    public function assetAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $name = (string)($params['name'] ?? '');
        $path = match ($name) {
            'navigation.js' => Plugin::dir() . '/frontend/navigation.js',
            'app.js', 'app.css' => Plugin::dir() . '/dist/' . $name,
            default => null,
        };
        if ($path === null) {
            return $response->withStatus(404);
        }
        if (!is_file($path)) {
            $response->getBody()->write(
                'Interface du plugin non compilée. Exécutez bin/setup.sh dans le dossier du plugin.'
            );

            return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response->getBody()->write((string)file_get_contents($path));

        return $response
            ->withHeader('Content-Type', str_ends_with($name, '.css') ? 'text/css' : 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function stateAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();

            return $this->json($response, [
                'version' => Plugin::version(),
                'csrf' => $request->getCsrf()->generate(self::CSRF_NAMESPACE),
                'station' => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'timezone' => $station->timezone,
                ],
                'settings' => Settings::forDisplay($this->store->read($station)),
                'playlists' => $this->playlists($station),
                'variables' => PromptBuilder::VARIABLES,
                'default_system_prompt' => Settings::DEFAULT_SYSTEM_PROMPT,
                'default_host' => Settings::defaultHost(),
                'status' => $this->status($station),
            ]);
        });
    }

    public function saveAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $settings = Settings::fromInput($this->body($request), $this->store->read($station));

            $this->checkPlaylist($station, $settings['bed']['playlist_id']);
            foreach ($settings['hosts'] as $host) {
                $this->checkPlaylist($station, $host['bed_playlist_id']);
            }
            if ($settings['enabled'] && !$this->jobs->isWritable()) {
                throw new InvalidArgumentException(
                    'Le dossier de données du plugin n\'est pas accessible en écriture. Relancez bin/setup.sh.'
                );
            }

            $this->store->write($station, $settings);

            // Library of a deleted host.
            if ($this->jobs->isWritable()) {
                $ids = array_column($settings['hosts'], 'id');
                foreach ($this->library->hosts((int)$station->id) as $hostId) {
                    if (!in_array($hostId, $ids, true)) {
                        $this->library->deleteHost((int)$station->id, $hostId);
                    }
                }
            }

            return $this->json($response, [
                'success' => true,
                'message' => 'Réglages enregistrés.',
                'settings' => Settings::forDisplay($settings),
                'status' => $this->status($station),
            ]);
        });
    }

    public function historyAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();
            $rows = [];
            if ($this->jobs->isWritable()) {
                foreach ($this->jobs->list((int)$station->id, 200) as $job) {
                    $rows[] = $this->jobForDisplay($job);
                }
            }

            return $this->json($response, ['rows' => $rows, 'status' => $this->status($station)]);
        });
    }

    public function clearHistoryAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $count = $this->jobs->clear((int)$request->getStation()->id);

            return $this->json($response, [
                'success' => true,
                'message' => $count . ' intervention(s) supprimée(s). Celles qui attendent leur diffusion sont conservées.',
            ]);
        });
    }

    public function clipAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $id = (string)($params['job_id'] ?? '');
        if (!Jobs::isValidId($id)) {
            return $response->withStatus(404);
        }
        $path = $this->jobs->clipPath((int)$station->id, $id);
        if (!is_file($path)) {
            return $response->withStatus(404);
        }
        $response->getBody()->write((string)file_get_contents($path));

        return $response
            ->withHeader('Content-Type', 'audio/mpeg')
            ->withHeader('Content-Length', (string)filesize($path))
            ->withHeader('Content-Disposition', 'inline; filename="intervention-' . $id . '.mp3"')
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }

    /**
     * Generates an intervention now with the saved settings, without queueing it.
     */
    public function testAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $body = $this->body($request);
            $settings = $this->store->read($station);
            $host = Settings::findHost($settings, (string)($body['host_id'] ?? ''))
                ?? throw new InvalidArgumentException('Animateur introuvable : enregistrez d\'abord vos réglages.');
            if ($settings['deepseek']['api_key'] === '' || $settings['elevenlabs']['api_key'] === '') {
                throw new InvalidArgumentException('Renseignez et enregistrez d\'abord les clés API DeepSeek et ElevenLabs.');
            }
            if ($host['voice_id'] === '') {
                throw new InvalidArgumentException('Renseignez le Voice ID de « ' . $host['name'] . ' ».');
            }

            $now = new DateTimeImmutable('now', $station->getTimezoneObject());
            if (!empty($settings['limits']['daily_enabled'])
                && $this->jobs->countToday((int)$station->id, $now) >= (int)$settings['limits']['daily_max']) {
                throw new InvalidArgumentException('La limite quotidienne d\'interventions est atteinte.');
            }

            $promptId = (string)($body['prompt_id'] ?? '');
            $prompt = Planner::pickPrompt($host, 'test', $promptId !== '' ? $promptId : null)
                ?? throw new InvalidArgumentException('Aucun prompt actif pour « ' . $host['name'] . ' ».');
            [$previous, $next] = $this->currentSongs($station);
            $variables = PromptBuilder::variables($host, $station->name, $settings['language'], $now, $previous, $next);

            $job = $this->jobs->create((int)$station->id, $now, [
                'kind' => 'test',
                'play_at' => null,
                'host_id' => $host['id'],
                'host_name' => $host['name'],
                'prompt_id' => $prompt['id'],
                'prompt_name' => $prompt['name'],
                'variables' => $variables,
            ]);

            @set_time_limit(300);
            $job = $this->generator->generate($station, $job['id'], true) ?? $job;

            return $this->json($response, [
                'success' => $job['status'] === 'done',
                'message' => $job['status'] === 'done' ? 'Intervention générée.' : ($job['error'] ?: 'Échec.'),
                'job' => $this->jobForDisplay($job),
            ], $job['status'] === 'done' ? 200 : 502);
        });
    }

    /**
     * Airs an existing clip right after the current song (Liquidsoap request queue).
     */
    public function broadcastAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response, $params): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $id = (string)($params['job_id'] ?? '');
            $job = $this->jobs->load((int)$station->id, $id) ?? throw new InvalidArgumentException('Intervention introuvable.');
            $path = $this->jobs->clipPath((int)$station->id, $id);
            if (!is_file($path)) {
                throw new InvalidArgumentException('Le fichier de cette intervention n\'existe plus.');
            }
            $backend = $this->adapters->getBackendAdapter($station)
                ?? throw new InvalidArgumentException('Cette station n\'utilise pas Liquidsoap.');

            $settings = $this->store->read($station);
            $variables = is_array($job['variables'] ?? null) ? $job['variables'] : [];
            $annotations = array_filter([
                'title' => str_replace(PromptBuilder::MISSING, '', PromptBuilder::fill($settings['output']['meta_title'], $variables))
                    ?: (string)$job['host_name'],
                'artist' => str_replace(PromptBuilder::MISSING, '', PromptBuilder::fill($settings['output']['meta_artist'], $variables)),
                'duration' => (float)($job['duration'] ?? 0) ?: null,
                'jingle_mode' => !empty($settings['output']['hide_metadata']) ? 'true' : null,
            ], static fn(mixed $v): bool => $v !== null && $v !== '');

            $backend->enqueue(
                $station,
                LiquidsoapQueues::Requests,
                'annotate:' . ConfigWriter::annotateArray($annotations) . ':' . $path
            );

            return $this->json($response, [
                'success' => true,
                'message' => 'Intervention envoyée : elle passera à l\'antenne à la fin du morceau en cours.',
            ]);
        });
    }

    public function checkDeepSeekAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $key = $this->key($request, 'deepseek');
            $result = $this->deepSeek->check($key);

            return $this->json($response, [
                'success' => true,
                'message' => 'Clé DeepSeek valide.'
                    . ($result['balance'] !== '' ? ' Solde : ' . $result['balance'] . '.' : '')
                    . ($result['models'] !== [] ? ' Modèles disponibles : ' . implode(', ', $result['models']) . '.' : ''),
                'models' => $result['models'],
            ]);
        });
    }

    public function checkElevenLabsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $key = $this->key($request, 'elevenlabs');
            $voiceId = trim((string)($this->body($request)['voice_id'] ?? ''));
            if ($voiceId === '') {
                throw new InvalidArgumentException('Indiquez un Voice ID.');
            }
            $voice = $this->elevenLabs->voice($key, $voiceId);
            $quota = $this->elevenLabs->quota($key);

            return $this->json($response, [
                'success' => true,
                'message' => 'Voix trouvée : « ' . $voice['name'] . ' »'
                    . ($quota !== null
                        ? '. Caractères utilisés ce mois-ci : ' . number_format($quota['used'], 0, ',', ' ')
                            . ' sur ' . number_format($quota['limit'], 0, ',', ' ') . '.'
                        : '.'),
                'voice' => $voice,
            ]);
        });
    }

    /**
     * Shows the messages that would be sent to DeepSeek for a host and prompt of the form
     * (not saved yet), with example values.
     */
    public function previewAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $body = $this->body($request);
            $settings = Settings::withDefaults($this->store->read($station));
            $settings['system_prompt'] = trim((string)($body['system_prompt'] ?? '')) ?: Settings::DEFAULT_SYSTEM_PROMPT;
            $settings['language'] = trim((string)($body['language'] ?? '')) ?: $settings['language'];

            $hostInput = is_array($body['host'] ?? null) ? $body['host'] : [];
            $hostInput['enabled'] = false; // no completeness check for a preview
            $host = Settings::host($hostInput);
            $index = (int)($body['prompt_index'] ?? 0);
            $prompt = $host['prompts'][$index] ?? $host['prompts'][0]
                ?? throw new InvalidArgumentException('Ce prompt est vide.');

            $now = new DateTimeImmutable('now', $station->getTimezoneObject());
            [$previous, $next] = $this->currentSongs($station);
            $previous = array_filter($previous) ?: ['title' => 'Titre précédent', 'artist' => 'Artiste précédent'];
            $next = array_filter($next) ?: ['title' => 'Titre suivant', 'artist' => 'Artiste suivant'];
            $variables = PromptBuilder::variables($host, $station->name, $settings['language'], $now, $previous, $next);
            $recent = $this->jobs->isWritable() && ($hostInput['id'] ?? '') !== ''
                ? $this->jobs->recentTexts((int)$station->id, (string)$hostInput['id'], (int)$settings['avoid_repeat'])
                : [];

            return $this->json($response, [
                'messages' => PromptBuilder::messages($settings, $host, $prompt, $variables, $recent),
            ]);
        });
    }

    public function libraryAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();
            $settings = $this->store->read($station);
            $hosts = [];
            foreach ($settings['hosts'] as $host) {
                $signature = Library::voiceSignature($settings, $host);
                $config = Library::config($host);
                $entries = $this->jobs->isWritable() ? $this->library->entries((int)$station->id, $host['id']) : [];
                $valid = 0;
                foreach ($entries as &$entry) {
                    $entry['obsolete'] = ($entry['signature'] ?? '') !== $signature;
                    $valid += $entry['obsolete'] ? 0 : 1;
                    unset($entry['signature']);
                }
                unset($entry);
                $hosts[] = [
                    'host_id' => $host['id'],
                    'name' => $host['name'],
                    'config' => $config,
                    'valid' => $valid,
                    'share' => Library::reuseShare($config, $valid),
                    'timeless_prompts' => count(array_filter(
                        $host['prompts'],
                        static fn(array $p): bool => !empty($p['timeless']) && !empty($p['enabled'])
                    )),
                    'entries' => $entries,
                ];
            }

            return $this->json($response, ['hosts' => $hosts]);
        });
    }

    public function libraryAudioAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $hostId = (string)($params['host_id'] ?? '');
        $id = (string)($params['entry_id'] ?? '');
        if (!Library::isValidId($hostId) || !Library::isValidId($id)) {
            return $response->withStatus(404);
        }
        $path = $this->library->audioPath((int)$request->getStation()->id, $hostId, $id);
        if (!is_file($path)) {
            return $response->withStatus(404);
        }
        $response->getBody()->write((string)file_get_contents($path));

        return $response
            ->withHeader('Content-Type', 'audio/mpeg')
            ->withHeader('Content-Length', (string)filesize($path))
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }

    public function libraryDeleteAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response, $params): ResponseInterface {
            $this->verifyCsrf($request);
            $hostId = (string)($params['host_id'] ?? '');
            if (!Library::isValidId($hostId)) {
                throw new InvalidArgumentException('Animateur invalide.');
            }
            $deleted = $this->library->delete((int)$request->getStation()->id, $hostId, (string)($params['entry_id'] ?? ''));

            return $this->json($response, [
                'success' => $deleted,
                'message' => $deleted ? 'Clip supprimé de la bibliothèque.' : 'Ce clip n\'existe plus.',
            ]);
        });
    }

    public function libraryObsoleteAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response, $params): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $settings = $this->store->read($station);
            $host = Settings::findHost($settings, (string)($params['host_id'] ?? ''))
                ?? throw new InvalidArgumentException('Animateur introuvable.');
            $count = $this->library->deleteObsolete((int)$station->id, $host['id'], Library::voiceSignature($settings, $host));

            return $this->json($response, [
                'success' => true,
                'message' => $count . ' clip(s) enregistré(s) avec une ancienne voix supprimé(s).',
            ]);
        });
    }

    // --- helpers --------------------------------------------------------------------------------

    private function status(Station $station): array
    {
        $settings = $this->store->read($station);
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $writable = $this->jobs->isWritable();
        $onAir = Planner::onAir($settings, $now);

        return [
            'now' => $now->format(DATE_ATOM),
            'data_writable' => $writable,
            'data_dir' => $this->jobs->root(),
            'ffmpeg' => Shell::which('ffmpeg') !== null,
            'autodj' => $station->supportsAutoDjQueue(),
            'ready' => Settings::isReady($settings),
            'today' => $writable ? $this->jobs->countToday((int)$station->id, $now) : 0,
            'today_reused' => $writable ? $this->jobs->todayStats((int)$station->id, $now)['reused'] : 0,
            'on_air' => $onAir !== null ? [
                'host' => $onAir['host']['name'],
                'start' => $onAir['slot']['start'],
                'end' => $onAir['slot']['end'],
            ] : null,
        ];
    }

    private function jobForDisplay(array $job): array
    {
        $path = (string)($job['path'] ?? '');
        unset($job['path']);
        $job['has_clip'] = $path !== '' && is_file($path);
        if (is_array($job['bed'] ?? null)) {
            unset($job['bed']['path']);
        }

        return $job;
    }

    /**
     * Song on air now and next song of the queue (for tests and previews).
     *
     * @return array{0: array, 1: array}
     */
    private function currentSongs(Station $station): array
    {
        $previous = [];
        $current = $station->current_song;
        if ($current !== null) {
            $previous = ['title' => $current->title, 'artist' => $current->artist];
        }
        $next = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.title, sq.artist FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.is_played = 0 AND sq.autodj_custom_uri IS NULL
                ORDER BY sq.timestamp_cued ASC
            DQL
        )->setParameter('station', $station)->setMaxResults(1)->getOneOrNullResult();

        return [$previous, is_array($next) ? ['title' => $next['title'], 'artist' => $next['artist']] : []];
    }

    private function playlists(Station $station): array
    {
        $playlists = $this->em->getRepository(StationPlaylist::class)->findBy(['station' => $station], ['name' => 'ASC']);
        $rows = [];
        foreach ($playlists as $playlist) {
            /** @var StationPlaylist $playlist */
            if ($playlist->source !== PlaylistSources::Songs) {
                continue;
            }
            $rows[] = [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'enabled' => $playlist->is_enabled,
                'advanced' => $playlist->type === PlaylistTypes::Advanced,
            ];
        }

        return $rows;
    }

    private function checkPlaylist(Station $station, ?int $playlistId): void
    {
        if ($playlistId === null) {
            return;
        }
        $playlist = $this->em->find(StationPlaylist::class, $playlistId);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            throw new InvalidArgumentException('Playlist du fond musical introuvable pour cette station.');
        }
        if ($playlist->source !== PlaylistSources::Songs) {
            throw new InvalidArgumentException('La playlist « ' . $playlist->name . ' » ne contient pas de fichiers.');
        }
    }

    /** API key typed in the form, or the saved one if the field still shows the mask. */
    private function key(ServerRequest $request, string $service): string
    {
        $typed = trim((string)($this->body($request)['api_key'] ?? ''));
        if ($typed !== '' && $typed !== Settings::MASK) {
            return $typed;
        }
        $saved = $this->store->read($request->getStation())[$service]['api_key'];
        if ($saved === '') {
            throw new InvalidArgumentException('Saisissez d\'abord la clé API.');
        }

        return $saved;
    }

    private function verifyCsrf(ServerRequest $request): void
    {
        try {
            $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), self::CSRF_NAMESPACE);
        } catch (Throwable) {
            throw new InvalidArgumentException('Session expirée : rechargez la page puis recommencez.');
        }
    }

    private function body(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = json_decode((string)$request->getBody(), true);
        }

        return is_array($body) ? $body : [];
    }

    private function json(Response $response, array $data, int $status = 200): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withJson($data, $status);
    }

    /**
     * @param callable(): ResponseInterface $action
     */
    private function guard(Response $response, callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
