<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\EconomyApplicationService;
use App\Repository\ConstructionEntryRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:economy:process', description: 'Settle a bounded batch of due construction queues.')]
final class ProcessEconomyCommand extends Command
{
    public function __construct(
        private readonly ConstructionEntryRepository $entries,
        private readonly EconomyApplicationService $economy,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum planets to process (1-100).', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limitValue = $input->getOption('limit');
        if (!is_string($limitValue) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limitValue) !== 1) {
            $output->writeln('<error>Limit must be an integer between 1 and 100.</error>');

            return Command::INVALID;
        }
        $limit = (int) $limitValue;
        if ($limit > 100) {
            $output->writeln('<error>Limit must be an integer between 1 and 100.</error>');

            return Command::INVALID;
        }

        $discoveryTime = (string) $this->clock->now()->getTimestamp();
        $planetIds = $this->entries->findDuePlanetIds($discoveryTime, $limit);
        $processed = 0;
        foreach ($planetIds as $planetId) {
            if ($this->economy->settlePlanetById($planetId)) {
                ++$processed;
            }
        }

        $output->writeln(sprintf('<info>Settled %d of %d due planet candidate(s).</info>', $processed, count($planetIds)));

        return Command::SUCCESS;
    }
}
