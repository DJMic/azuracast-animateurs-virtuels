<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use Plugin\AzuracastAnimateursVirtuels\Console\GenerateCommand;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts the generation of a queued intervention right away, in a detached background process,
 * so that AzuraCast's queue building is never slowed down. If this fails, the plugin's
 * one-minute sync task generates it anyway.
 */
final class Launcher
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function start(int $stationId, string $jobId, int $delaySeconds = 3): void
    {
        if (!function_exists('proc_open') || !Jobs::isValidId($jobId)) {
            return;
        }
        $console = self::console();
        if ($console === null) {
            return;
        }
        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : (Shell::which('php') ?? 'php');

        // Same as AzuraCast's azuracast_cli: run from the application folder.
        $command = sprintf(
            'sleep %d; cd %s && exec %s %s %s %d %s',
            max(0, $delaySeconds),
            escapeshellarg($console['cwd']),
            escapeshellarg($php),
            escapeshellarg($console['script']),
            escapeshellarg(GenerateCommand::NAME),
            $stationId,
            escapeshellarg($jobId)
        );
        $line = 'if command -v setsid >/dev/null 2>&1; then setsid sh -c ' . escapeshellarg($command)
            . '; else sh -c ' . escapeshellarg($command) . '; fi >/dev/null 2>&1 </dev/null &';

        try {
            $process = proc_open(['/bin/sh', '-c', $line], [], $pipes);
            if (is_resource($process)) {
                proc_close($process); // returns at once: the work runs in the background
            }
        } catch (Throwable $e) {
            $this->logger->notice('Animateurs virtuels : lancement immédiat impossible (' . $e->getMessage() . ').');
        }
    }

    /**
     * AzuraCast's console: backend/bin/console in recent versions, bin/console before.
     *
     * @return array{cwd: string, script: string}|null
     */
    private static function console(): ?array
    {
        // <AzuraCast>/plugins/<this plugin> → <AzuraCast>
        foreach (array_unique([dirname(Plugin::dir(), 2), '/var/azuracast/www']) as $root) {
            foreach (['backend/bin/console', 'bin/console'] as $script) {
                if (is_file($root . '/' . $script)) {
                    return ['cwd' => $root, 'script' => $script];
                }
            }
        }

        return null;
    }
}
