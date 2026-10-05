<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\GameApplicationService;
use App\Domain\Development\AccountState;
use App\Domain\Development\Coordinates;
use App\Domain\Development\Mission;
use App\Domain\Development\Technology;
use App\Domain\Economy\Building;
use App\Entity\User;
use App\Domain\Development\Ship;
use App\Tests\Support\GameVerification\GameVerificationTestCase;

final class GameAccountPersistenceTest extends GameVerificationTestCase
{
    public function testSnapshotRoundTripsCompleteAccountKeysAndPersistsExistingConstructionQueue(): void
    {
        $this->setGameTestClock(1_700_200_000);
        $owner = $this->registerGameTestUser('roundtrip');
        $game = self::getContainer()->get(GameApplicationService::class);
        $planetId = $this->planetId($owner);

        $this->assertGameTestDatabase();
        $initial = $game->snapshotForOwner($owner);
        self::assertSame((string) $owner->getId(), $initial->ownerId);
        self::assertArrayHasKey((string) $planetId, $initial->planets);
        $home = $initial->planets[(string) $planetId];
        self::assertEqualsCanonicalizing(
            array_map(static fn (Building $building): string => $building->value, Building::cases()),
            array_keys($home->economy->levels->toArray()),
        );
        self::assertEqualsCanonicalizing(
            array_map(static fn (Technology $technology): string => $technology->value, Technology::cases()),
            array_keys($initial->research),
        );
        self::assertSame(['small_cargo' => 0, 'colony_ship' => 0], $home->ships);
        self::assertSame($initial->toArray(), AccountState::fromArray($initial->toArray())->toArray());

        $this->assertGameTestDatabase();
        $first = $game->enqueueConstruction($owner, $planetId, 1, 1, str_repeat('a', 32));
        $this->assertGameTestDatabase();
        $second = $game->enqueueConstruction($owner, $planetId, 2, 1, str_repeat('b', 32));
        self::assertTrue($first->accepted);
        self::assertTrue($second->accepted);
        $rows = $this->gameConnection->fetchAllAssociative(
            'SELECT command_token, status, started_at, completes_at FROM construction_entry WHERE planet_id = ? ORDER BY position',
            [$planetId],
        );
        self::assertCount(2, $rows);
        self::assertSame('active', $rows[0]['status']);
        self::assertNotNull($rows[0]['started_at']);
        self::assertNotNull($rows[0]['completes_at']);
        self::assertSame('waiting', $rows[1]['status']);
        self::assertNull($rows[1]['started_at']);
        self::assertNull($rows[1]['completes_at']);

        $this->assertGameTestDatabase();
        $beforeReject = $game->snapshotForOwner($owner)->toArray();
        $this->assertGameTestDatabase();
        $rejected = $game->enqueueResearch($owner, $planetId, 106, 1, str_repeat('c', 32));
        self::assertFalse($rejected->accepted, 'Missing completed Laboratory 3 must reject without partial persistence.');
        self::assertNotNull($rejected->rejectionCode);
        $this->assertGameTestDatabase();
        self::assertSame($beforeReject, $game->snapshotForOwner($owner)->toArray());
        self::assertSame(2, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE planet_id = ?', [$planetId]));
    }

    public function testPlanetIdIsAnOwnedSelectorAndCannotSettleAnotherAccount(): void
    {
        $this->setGameTestClock(1_700_210_000);
        $owner = $this->registerGameTestUser('owner');
        $other = $this->registerGameTestUser('other');
        $game = self::getContainer()->get(GameApplicationService::class);
        $ownerPlanetId = $this->planetId($owner);
        $otherPlanetId = $this->planetId($other);
        $this->assertGameTestDatabase();
        $beforeOwner = $game->snapshotForOwner($owner)->toArray();
        $this->assertGameTestDatabase();
        $beforeOther = $game->snapshotForOwner($other)->toArray();
        self::assertArrayNotHasKey((string) $ownerPlanetId, $beforeOther['planets']);

        $this->assertGameTestDatabase();
        $foreignOverviewRejected = false;
        try {
            $game->overview($other, $ownerPlanetId);
        } catch (\Throwable) {
            // Application implementations may represent the not-owned selector as a typed rejection or not-found error.
            $foreignOverviewRejected = true;
        }
        self::assertTrue($foreignOverviewRejected, 'A foreign planet ID was accepted by overview.');
        $this->assertGameTestDatabase();
        $attempt = $game->enqueueConstruction($other, $ownerPlanetId, 1, 1, str_repeat('d', 32));
        self::assertFalse($attempt->accepted);
        $this->assertGameTestDatabase();
        $researchAttempt = $game->enqueueResearch($other, $ownerPlanetId, 106, 1, str_repeat('e', 32));
        self::assertFalse($researchAttempt->accepted);
        $this->assertGameTestDatabase();
        $shipAttempt = $game->enqueueShips($other, $ownerPlanetId, 202, 1, str_repeat('f', 32));
        self::assertFalse($shipAttempt->accepted);
        $this->assertGameTestDatabase();
        $fleetAttempt = $game->dispatch($other, $ownerPlanetId, Mission::Transport, new Coordinates(1, 1, 4), $ownerPlanetId,
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'], 10, str_repeat('1', 32));
        self::assertFalse($fleetAttempt->accepted);
        $this->assertGameTestDatabase();
        self::assertSame($beforeOwner, $game->snapshotForOwner($owner)->toArray());
        $this->assertGameTestDatabase();
        self::assertSame($beforeOther, $game->snapshotForOwner($other)->toArray());
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE command_token = ?', [str_repeat('d', 32)]));
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM research_entry WHERE command_token IN (?,?)', [str_repeat('e', 32), str_repeat('1', 32)]));
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM shipyard_batch WHERE command_token = ?', [str_repeat('f', 32)]));
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM fleet WHERE command_token = ?', [str_repeat('1', 32)]));
    }

    public function testLateAccountCatchupPersistsTwoResearchSourcesTenBatchesAndPartialProduction(): void
    {
        $start = 1_700_220_000;
        $clock = $this->setGameTestClock($start);
        $owner = $this->registerGameTestUser('late-catchup');
        $ids = $this->seedAccountQueuePersistenceFixture($owner, $start);
        $game = self::getContainer()->get(GameApplicationService::class);

        $this->assertGameTestDatabase();
        $before = $game->snapshotForOwner($owner);
        self::assertCount(2, $before->researchQueue);
        self::assertSame((string) $ids['home'], $before->researchQueue[0]->sourcePlanet);
        self::assertSame((string) $ids['funding_planet'], $before->researchQueue[1]->sourcePlanet);
        self::assertCount(5, $before->planets[(string) $ids['home']]->economy->pendingEntries);
        self::assertCount(10, $before->planets[(string) $ids['home']]->batches);
        self::assertSame(8, $before->planets[(string) $ids['home']]->ships[Ship::SmallCargo->value]);

        $storedResearch = $this->gameConnection->fetchAllAssociative(
            'SELECT technology_id, source_planet_id, target_level, status, started_at, completes_at FROM research_entry WHERE owner_id = ? ORDER BY position',
            [$owner->getId()],
        );
        self::assertSame('active', $storedResearch[0]['status']);
        self::assertSame((string) $ids['home'], (string) $storedResearch[0]['source_planet_id']);
        self::assertSame('waiting', $storedResearch[1]['status']);
        self::assertSame((string) $ids['funding_planet'], (string) $storedResearch[1]['source_planet_id']);
        self::assertSame('3', (string) $this->gameConnection->fetchOne('SELECT produced FROM shipyard_batch WHERE planet_id = ? AND position = 0', [$ids['home']]));

        $clock->sleep(100);
        $this->assertGameTestDatabase();
        $after = $game->snapshotForOwner($owner);
        self::assertSame($start + 100, $after->settledAt);
        self::assertSame(2, $after->research['energy']);
        self::assertSame(2, $after->research['combustion']);
        self::assertCount(2, $after->researchQueue);
        self::assertSame((string) $ids['funding_planet'], $after->researchQueue[0]->sourcePlanet);
        self::assertSame($start + 100, $after->researchQueue[0]->startedAt);
        self::assertGreaterThan($start + 100, $after->researchQueue[0]->completesAt);

        $rows = $this->gameConnection->fetchAllAssociative(
            'SELECT building_id, target_level, status, started_at, completes_at, resolved_at FROM construction_entry WHERE planet_id = ? ORDER BY position',
            [$ids['home']],
        );
        self::assertSame('completed', $rows[0]['status']);
        self::assertSame((string) ($start + 100), (string) $rows[0]['completes_at']);
        self::assertSame((string) ($start + 100), (string) $rows[0]['resolved_at']);
        self::assertSame('active', $rows[1]['status']);
        self::assertSame((string) ($start + 100), (string) $rows[1]['started_at']);

        $batches = $this->gameConnection->fetchAllAssociative(
            'SELECT position, status, produced, started_at, completes_at, resolved_at FROM shipyard_batch WHERE planet_id = ? ORDER BY position',
            [$ids['home']],
        );
        self::assertCount(10, $batches);
        self::assertSame('completed', $batches[0]['status']);
        self::assertSame('10', (string) $batches[0]['produced']);
        self::assertSame((string) ($start + 70), (string) $batches[0]['resolved_at']);
        self::assertSame('active', $batches[1]['status']);
        self::assertSame((string) ($start + 70), (string) $batches[1]['started_at']);
        self::assertSame(15, $after->planets[(string) $ids['home']]->ships[Ship::SmallCargo->value]);
        self::assertGreaterThan(0, $batches[1]['completes_at']);

        $this->assertGameTestDatabase();
        $researchResponse = $game->enqueueResearch($owner, $ids['home'], 106, 4, str_repeat('4', 32));
        self::assertTrue($researchResponse->accepted, 'Completed Laboratory 3 and Spy 3 satisfy the next Spy Technology target.');
        $this->assertGameTestDatabase();
        $shipResponse = $game->enqueueShips($owner, $ids['home'], 202, 2, str_repeat('5', 32));
        self::assertTrue($shipResponse->accepted, 'Completed Shipyard 4 and Combustion 2 satisfy Small Cargo admission.');
        self::assertSame('waiting', $this->gameConnection->fetchOne('SELECT status FROM research_entry WHERE owner_id = ? AND command_token = ?', [$owner->getId(), str_repeat('4', 32)]));
        self::assertSame('waiting', $this->gameConnection->fetchOne('SELECT status FROM shipyard_batch WHERE planet_id = ? AND command_token = ?', [$ids['home'], str_repeat('5', 32)]));
    }

    private function planetId(User $user): int
    {
        $this->assertGameTestDatabase();
        $id = $this->gameConnection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$user->getId()]);
        self::assertNotFalse($id);
        return (int) $id;
    }
}
