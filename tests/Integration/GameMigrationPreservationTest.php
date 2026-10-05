<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\GameApplicationService;
use App\Application\IdempotencyConflict;
use App\Entity\User;
use App\Domain\Economy\Building;
use App\Domain\Development\Technology;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/** DDL commits on MariaDB: this test runs outside DatabaseTestCase and restores latest in finally. */
final class GameMigrationPreservationTest extends KernelTestCase
{
    private const BASELINE = 'DoctrineMigrations\\Version20261005000000';
    private const START = 1_700_100_000;
    private const ACTIVE_TOKEN = 'a1111111111111111111111111111111';
    private const WAITING_TOKEN = 'b2222222222222222222222222222222';
    private const TERMINAL_TOKEN = 'c3333333333333333333333333333333';

    private Connection $connection;
    private string $email = 'phase-b-migration-preservation@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->assertDbTest();
    }

    public function testAdditiveMigrationPreservesLegacyRowsAndCompletedReplayIdentity(): void
    {
        $this->assertProjectMigrationBeyondBaselineExists();

        try {
            $this->resetToEmptyTestSchema();
            $this->migrate(self::BASELINE);
            $this->assertDbTest();
            $this->connection->insert('game_user', ['email' => $this->email, 'password_hash' => 'migration-fixture-hash', 'created_at' => '2023-11-14 22:13:20']);
            $userId = (int) $this->connection->lastInsertId();
            $this->assertDbTest();
            $this->connection->insert('game_user', ['email' => 'phase-b-migration-coordinate@example.test', 'password_hash' => 'migration-fixture-hash', 'created_at' => '2023-11-14 22:13:20']);
            $otherUserId = (int) $this->connection->lastInsertId();
            $this->assertDbTest();
            $this->connection->insert('planet', [
                'owner_id' => $userId, 'name' => 'Preserved home', 'metal_balance' => '1234567/3', 'crystal_balance' => '7654321/7', 'deuterium_balance' => '98765/11',
                'metal_mine_level' => 1, 'crystal_mine_level' => 0, 'deuterium_synthesizer_level' => 0, 'solar_plant_level' => 1,
                'metal_storage_level' => 0, 'crystal_storage_level' => 0, 'deuterium_storage_level' => 0,
                'temperature_max' => 40, 'fields_total' => 163, 'fields_used' => 2, 'last_settled_at' => (string) self::START,
            ]);
            $planetId = (int) $this->connection->lastInsertId();
            $this->assertDbTest();
            $this->connection->insert('planet', [
                'owner_id' => $otherUserId, 'name' => 'Other home', 'metal_balance' => '100/1', 'crystal_balance' => '100/1', 'deuterium_balance' => '100/1',
                'metal_mine_level' => 0, 'crystal_mine_level' => 0, 'deuterium_synthesizer_level' => 0, 'solar_plant_level' => 0,
                'metal_storage_level' => 0, 'crystal_storage_level' => 0, 'deuterium_storage_level' => 0,
                'temperature_max' => 40, 'fields_total' => 163, 'fields_used' => 0, 'last_settled_at' => (string) self::START,
            ]);
            $otherPlanetId = (int) $this->connection->lastInsertId();
            self::assertGreaterThan(0, $planetId);
            self::assertGreaterThan(0, $otherPlanetId);
            $this->insertConstruction($planetId, 0, self::ACTIVE_TOKEN, 1, 2, 'active', self::START, self::START, self::START + 162, null, null);
            $this->insertConstruction($planetId, 1, self::WAITING_TOKEN, 2, 1, 'waiting', self::START + 1, null, null, null, null);
            $this->insertConstruction($planetId, 2, self::TERMINAL_TOKEN, 4, 1, 'completed', self::START - 1000, self::START - 1000, self::START - 838, self::START - 838, null);

            $legacyBefore = $this->legacySnapshot($planetId, $userId);
            $this->migrate('latest');
            $this->clearEntityManager();
            $user = $this->findUser($userId);
            $otherUser = $this->findUser($otherUserId);

            $game = self::getContainer()->get(GameApplicationService::class);
            $this->assertDbTest();
            $account = $game->snapshotForOwner($user);
            $this->assertDbTest();
            $otherAccount = $game->snapshotForOwner($otherUser);
            self::assertSame(self::START, $account->settledAt);
            self::assertArrayHasKey((string) $planetId, $account->planets, 'Persistent planet references are normalized database-ID strings.');
            self::assertCount(1, $account->planets);
            $home = $account->planets[(string) $planetId];
            self::assertSame([1, 1, 40, 163], [
                $home->economy->levels->get(Building::MetalMine),
                $home->economy->levels->get(Building::SolarPlant),
                $home->economy->temperatureMax,
                $home->economy->fieldsTotal,
            ]);
            foreach ([Building::RoboticsFactory, Building::Shipyard, Building::Laboratory] as $newBuilding) {
                self::assertSame(0, $home->economy->levels->get($newBuilding));
            }
            self::assertSame(['small_cargo' => 0, 'colony_ship' => 0], $home->ships);
            self::assertSame(array_fill_keys(array_map(static fn (Technology $technology): string => $technology->value, Technology::cases()), 0), $account->research);
            self::assertSame(self::START, $home->economy->lastSettledAt);
            self::assertNotSame('', $home->coordinates->key());
            self::assertArrayHasKey((string) $this->connection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$otherUserId]), $otherAccount->planets);
            $otherPlanet = array_values($otherAccount->planets)[0];
            self::assertNotSame($home->coordinates->key(), $otherPlanet->coordinates->key(), 'Migrated home coordinates must be unique across accounts.');
            self::assertSame($legacyBefore, $this->legacySnapshot($planetId, $userId));

            $this->assertDbTest();
            $terminalReplay = $game->enqueueConstruction($user, $planetId, 4, 1, self::TERMINAL_TOKEN);
            self::assertTrue($terminalReplay->accepted);
            self::assertTrue($terminalReplay->replayed);
            self::assertSame('completed', $terminalReplay->existingStatus);
            self::assertSame($legacyBefore, $this->legacySnapshot($planetId, $userId));

            try {
                $this->assertDbTest();
                $game->enqueueConstruction($user, $planetId, 1, 2, self::TERMINAL_TOKEN);
                self::fail('Changed payload reused a completed token after migration.');
            } catch (IdempotencyConflict) {
                self::assertSame($legacyBefore, $this->legacySnapshot($planetId, $userId));
            }
        } finally {
            // Restoration is always attempted, including assertion or migration failures.
            $this->resetToEmptyTestSchema();
            $this->migrate('latest');
        }
    }

    public function testMigrationPreflightRejectsMalformedLegacyBalanceBeforeSchemaChanges(): void
    {
        $this->assertProjectMigrationBeyondBaselineExists();
        try {
            $this->resetToEmptyTestSchema();
            $this->migrate(self::BASELINE);
            $this->assertDbTest();
            $this->connection->insert('game_user', ['email' => 'phase-b-malformed-preflight@example.test', 'password_hash' => 'migration-fixture-hash', 'created_at' => '2023-11-14 22:13:20']);
            $userId = (int) $this->connection->lastInsertId();
            $this->assertDbTest();
            $this->connection->insert('planet', [
                'owner_id' => $userId, 'name' => 'Malformed home', 'metal_balance' => 'malformed-rational', 'crystal_balance' => '100/1', 'deuterium_balance' => '100/1',
                'metal_mine_level' => 0, 'crystal_mine_level' => 0, 'deuterium_synthesizer_level' => 0, 'solar_plant_level' => 0,
                'metal_storage_level' => 0, 'crystal_storage_level' => 0, 'deuterium_storage_level' => 0,
                'temperature_max' => 40, 'fields_total' => 163, 'fields_used' => 0, 'last_settled_at' => (string) self::START,
            ]);

            $rejected = false;
            try {
                $this->migrate('latest');
            } catch (\Throwable) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Migration preflight must reject a non-canonical legacy balance.');
            $columns = array_map(static fn ($column): string => $column->getName(), $this->connection->createSchemaManager()->listTableColumns('planet'));
            self::assertNotContains('robotics_factory_level', $columns, 'Preflight rejection must precede additive DDL.');
            self::assertNotContains('home_planet_id', array_map(static fn ($column): string => $column->getName(), $this->connection->createSchemaManager()->listTableColumns('game_user')));
        } finally {
            $this->resetToEmptyTestSchema();
            $this->migrate('latest');
        }
    }

    private function insertConstruction(int $planetId, int $position, string $token, int $building, int $target,
        string $status, int $enqueued, ?int $started, ?int $completes, ?int $resolved, ?string $failure): void
    {
        $this->write(
            'INSERT INTO construction_entry (planet_id, position, command_token, building_id, target_level, status, enqueued_at, started_at, completes_at, resolved_at, failure_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$planetId, $position, $token, $building, $target, $status, $enqueued, $started, $completes, $resolved, $failure],
        );
    }

    /** @return array{planet: array<string,mixed>, constructions: list<array<string,mixed>>, user: array<string,mixed>} */
    private function legacySnapshot(int $planetId, int $userId): array
    {
        $planet = $this->connection->fetchAssociative(
            'SELECT id, owner_id, metal_balance, crystal_balance, deuterium_balance, metal_mine_level, solar_plant_level, temperature_max, fields_total, fields_used, last_settled_at FROM planet WHERE id = ?',
            [$planetId],
        );
        $constructions = $this->connection->fetchAllAssociative(
            'SELECT id, position, command_token, building_id, target_level, status, enqueued_at, started_at, completes_at, resolved_at, failure_reason FROM construction_entry WHERE planet_id = ? ORDER BY position',
            [$planetId],
        );
        $user = $this->connection->fetchAssociative('SELECT id, email FROM game_user WHERE id = ?', [$userId]);
        self::assertIsArray($planet);
        self::assertIsArray($user);

        return ['planet' => $planet, 'constructions' => $constructions, 'user' => $user];
    }

    private function migrate(string $version): void
    {
        $this->assertDbTest();
        $application = new FrameworkApplication(self::$kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new CommandTester($application->find('doctrine:migrations:migrate'));
        $status = $tester->execute(['version' => $version, '--no-interaction' => true], ['interactive' => false]);
        self::assertSame(0, $status, $tester->getDisplay());
    }

    private function assertProjectMigrationBeyondBaselineExists(): void
    {
        $files = glob(self::$kernel->getProjectDir().'/migrations/Version*.php') ?: [];
        $newVersions = array_filter($files, static function (string $file): bool {
            $source = file_get_contents($file);
            return basename($file) !== 'Version20261005000000.php'
                && is_string($source)
                && preg_match('/namespace\s+DoctrineMigrations\s*;/', $source) === 1;
        });
        self::assertNotEmpty($newVersions, 'Expected an additive migration in the configured DoctrineMigrations namespace.');
    }

    private function resetToEmptyTestSchema(): void
    {
        $this->assertDbTest();
        $tables = $this->connection->createSchemaManager()->listTableNames();
        $allowed = [
            'doctrine_migration_versions', 'game_user', 'planet', 'construction_entry',
            'universe', 'coordinate_lock', 'research_entry', 'shipyard_batch', 'fleet',
        ];
        self::assertSame([], array_values(array_diff($tables, $allowed)), 'Refusing to reset an unexpected db_test table.');

        // The test database alone is rebuilt; the later migration is irreversible and is never rolled down.
        $this->assertDbTest();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['fleet', 'research_entry', 'shipyard_batch', 'construction_entry', 'coordinate_lock', 'planet', 'game_user', 'universe', 'doctrine_migration_versions'] as $table) {
                if (!in_array($table, $tables, true)) { continue; }
                $this->assertDbTest();
                $this->connection->executeStatement('DROP TABLE `'.$table.'`');
            }
        } finally {
            $this->assertDbTest();
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function write(string $sql, array $parameters = []): void
    {
        $this->assertDbTest();
        $this->connection->executeStatement($sql, $parameters);
    }

    private function assertDbTest(): void
    {
        self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
    }

    private function setClock(int $timestamp): MockClock
    {
        $clock = new MockClock(new \DateTimeImmutable('@'.$timestamp));
        self::getContainer()->set(ClockInterface::class, $clock);
        return $clock;
    }

    private function clearEntityManager(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function findUser(int $id): User
    {
        $user = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }
}
