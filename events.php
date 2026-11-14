<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use Plugin\AzuracastAnimateursVirtuels\EventHandler\PluginSubscriber;
use Plugin\AzuracastAnimateursVirtuels\EventHandler\QueueSubscriber;

return static function (CallableEventDispatcherInterface $dispatcher): void {
    $dispatcher->addServiceSubscriber([
        PluginSubscriber::class,
        QueueSubscriber::class,
    ]);
};
