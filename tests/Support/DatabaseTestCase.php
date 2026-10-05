<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\RegistrationService;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $entityManager->getConnection();
        self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
        $this->clearGameRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
            $this->clearGameRows();
        }
        parent::tearDown();
    }

    protected function setTestClock(int $timestamp): MockClock
    {
        $clock = new MockClock(new \DateTimeImmutable('@'.$timestamp));
        self::getContainer()->set(ClockInterface::class, $clock);

        return $clock;
    }

    protected function registerUser(string $email = 'pilot@example.test', string $password = 'correct horse battery staple'): User
    {
        $user = new User();
        $user->setEmail($email);
        self::getContainer()->get(RegistrationService::class)->register($user, $password);

        return $user;
    }

    private function clearGameRows(): void
    {
        $this->connection->executeStatement('DELETE FROM construction_entry');
        $this->connection->executeStatement('DELETE FROM planet');
        $this->connection->executeStatement('DELETE FROM game_user');
    }
}
