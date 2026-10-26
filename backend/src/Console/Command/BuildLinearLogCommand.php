<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Radio\AutoDJ\LinearLogBuilder;
use App\Utilities\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Builds a station's saved linear log by hand: the same build
 * {@see \App\Sync\Task\BuildLinearLogTask} runs every day for stations with
 * the log turned on, for any station whatever its `linear_log_enabled` setting.
 *
 * The plan comes from the live selection pipeline (playlists, clock wheels,
 * schedules, top-of-hour protection) run ahead in a transaction that is rolled
 * back; only the resulting log lines are saved. Without --rebuild the planned
 * lines stay and the log is extended; with it, unlocked lines past the lock
 * window are planned again.
 */
#[AsCommand(
    name: 'azuracast:radio:build-linear-log',
    description: 'Build a linear playout log up to N hours ahead for one or all stations.',
)]
final class BuildLinearLogCommand extends CommandAbstract
{
    private const int DEFAULT_HOURS = 24;

    private const int MAX_HOURS = 48;

    public function __construct(
        private readonly StationRepository $stationRepo,
        private readonly LinearLogBuilder $linearLogBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station-name', InputArgument::OPTIONAL)
            ->addOption(
                'hours',
                null,
                InputOption::VALUE_REQUIRED,
                'How many hours ahead to build the log to.',
                (string)self::DEFAULT_HOURS,
            )
            ->addOption(
                'rebuild',
                null,
                InputOption::VALUE_NONE,
                'Re-plan unlocked lines past the lock window instead of only extending the log.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $hours = Types::int($input->getOption('hours'), self::DEFAULT_HOURS);
        $hours = max(1, min($hours, self::MAX_HOURS));

        $stationName = Types::stringOrNull($input->getArgument('station-name'));

        if (!empty($stationName)) {
            $station = $this->stationRepo->findByIdentifier($stationName);

            if (!$station instanceof Station) {
                $io->error('Station not found.');
                return 1;
            }

            $stations = [$station];
        } else {
            $stations = $this->stationRepo->fetchAll();
        }

        $io->section(sprintf('Building linear log %d hour(s) ahead...', $hours));
        $io->progressStart(count($stations));

        $failures = 0;

        foreach ($stations as $station) {
            if (!$station->supportsAutoDjQueue()) {
                $io->progressAdvance();
                continue;
            }

            try {
                if ($this->linearLogBuilder->secondsUntilClearOfTopOfHour($station) > 0) {
                    $io->note($station . ': held until the top of the hour has settled; it builds at :07.');
                }
                $this->linearLogBuilder->build($station, $hours, (bool)$input->getOption('rebuild'));
            } catch (Throwable $e) {
                $failures++;
                $io->warning($station . ': ' . $e->getMessage());
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($failures > 0) {
            $io->warning(sprintf('%d station(s) failed to build.', $failures));
        }

        $io->success('Linear log build complete.');
        return 0;
    }
}
