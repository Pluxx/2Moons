<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseBoundaryTest extends KernelTestCase
{
    public function testConnectionUsesOnlyTheDedicatedTestDatabase(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame('db_test', $connection->fetchOne('SELECT DATABASE()'));
        self::assertSame('1', (string) $connection->fetchOne('SELECT 1'));
    }

    public function testRollbackLeavesNoPersistentTestData(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame('db_test', $connection->fetchOne('SELECT DATABASE()'));

        $connection->executeStatement('CREATE TEMPORARY TABLE phase2_rollback_probe (value INT NOT NULL)');
        $connection->beginTransaction();
        try {
            $connection->insert('phase2_rollback_probe', ['value' => 42]);
            $connection->rollBack();

            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM phase2_rollback_probe'));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS phase2_rollback_probe');
        }
    }
}
