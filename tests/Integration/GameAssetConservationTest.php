<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\GameApplicationService;
use App\Application\IdempotencyConflict;
use App\Domain\Development\Coordinates;
use App\Domain\Development\Mission;
use App\Domain\Economy\ResourceAmounts;
use App\Entity\User;
use App\Tests\Support\GameVerification\GameVerificationTestCase;

/** Exact resource conservation at the persistence boundary; seeded balances are a unit fixture, not earned-play evidence. */
final class GameAssetConservationTest extends GameVerificationTestCase
{
    public function testConstructionChargeReplayAndOwnerIsolationConserveExactRationalResources(): void
    {
        $this->setGameTestClock(1_700_300_000);
        $owner = $this->registerGameTestUser('assets-a');
        $other = $this->registerGameTestUser('assets-b');
        $this->seedBalance($owner, '1001/3', '2002/7', '3/5');
        $this->seedBalance($other, '4001/9', '8003/11', '7/13');

        // These literal amounts are exact fixture inputs. The existing reviewed metal-mine target-1 cost is M90/C45/2/D0.
        $initial = ResourceAmounts::fromStrings('1001/3', '2002/7', '3/5');
        $cost = ResourceAmounts::fromStrings('90', '45/2', '0');
        $expectedAfterPayment = $initial->minus($cost)->toCanonicalArray();
        $otherInitial = ResourceAmounts::fromStrings('4001/9', '8003/11', '7/13')->toCanonicalArray();
        $token = str_repeat('e', 32);
        $ownerPlanetId = $this->planetId($owner);
        $otherPlanetId = $this->planetId($other);
        $game = self::getContainer()->get(GameApplicationService::class);

        $this->assertGameTestDatabase();
        $result = $game->enqueueConstruction($owner, $ownerPlanetId, 1, 1, $token);
        self::assertTrue($result->accepted);
        $this->assertSameBalances($ownerPlanetId, $expectedAfterPayment);
        $this->assertSameBalances($otherPlanetId, $otherInitial);

        $this->assertGameTestDatabase();
        $snapshotAfterCharge = $game->snapshotForOwner($owner)->toArray();
        $this->assertGameTestDatabase();
        $replay = $game->enqueueConstruction($owner, $ownerPlanetId, 1, 1, $token);
        self::assertTrue($replay->accepted);
        self::assertTrue($replay->replayed);
        self::assertSameBalances($ownerPlanetId, $expectedAfterPayment);
        $this->assertGameTestDatabase();
        self::assertSame($snapshotAfterCharge, $game->snapshotForOwner($owner)->toArray());

        try {
            $this->assertGameTestDatabase();
            $game->enqueueConstruction($owner, $ownerPlanetId, 2, 1, $token);
            self::fail('A changed payload reused the construction token.');
        } catch (IdempotencyConflict) {
            $this->assertSameBalances($ownerPlanetId, $expectedAfterPayment);
            $this->assertSameBalances($otherPlanetId, $otherInitial);
        }
    }

    public function testOwnedTransportChargesIndependentFixtureFuelDepositsOnceAndReturnsShip(): void
    {
        $start = 1_700_310_000;
        $clock = $this->setGameTestClock($start);
        $owner = $this->registerGameTestUser('transport-owner');
        $otherOwner = $this->registerGameTestUser('transport-other');
        $ids = $this->seedFleetAccountFixture($owner, $otherOwner, $start);
        $game = self::getContainer()->get(GameApplicationService::class);
        $ships = ['small_cargo' => 1, 'colony_ship' => 0];
        $cargo = ['metal' => '7', 'crystal' => '0', 'deuterium' => '0'];
        $token = str_repeat('f', 32);

        $this->assertGameTestDatabase();
        $launched = $game->dispatch($owner, $ids['source'], Mission::Transport, new Coordinates(1, 1, 4), $ids['destination'], $ships, $cargo, 10, $token);
        self::assertTrue($launched->accepted);
        $fleet = $this->gameConnection->fetchAssociative('SELECT * FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), $token]);
        self::assertIsArray($fleet);
        self::assertSame('2', (string) $fleet['fuel']);
        self::assertSame((string) ($start + 4540), (string) $fleet['arrives_at']);
        self::assertSame((string) ($start + 9080), (string) $fleet['returns_at']);
        self::assertSame('7/1', $fleet['launch_metal']);
        self::assertSame('1', (string) $fleet['launch_small_cargo']);
        $sourceAfterLaunch = ResourceAmounts::fromCanonical($this->balanceRow($ids['source']));
        self::assertSame(
            ResourceAmounts::fromStrings('1001/3', '2002/7', '1000/1')->minus(ResourceAmounts::fromStrings('7', '0', '2'))->toCanonicalArray(),
            $sourceAfterLaunch->toCanonicalArray(),
        );

        $clock->sleep(4540);
        $this->assertGameTestDatabase();
        $atArrival = $game->snapshotForOwner($owner);
        self::assertSame('returning', $atArrival->fleets[0]->status);
        $fleetAtArrival = $this->gameConnection->fetchAssociative('SELECT status, launch_metal, metal_cargo FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), $token]);
        self::assertSame('returning', $fleetAtArrival['status']);
        self::assertSame('7/1', $fleetAtArrival['launch_metal']);
        self::assertSame('0/1', $fleetAtArrival['metal_cargo']);
        self::assertSame('1000000007/1', $this->gameConnection->fetchOne('SELECT metal_balance FROM planet WHERE id = ?', [$ids['destination']]));

        $clock->sleep(4540);
        $this->assertGameTestDatabase();
        $complete = $game->snapshotForOwner($owner);
        self::assertSame('complete', $complete->fleets[0]->status);
        self::assertSame(2, (int) $this->gameConnection->fetchOne('SELECT small_cargo_count FROM planet WHERE id = ?', [$ids['source']]));
        self::assertSame('998/1', $this->gameConnection->fetchOne('SELECT deuterium_balance FROM planet WHERE id = ?', [$ids['source']]));
        self::assertSame('1000000007/1', $this->gameConnection->fetchOne('SELECT metal_balance FROM planet WHERE id = ?', [$ids['destination']]));
        $metalAcrossOwnedPlanets = ResourceAmounts::fromCanonical($this->balanceRow($ids['source']))
            ->plus(ResourceAmounts::fromCanonical($this->balanceRow($ids['destination'])));
        $expectedMetalTotal = ResourceAmounts::fromStrings('1001/3', '0', '0')->plus(ResourceAmounts::fromStrings('1000000000', '0', '0'));
        self::assertSame($expectedMetalTotal->toCanonicalArray()['metal'], $metalAcrossOwnedPlanets->toCanonicalArray()['metal']);
        $clock->sleep(100);
        $this->assertGameTestDatabase();
        $settledAfterNoProduction = $game->snapshotForOwner($owner);
        self::assertSame('1000000007/1', $this->gameConnection->fetchOne('SELECT metal_balance FROM planet WHERE id = ?', [$ids['destination']]));
        $beforeReplay = [$settledAfterNoProduction->toArray(), $this->balanceRow($ids['source']), $this->balanceRow($ids['destination'])];

        $this->assertGameTestDatabase();
        $replay = $game->dispatch($owner, $ids['source'], Mission::Transport, new Coordinates(1, 1, 4), $ids['destination'], $ships, $cargo, 10, $token);
        self::assertTrue($replay->accepted);
        self::assertTrue($replay->replayed);
        self::assertSame('complete', $replay->existingStatus);
        $this->assertGameTestDatabase();
        self::assertSame($beforeReplay, [$game->snapshotForOwner($owner)->toArray(), $this->balanceRow($ids['source']), $this->balanceRow($ids['destination'])]);

        try {
            $this->assertGameTestDatabase();
            $game->dispatch($owner, $ids['source'], Mission::Transport, new Coordinates(1, 1, 4), $ids['destination'], $ships,
                ['metal' => '8', 'crystal' => '0', 'deuterium' => '0'], 10, $token);
            self::fail('A changed fleet payload reused the durable token.');
        } catch (IdempotencyConflict) {
            $this->assertGameTestDatabase();
            self::assertSame($beforeReplay, [$game->snapshotForOwner($owner)->toArray(), $this->balanceRow($ids['source']), $this->balanceRow($ids['destination'])]);
        }

        $this->assertGameTestDatabase();
        $overInventory = $game->dispatch($owner, $ids['source'], Mission::Transport, new Coordinates(1, 1, 4), $ids['destination'],
            ['small_cargo' => 3, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'], 10, str_repeat('1', 32));
        self::assertFalse($overInventory->accepted, 'A new token is a new command and must pass current ship inventory checks.');
        self::assertSame(1, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), str_repeat('1', 32)]));
    }

    public function testFailedColonizationBurnsFuelOnceAndReturnsIntactColonyShipWithoutStarterResources(): void
    {
        $start = 1_700_320_000;
        $clock = $this->setGameTestClock($start);
        $owner = $this->registerGameTestUser('failed-colony-owner');
        $otherOwner = $this->registerGameTestUser('failed-colony-other');
        $ids = $this->seedFleetAccountFixture($owner, $otherOwner, $start);
        $game = self::getContainer()->get(GameApplicationService::class);
        $token = str_repeat('2', 32);
        $ships = ['small_cargo' => 0, 'colony_ship' => 1];
        $emptyCargo = ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'];

        $this->assertGameTestDatabase();
        $launch = $game->dispatch($owner, $ids['source'], Mission::Colonize, new Coordinates(1, 1, 5), null, $ships, $emptyCargo, 10, $token);
        self::assertTrue($launch->accepted);
        $fleet = $this->gameConnection->fetchAssociative('SELECT fuel, arrives_at, returns_at, launch_colony_ship FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), $token]);
        self::assertIsArray($fleet);
        self::assertGreaterThan(0, (int) $fleet['fuel']);
        self::assertSame('1', (string) $fleet['launch_colony_ship']);
        $fuel = ResourceAmounts::fromStrings('0', '0', (string) $fleet['fuel']);
        $sourceAfterPayment = ResourceAmounts::fromCanonical($this->balanceRow($ids['source']));
        self::assertSame(ResourceAmounts::fromStrings('1001/3', '2002/7', '1000/1')->minus($fuel)->toCanonicalArray(), $sourceAfterPayment->toCanonicalArray());

        // Deliberately occupy the unreserved target after launch; the write is db_test-guarded.
        $this->executeGameTestWrite('UPDATE planet SET galaxy = 1, system = 1, position = 5 WHERE id = ?', [$ids['other_owner_planet']]);
        $clock->sleep((int) $fleet['arrives_at'] - $start);
        $this->assertGameTestDatabase();
        $arrival = $game->snapshotForOwner($owner);
        self::assertCount(2, $arrival->planets);
        self::assertSame('returning', $arrival->fleets[0]->status);
        $failed = $this->gameConnection->fetchAssociative('SELECT status, outcome, colony_ship, launch_colony_ship, launch_metal, metal_cargo, fuel FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), $token]);
        self::assertSame('returning', $failed['status']);
        self::assertNotSame('colonized', $failed['outcome']);
        self::assertSame('1', (string) $failed['colony_ship']);
        self::assertSame('1', (string) $failed['launch_colony_ship']);
        self::assertSame('0/1', $failed['metal_cargo']);
        self::assertSame((string) $fleet['fuel'], (string) $failed['fuel']);

        $clock->sleep((int) $fleet['returns_at'] - (int) $fleet['arrives_at']);
        $this->assertGameTestDatabase();
        $returned = $game->snapshotForOwner($owner);
        self::assertCount(2, $returned->planets, 'A failed claim awards no colony starter resources.');
        self::assertSame(1, (int) $this->gameConnection->fetchOne('SELECT colony_ship_count FROM planet WHERE id = ?', [$ids['source']]));
        self::assertSame($sourceAfterPayment->toCanonicalArray(), ResourceAmounts::fromCanonical($this->balanceRow($ids['source']))->toCanonicalArray(), 'Burned fuel is not refunded on failure.');
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM planet WHERE owner_id = ? AND galaxy = 1 AND system = 1 AND position = 5', [$owner->getId()]));
        self::assertSame('complete', $returned->fleets[0]->status);
    }

    public function testSuccessfulColonyBirthAddsStarterExactlyOnceAndConsumesOneColonyShip(): void
    {
        $start = 1_700_330_000;
        $clock = $this->setGameTestClock($start);
        $owner = $this->registerGameTestUser('successful-colony-owner');
        $otherOwner = $this->registerGameTestUser('successful-colony-other');
        $ids = $this->seedFleetAccountFixture($owner, $otherOwner, $start);
        $game = self::getContainer()->get(GameApplicationService::class);
        $token = str_repeat('3', 32);
        $ships = ['small_cargo' => 0, 'colony_ship' => 1];
        $emptyCargo = ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'];
        $target = new Coordinates(1, 1, 5);

        $this->assertGameTestDatabase();
        $launch = $game->dispatch($owner, $ids['source'], Mission::Colonize, $target, null, $ships, $emptyCargo, 10, $token);
        self::assertTrue($launch->accepted);
        $arrivesAt = (int) $this->gameConnection->fetchOne('SELECT arrives_at FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), $token]);
        $clock->sleep($arrivesAt - $start);
        $this->assertGameTestDatabase();
        $arrived = $game->snapshotForOwner($owner);

        self::assertCount(3, $arrived->planets);
        $colony = null;
        foreach ($arrived->planets as $reference => $planet) {
            if ($planet->coordinates->key() === $target->key()) { $colony = [$reference, $planet]; break; }
        }
        self::assertNotNull($colony, 'Successful arrival materializes one planet and remaps its logical reference to a database ID.');
        [$colonyReference, $planet] = $colony;
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/D', $colonyReference);
        self::assertSame($arrivesAt, $planet->bornAt);
        self::assertSame(['metal' => '500/1', 'crystal' => '500/1', 'deuterium' => '0/1'], $planet->economy->resources->toCanonicalArray());
        self::assertGreaterThanOrEqual(60, $planet->economy->temperatureMax);
        self::assertLessThanOrEqual(100, $planet->economy->temperatureMax);
        self::assertGreaterThanOrEqual(148, $planet->economy->fieldsTotal);
        self::assertLessThanOrEqual(210, $planet->economy->fieldsTotal);
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT colony_ship_count FROM planet WHERE id = ?', [$ids['source']]));
        self::assertSame('complete', $arrived->fleets[0]->status, 'The sole colony ship is consumed; no return ship remains.');

        $beforeReplay = [$arrived->toArray(), (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM planet WHERE owner_id = ?', [$owner->getId()])];
        $this->assertGameTestDatabase();
        $replay = $game->dispatch($owner, $ids['source'], Mission::Colonize, $target, null, $ships, $emptyCargo, 10, $token);
        self::assertTrue($replay->accepted);
        self::assertTrue($replay->replayed);
        self::assertSame('complete', $replay->existingStatus);
        self::assertSame($beforeReplay, [$game->snapshotForOwner($owner)->toArray(), (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM planet WHERE owner_id = ?', [$owner->getId()])]);

        $this->assertGameTestDatabase();
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM coordinate_lock WHERE universe_id = 1 AND galaxy = 9 AND system = 400 AND position = 15'));
        $rejected = $game->dispatch($owner, $ids['source'], Mission::Colonize, new Coordinates(9, 400, 15), null,
            ['small_cargo' => 0, 'colony_ship' => 1], $emptyCargo, 10, str_repeat('6', 32));
        self::assertFalse($rejected->accepted, 'No colony ship remains after the successful sole-ship colony.');
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM coordinate_lock WHERE universe_id = 1 AND galaxy = 9 AND system = 400 AND position = 15'), 'Rejected dispatch must roll back its provisional coordinate mutex.');
        self::assertSame(0, (int) $this->gameConnection->fetchOne('SELECT COUNT(*) FROM fleet WHERE owner_id = ? AND command_token = ?', [$owner->getId(), str_repeat('6', 32)]));
    }

    private function seedBalance(User $user, string $metal, string $crystal, string $deuterium): void
    {
        $this->executeGameTestWrite(
            'UPDATE planet SET metal_balance = ?, crystal_balance = ?, deuterium_balance = ? WHERE owner_id = ?',
            [$metal, $crystal, $deuterium, $user->getId()],
        );
    }

    /** @return array{metal:string,crystal:string,deuterium:string} */
    private function balanceRow(int $planetId): array
    {
        $this->assertGameTestDatabase();
        $row = $this->gameConnection->fetchAssociative(
            'SELECT metal_balance AS metal, crystal_balance AS crystal, deuterium_balance AS deuterium FROM planet WHERE id = ?',
            [$planetId],
        );
        self::assertIsArray($row);
        return $row;
    }

    private function planetId(User $user): int
    {
        $this->assertGameTestDatabase();
        $id = $this->gameConnection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$user->getId()]);
        self::assertNotFalse($id);
        return (int) $id;
    }

    /** @param array{metal:string,crystal:string,deuterium:string} $expected */
    private function assertSameBalances(int $planetId, array $expected): void
    {
        self::assertSame($expected, $this->gameConnection->fetchAssociative(
            'SELECT metal_balance AS metal, crystal_balance AS crystal, deuterium_balance AS deuterium FROM planet WHERE id = ?',
            [$planetId],
        ));
    }
}
