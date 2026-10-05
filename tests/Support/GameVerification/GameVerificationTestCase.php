<?php

declare(strict_types=1);

namespace App\Tests\Support\GameVerification;

use App\Application\RegistrationService;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/** Database helpers for colonization/transport integration tests; never resets the shared test database. */
abstract class GameVerificationTestCase extends KernelTestCase
{
    protected Connection $gameConnection;

    /** @var list<string> */
    private array $ownedEmails = [];

    private static int $emailSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->gameConnection = self::getContainer()->get(Connection::class);
        $this->assertGameTestDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->gameConnection)) {
            foreach ($this->ownedEmails as $email) {
                $this->deleteOwnedTestUser($email);
            }
        }
        parent::tearDown();
    }

    protected function assertGameTestDatabase(): void
    {
        self::assertSame('db_test', $this->gameConnection->fetchOne('SELECT DATABASE()'));
    }

    /** The identity check is deliberately adjacent to every fixture write. */
    protected function executeGameTestWrite(string $sql, array $parameters = []): int
    {
        $this->assertGameTestDatabase();

        return $this->gameConnection->executeStatement($sql, $parameters);
    }

    protected function setGameTestClock(int $timestamp): MockClock
    {
        $clock = new MockClock(new \DateTimeImmutable('@'.$timestamp));
        self::getContainer()->set(ClockInterface::class, $clock);

        return $clock;
    }

    protected function registerGameTestUser(string $label = 'pilot', string $password = 'correct horse battery staple'): User
    {
        $email = sprintf('phase-b-%s-%d-%d@example.test', $label, getmypid(), ++self::$emailSequence);
        $user = new User();
        $user->setEmail($email);
        $this->assertGameTestDatabase();
        self::getContainer()->get(RegistrationService::class)->register($user, $password);
        $this->ownedEmails[] = $email;

        return $user;
    }

    /** @return array{home:int,funding_planet:int} Purpose-built persisted snapshot fixture, not an earned-play setup. */
    protected function seedAccountQueuePersistenceFixture(User $user, int $now): array
    {
        $home = (int) $this->gameConnection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$user->getId()]);
        $this->executeGameTestWrite(
            'UPDATE game_user SET settled_at = ?, research_spy = 3, research_energy = 1, research_combustion = 2, research_impulse = 3, research_expedition = 1 WHERE id = ?',
            [(string) $now, $user->getId()],
        );
        $this->executeGameTestWrite(
            'UPDATE planet SET metal_balance = ?, crystal_balance = ?, deuterium_balance = ?, metal_mine_level = 1, solar_plant_level = 1, robotics_factory_level = 2, shipyard_level = 4, laboratory_level = 3, fields_used = 11, small_cargo_count = 5, colony_ship_count = 3, last_settled_at = ?, born_at = ? WHERE id = ?',
            ['10000000/1', '10000000/1', '10000000/1', (string) $now, (string) $now, $home],
        );
        $this->executeGameTestWrite(
            'INSERT INTO planet (owner_id, name, metal_balance, crystal_balance, deuterium_balance, metal_mine_level, crystal_mine_level, deuterium_synthesizer_level, solar_plant_level, metal_storage_level, crystal_storage_level, deuterium_storage_level, robotics_factory_level, shipyard_level, laboratory_level, temperature_max, fields_total, fields_used, last_settled_at, universe_id, galaxy, system, position, born_at, small_cargo_count, colony_ship_count) VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 40, 163, 1, ?, 1, 1, 2, 3, ?, 1, 0)',
            [$user->getId(), 'Funding world fixture', '10000000/1', '10000000/1', '10000000/1', (string) $now, (string) $now],
        );
        $fundingPlanet = (int) $this->gameConnection->lastInsertId();

        $this->executeGameTestWrite(
            'INSERT INTO construction_entry (planet_id, position, command_token, building_id, target_level, status, enqueued_at, started_at, completes_at, resolved_at, failure_reason) VALUES (?, 0, ?, 1, 2, \'active\', ?, ?, ?, NULL, NULL)',
            [$home, self::fixtureToken(100), (string) ($now - 100), (string) ($now - 100), (string) ($now + 100)],
        );
        foreach ([[2, 1], [3, 1], [22, 1], [23, 1]] as $offset => [$building, $target]) {
            $this->executeGameTestWrite(
                'INSERT INTO construction_entry (planet_id, position, command_token, building_id, target_level, status, enqueued_at, started_at, completes_at, resolved_at, failure_reason) VALUES (?, ?, ?, ?, ?, \'waiting\', ?, NULL, NULL, NULL, NULL)',
                [$home, $offset + 1, self::fixtureToken(101 + $offset), $building, $target, (string) ($now - 99 + $offset)],
            );
        }

        $this->executeGameTestWrite(
            'INSERT INTO research_entry (owner_id, source_planet_id, position, command_token, technology_id, target_level, status, enqueued_at, started_at, completes_at, resolved_at, cost_metal, cost_crystal, cost_deuterium, failure_reason) VALUES (?, ?, 0, ?, 113, 2, \'active\', ?, ?, ?, NULL, ?, ?, ?, NULL)',
            [$user->getId(), $home, self::fixtureToken(200), (string) ($now - 100), (string) ($now - 100), (string) ($now + 100), '0/1', '3200/1', '1600/1'],
        );
        $this->executeGameTestWrite(
            'INSERT INTO research_entry (owner_id, source_planet_id, position, command_token, technology_id, target_level, status, enqueued_at, started_at, completes_at, resolved_at, cost_metal, cost_crystal, cost_deuterium, failure_reason) VALUES (?, ?, 1, ?, 115, 3, \'waiting\', ?, NULL, NULL, NULL, ?, ?, ?, NULL)',
            [$user->getId(), $fundingPlanet, self::fixtureToken(201), (string) ($now - 99), '0/1', '0/1', '0/1'],
        );

        for ($position = 0; $position < 10; ++$position) {
            $active = $position === 0;
            $shipId = $position % 2 === 0 ? 202 : 208;
            $quantity = $active ? 10 : 1;
            $metal = $shipId === 202 ? 2000 * $quantity : 10000 * $quantity;
            $crystal = $shipId === 202 ? 2000 * $quantity : 20000 * $quantity;
            $deuterium = $shipId === 202 ? 0 : 10000 * $quantity;
            $status = $active ? 'active' : 'waiting';
            $enqueuedAt = $active ? $now - 50 : $now - 49 + $position;
            $startedAt = $active ? (string) ($now - 30) : null;
            $unitSeconds = $active ? 10 : null;
            $produced = $active ? 3 : 0;
            $completesAt = $active ? (string) ($now + 70) : null;
            $this->executeGameTestWrite(
                'INSERT INTO shipyard_batch (planet_id, position, command_token, ship_id, quantity, produced, cost_metal, cost_crystal, cost_deuterium, status, enqueued_at, started_at, unit_seconds, completes_at, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
                [$home, $position, self::fixtureToken(300 + $position), $shipId, $quantity, $produced, $metal.'/1', $crystal.'/1', $deuterium.'/1', $status, (string) $enqueuedAt, $startedAt, $unitSeconds, $completesAt],
            );
        }

        return ['home' => $home, 'funding_planet' => $fundingPlanet];
    }

    /** @return array{source:int,destination:int,other_owner_planet:int} */
    protected function seedFleetAccountFixture(User $owner, User $otherOwner, int $now): array
    {
        $source = (int) $this->gameConnection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$owner->getId()]);
        $otherPlanet = (int) $this->gameConnection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$otherOwner->getId()]);
        $this->executeGameTestWrite('UPDATE game_user SET settled_at = ?, research_combustion = 2, research_impulse = 3, research_expedition = 1 WHERE id = ?', [(string) $now, $owner->getId()]);
        $this->executeGameTestWrite('UPDATE game_user SET settled_at = ? WHERE id = ?', [(string) $now, $otherOwner->getId()]);
        $this->executeGameTestWrite(
            'UPDATE planet SET metal_balance = ?, crystal_balance = ?, deuterium_balance = ?, last_settled_at = ?, born_at = ?, galaxy = 1, system = 1, position = 3, small_cargo_count = 2, colony_ship_count = 1 WHERE id = ?',
            ['1001/3', '2002/7', '1000/1', (string) $now, (string) $now, $source],
        );
        $this->executeGameTestWrite(
            'UPDATE planet SET galaxy = 1, system = 2, position = 3, last_settled_at = ?, born_at = ? WHERE id = ?',
            [(string) $now, (string) $now, $otherPlanet],
        );
        $this->executeGameTestWrite(
            'INSERT INTO planet (owner_id, name, metal_balance, crystal_balance, deuterium_balance, metal_mine_level, crystal_mine_level, deuterium_synthesizer_level, solar_plant_level, metal_storage_level, crystal_storage_level, deuterium_storage_level, robotics_factory_level, shipyard_level, laboratory_level, temperature_max, fields_total, fields_used, last_settled_at, universe_id, galaxy, system, position, born_at, small_cargo_count, colony_ship_count) VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 40, 163, 0, ?, 1, 1, 1, 4, ?, 0, 0)',
            [$owner->getId(), 'Owned transport destination fixture', '1000000000/1', '500/1', '0/1', (string) $now, (string) $now],
        );
        $destination = (int) $this->gameConnection->lastInsertId();

        return ['source' => $source, 'destination' => $destination, 'other_owner_planet' => $otherPlanet];
    }

    protected static function fixtureToken(int $number): string
    {
        return sprintf('%032x', $number);
    }

    private function deleteOwnedTestUser(string $email): void
    {
        $this->assertGameTestDatabase();
        $id = $this->gameConnection->fetchOne('SELECT id FROM game_user WHERE email = ?', [$email]);
        if ($id === false) { return; }
        $planetIds = $this->gameConnection->fetchFirstColumn('SELECT id FROM planet WHERE owner_id = ?', [$id]);
        $this->executeGameTestWrite('DELETE FROM fleet WHERE owner_id = ?', [$id]);
        $this->executeGameTestWrite('DELETE FROM research_entry WHERE owner_id = ?', [$id]);
        if ($planetIds !== []) {
            $marks = implode(',', array_fill(0, count($planetIds), '?'));
            $this->executeGameTestWrite('DELETE FROM shipyard_batch WHERE planet_id IN ('.$marks.')', $planetIds);
            $this->executeGameTestWrite('DELETE FROM construction_entry WHERE planet_id IN ('.$marks.')', $planetIds);
        }
        $this->executeGameTestWrite('DELETE FROM planet WHERE owner_id = ?', [$id]);
        $this->executeGameTestWrite('DELETE FROM game_user WHERE id = ?', [$id]);
    }
}
