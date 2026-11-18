<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Console;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use Plugin\AzuracastAnimateursVirtuels\Service\ChronicleGenerator;
use Plugin\AzuracastAnimateursVirtuels\Service\Generator;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * docker compose exec web azuracast_cli animateurs-virtuels:generate <station>           (last interventions)
 * docker compose exec web azuracast_cli animateurs-virtuels:generate <station> <job id>  (generate a pending one)
 *
 * Also started in the background by the plugin as soon as an intervention is queued.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Animateurs virtuels : liste les dernières interventions ou génère une intervention en attente.'
)]
final class GenerateCommand extends Command
{
    use EntityManagerAwareTrait;

    public const string NAME = 'animateurs-virtuels:generate';

    public function __construct(
        private readonly Generator $generator,
        private readonly ChronicleGenerator $chronicles,
        private readonly Jobs $jobs
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'ID ou nom court de la station')
            ->addArgument('job', InputArgument::OPTIONAL, 'ID de l\'intervention à générer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string)$input->getArgument('station');
        $repo = $this->em->getRepository(Station::class);
        $station = ctype_digit($key) ? $repo->find((int)$key) : $repo->findOneBy(['short_name' => $key]);
        if (!$station instanceof Station) {
            $io->error('Station introuvable : ' . $key);

            return 1;
        }

        $jobId = $input->getArgument('job');
        if ($jobId === null) {
            $rows = array_map(static fn(array $job): array => [
                $job['id'],
                $job['host_name'] ?? '',
                $job['kind'] ?? '',
                $job['status'] ?? '',
                mb_substr((string)($job['text'] ?: $job['error']), 0, 70),
            ], $this->jobs->list((int)$station->id, 20));
            $io->table(['ID', 'Animateur', 'Type', 'Statut', 'Texte / erreur'], $rows);

            return 0;
        }

        $pending = $this->jobs->load((int)$station->id, (string)$jobId);
        $job = ($pending['kind'] ?? '') === 'chronicle'
            ? $this->chronicles->run($station, (string)$jobId)
            : $this->generator->generate($station, (string)$jobId);
        if ($job === null) {
            $io->note('Déjà en cours de génération par un autre processus.');

            return 0;
        }
        $io->writeln($job['status'] . ($job['error'] !== '' ? ' : ' . $job['error'] : ''));

        return $job['status'] === 'failed' ? 1 : 0;
    }
}
