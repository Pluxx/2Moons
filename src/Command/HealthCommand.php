<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:health', description: 'Check PHP and read-only database connectivity')]
final class HealthCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->connection->fetchOne('SELECT DATABASE()');
        $version = $this->connection->fetchOne('SELECT VERSION()');
        $this->connection->fetchOne('SELECT 1');

        $output->writeln(sprintf(
            '<info>PHP %s; connected to database %s (%s).</info>',
            PHP_VERSION,
            $database,
            $version,
        ));

        return Command::SUCCESS;
    }
}
