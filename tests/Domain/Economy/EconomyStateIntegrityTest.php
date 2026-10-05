<?php

declare(strict_types=1);

namespace App\Tests\Domain\Economy;

use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\ConstructionEntry;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\PlanetEconomyState;

final class EconomyStateIntegrityTest extends EconomyTestCase
{
    public function testPartialBuildingLevelsRemainAvailableForFixtureConstructionButPersistenceRequiresAllSeven(): void
    {
        $partial = BuildingLevels::fromArray(['metal_mine' => 1]);
        self::assertSame(1, $partial->get(Building::MetalMine));
        self::assertSame(0, $partial->get(Building::CrystalMine));

        $serialized = $this->planet(levels: ['metal_mine' => 1])->toArray();
        unset($serialized['levels']['metal_mine']);
        $this->assertInvalidState($serialized);
    }

    public function testRehydrationRejectsInconsistentCompletedFieldCountAndReservations(): void
    {
        $serialized = $this->planet(levels: ['metal_mine' => 1])->toArray();
        $serialized['fields_used'] = 0;
        $this->assertInvalidState($serialized);

        $serialized = $this->planet()->toArray();
        $serialized['fields_total'] = 0;
        $serialized['pending'] = [ConstructionEntry::active('active', Building::MetalMine, 1, 0, 0, 162)->toArray()];
        $this->assertInvalidState($serialized);
    }

    public function testRehydrationRejectsMalformedQueueListAndEntryShape(): void
    {
        $valid = $this->stateWithActiveQueue()->toArray();

        $assocQueue = $valid;
        $assocQueue['pending'] = [2 => $valid['pending'][0]];
        $this->assertInvalidState($assocQueue);

        $missingEntryField = $valid;
        unset($missingEntryField['pending'][0]['started_at']);
        $this->assertInvalidState($missingEntryField);

        $extraEntryField = $valid;
        $extraEntryField['pending'][0]['unexpected'] = 1;
        $this->assertInvalidState($extraEntryField);
    }

    public function testRehydrationRejectsFutureEnqueueOrStartAndAnActiveHeadPastCompletion(): void
    {
        $valid = $this->stateWithActiveQueue()->toArray();

        $futureEnqueued = $valid;
        $futureEnqueued['pending'][0]['enqueued_at'] = 1;
        $this->assertInvalidState($futureEnqueued);

        $futureStart = $valid;
        $futureStart['pending'][0]['started_at'] = 1;
        $this->assertInvalidState($futureStart);

        $expiredHead = $valid;
        $expiredHead['last_settled_at'] = 163;
        $this->assertInvalidState($expiredHead);
    }

    public function testCompletionTimestampEqualToSettlementIsValid(): void
    {
        $state = $this->stateWithActiveQueue();
        $serialized = $state->toArray();
        $serialized['last_settled_at'] = $serialized['pending'][0]['completes_at'];

        $rehydrated = PlanetEconomyState::fromArray($serialized);
        self::assertSame($serialized, $rehydrated->toArray());
    }

    private function stateWithActiveQueue(): PlanetEconomyState
    {
        return new PlanetEconomyState(
            $this->amounts([]),
            BuildingLevels::fromArray([]),
            40,
            163,
            0,
            0,
            [ConstructionEntry::active('active', Building::MetalMine, 1, 0, 0, 162)],
        );
    }

    private function assertInvalidState(array $serialized): void
    {
        try {
            PlanetEconomyState::fromArray($serialized);
            self::fail('Malformed or inconsistent serialized state was accepted.');
        } catch (EconomyDataException) {
            self::assertTrue(true);
        }
    }
}
