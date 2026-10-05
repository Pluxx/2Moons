<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\RegistrationService;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationPersistenceTest extends DatabaseTestCase
{
    public function testRegistrationCreatesNormalizedHashedAccountAndExactlyOneCanonicalFortyDegreePlanet(): void
    {
        $this->setTestClock(1_700_000_000);
        $user = $this->registerUser('  Pilot@Example.Test  ', 'correct horse battery staple');

        self::assertNotNull($user->getId());
        self::assertSame('pilot@example.test', $user->getUserIdentifier());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet'));
        self::assertSame('pilot@example.test', $this->connection->fetchOne('SELECT email FROM game_user'));

        $hash = (string) $this->connection->fetchOne('SELECT password_hash FROM game_user');
        self::assertNotSame('correct horse battery staple', $hash);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'correct horse battery staple'));

        $row = $this->connection->fetchAssociative('SELECT * FROM planet');
        self::assertIsArray($row);
        self::assertSame('500/1', $row['metal_balance']);
        self::assertSame('500/1', $row['crystal_balance']);
        self::assertSame('0/1', $row['deuterium_balance']);
        self::assertSame(40, (int) $row['temperature_max']);
        self::assertSame(163, (int) $row['fields_total']);
        self::assertSame(0, (int) $row['fields_used']);
        self::assertSame('1700000000', (string) $row['last_settled_at']);
        foreach ([
            'metal_mine_level', 'crystal_mine_level', 'deuterium_synthesizer_level',
            'solar_plant_level', 'metal_storage_level', 'crystal_storage_level', 'deuterium_storage_level',
        ] as $levelColumn) {
            self::assertSame(0, (int) $row[$levelColumn]);
        }
    }

    public function testDatabaseUniqueEmailConstraintRemainsAuthoritativeForCaseNormalizedRaces(): void
    {
        $this->setTestClock(1_700_000_000);
        $this->registerUser('same@example.test');
        $duplicate = new User();
        $duplicate->setEmail('SAME@example.test');

        try {
            self::getContainer()->get(RegistrationService::class)->register($duplicate, 'different safe password');
            self::fail('Database accepted a duplicate normalized email.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet'));
        }
    }

    public function testFailureAfterUserInsertRollsBackUserAndStartingPlanet(): void
    {
        $this->setTestClock(1_700_000_000);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class($this->connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function postPersist(PostPersistEventArgs $event): void
            {
                if ($event->getObject() instanceof User) {
                    if (!$this->connection->isTransactionActive()
                        || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user WHERE email = ?', ['rollback@example.test']) !== 1) {
                        throw new \RuntimeException('The user row was not visible inside the registration transaction.');
                    }
                    throw new \RuntimeException('Controlled test failure after user INSERT.');
                }
            }
        };
        $eventManager = $entityManager->getEventManager();
        $eventManager->addEventListener(Events::postPersist, $listener);
        $user = new User();
        $user->setEmail('rollback@example.test');

        try {
            self::getContainer()->get(RegistrationService::class)->register($user, 'correct horse battery staple');
            self::fail('Expected controlled post-INSERT persistence failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Controlled test failure after user INSERT.', $exception->getMessage());
        } finally {
            $eventManager->removeEventListener(Events::postPersist, $listener);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet'));
    }
}
