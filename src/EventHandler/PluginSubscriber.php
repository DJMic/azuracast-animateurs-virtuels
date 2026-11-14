<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\EventHandler;

use App\Enums\StationPermissions;
use App\Event\BuildConsoleCommands;
use App\Event\BuildRoutes;
use App\Event\GetSyncTasks;
use App\Middleware;
use Plugin\AzuracastAnimateursVirtuels\Console\GenerateCommand;
use Plugin\AzuracastAnimateursVirtuels\Controller\PanelController;
use Plugin\AzuracastAnimateursVirtuels\Middleware\InjectNavigation;
use Plugin\AzuracastAnimateursVirtuels\Sync\GenerateTask;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PluginSubscriber implements EventSubscriberInterface
{
    public const string ROUTE_PREFIX = '/animateurs-virtuels';

    public static function getSubscribedEvents(): array
    {
        return [
            BuildRoutes::class => 'routes',
            GetSyncTasks::class => 'syncTasks',
            BuildConsoleCommands::class => 'commands',
        ];
    }

    public function syncTasks(GetSyncTasks $event): void
    {
        $event->addTask(GenerateTask::class);
    }

    public function commands(BuildConsoleCommands $event): void
    {
        $event->addAliases([GenerateCommand::NAME => GenerateCommand::class]);
    }

    public function routes(BuildRoutes $event): void
    {
        $app = $event->getApp();

        // Adds the station sidebar entry to AzuraCast's own pages.
        $app->add(new InjectNavigation($app->getBasePath()));

        $app->get(
            self::ROUTE_PREFIX . '/assets/{name:(?:navigation|app)[.](?:js|css)}',
            PanelController::class . ':assetAction'
        );

        $app->group(
            self::ROUTE_PREFIX . '/{station_id}',
            function (RouteCollectorProxy $group): void {
                $group->get('/state', PanelController::class . ':stateAction');
                $group->post('/settings', PanelController::class . ':saveAction');
                $group->get('/history', PanelController::class . ':historyAction');
                $group->post('/history/clear', PanelController::class . ':clearHistoryAction');
                $group->get('/clips/{job_id}', PanelController::class . ':clipAction');
                $group->post('/test', PanelController::class . ':testAction');
                $group->post('/broadcast/{job_id}', PanelController::class . ':broadcastAction');
                $group->post('/check/deepseek', PanelController::class . ':checkDeepSeekAction');
                $group->post('/check/elevenlabs', PanelController::class . ':checkElevenLabsAction');
                $group->post('/preview', PanelController::class . ':previewAction');
            }
        )
            ->add(new Middleware\Permissions(StationPermissions::Media, true))
            ->add(Middleware\RequireStation::class)
            ->add(Middleware\GetStation::class)
            ->add(Middleware\RequireLogin::class)
            ->add(Middleware\Auth\StandardAuth::class)
            ->add(Middleware\InjectSession::class);
    }
}
