<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Container\EntityManagerAwareTrait;
use App\Entity\AiDj;
use App\Entity\Station;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'azuracast:ai-dj:generate',
    description: 'Validate AI DJ configuration for stations.',
)]
final class GenerateAiDjCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    public function __construct() {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'station-name',
            InputArgument::OPTIONAL,
            'Validate AI DJ for a single station by short name or ID.'
        );

        $this->addOption(
            'dj-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Specific DJ ID to validate.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('AI DJ Configuration Validator');

        $stationName = $input->getArgument('station-name');
        $djId = $input->getOption('dj-id');

        try {
            $stations = $this->resolveStations($stationName);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }

        if (empty($stations)) {
            $io->warning('No matching stations found.');
            return 0;
        }

        $successCount = 0;
        $skipCount = 0;

        foreach ($stations as $station) {
            $io->section($station->name);

            try {
                $djRepo = $this->em->getRepository(AiDj::class);
                $djs = $djId
                    ? [$djRepo->find((int) $djId)]
                    : $djRepo->findBy(['station' => $station, 'is_enabled' => true]);

                if (empty($djs)) {
                    $io->text('<comment>No enabled DJs found — skipped.</comment>');
                    $skipCount++;
                    continue;
                }

                foreach ($djs as $dj) {
                    if (null !== $dj) {
                        $io->text(sprintf('DJ: <info>%s</info>', $dj->getName()));
                    }
                }

                $successCount++;
            } catch (Throwable $e) {
                $io->error(sprintf('Failed: %s', $e->getMessage()));
                return 1;
            }
        }

        $io->success(
            sprintf(
                'Processed %d station(s): %d validated, %d skipped.',
                count($stations),
                $successCount,
                $skipCount
            )
        );

        return 0;
    }

    /**
     * @return Station[]
     */
    private function resolveStations(?string $stationName): array
    {
        $repo = $this->em->getRepository(Station::class);

        if (null !== $stationName) {
            $station = $repo->findOneBy(['short_name' => $stationName])
                ?? $repo->find($stationName);

            return (null !== $station) ? [$station] : [];
        }

        return $repo->findAll();
    }
}
