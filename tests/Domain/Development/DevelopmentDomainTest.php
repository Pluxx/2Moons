<?php

declare(strict_types=1);

namespace App\Tests\Domain\Development;

use App\Domain\Development\AccountEngine;
use App\Domain\Development\AccountPlanet;
use App\Domain\Development\AccountState;
use App\Domain\Development\ArrivalInputs;
use App\Domain\Development\Coordinates;
use App\Domain\Development\FleetCalculator;
use App\Domain\Development\FleetState;
use App\Domain\Development\Mission;
use App\Domain\Development\ResearchCalculator;
use App\Domain\Development\ResearchEntry;
use App\Domain\Development\Ship;
use App\Domain\Development\ShipyardBatch;
use App\Domain\Development\ShipyardCalculator;
use App\Domain\Development\Technology;
use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\ConstructionEntry;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\ResourceAmounts;
use PHPUnit\Framework\TestCase;

final class DevelopmentDomainTest extends TestCase
{
    public function testCataloguePricesAndResearchPrerequisiteClosure(): void
    {
        self::assertCount(10, Building::cases());
        self::assertSame(14, Building::RoboticsFactory->legacyId());
        self::assertSame(21, Building::Shipyard->legacyId());
        self::assertSame(31, Building::Laboratory->legacyId());

        $research = new ResearchCalculator();
        $cost = $research->priceFor(Technology::Expedition, 1);
        self::assertSame('7000', (string) $cost->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame('14000', (string) $cost->get(\App\Domain\Economy\Resource::Crystal));
        self::assertSame('7000', (string) $cost->get(\App\Domain\Economy\Resource::Deuterium));
        self::assertSame(18_900, $research->durationSeconds($cost, 3));
        self::assertSame([200, 1000, 200], Technology::Spy->baseCost());
        self::assertFalse($research->meetsPrerequisites(Technology::Combustion, ['energy' => 0], 1));
        self::assertTrue($research->meetsPrerequisites(Technology::Combustion, ['energy' => 1], 1));
        self::assertFalse($research->meetsPrerequisites(Technology::Expedition,
            ['spy' => 3, 'impulse' => 3, 'energy' => 1, 'combustion' => 0], 2));
        self::assertTrue($research->meetsPrerequisites(Technology::Expedition,
            ['spy' => 3, 'impulse' => 3, 'energy' => 1, 'combustion' => 0], 3));
    }

    public function testShipPriceAndBulkUnitDurationAreLinear(): void
    {
        $calculator = new ShipyardCalculator();
        $one = $calculator->priceFor(Ship::SmallCargo, 1);
        $batch = $calculator->priceFor(Ship::SmallCargo, 1000);
        self::assertSame('2000', (string) $one->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame('2000000', (string) $batch->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame(1920, $calculator->unitDurationSeconds(Ship::SmallCargo, 1000, 2));
        self::assertSame(6000, Ship::SmallCargo->speed(2, 0)->toInt());
    }

    public function testCertifiedFleetSanityAndReturnRounding(): void
    {
        $calculator = new FleetCalculator();
        $research = ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0];
        $from = new Coordinates(1, 1, 3);
        $at1005 = $calculator->calculate($from, new Coordinates(1, 1, 4), ['small_cargo' => 1, 'colony_ship' => 0], $research, 10);
        self::assertSame(4540, $at1005['arrival_offset']);
        self::assertSame(9080, $at1005['return_offset']);
        self::assertSame(2, $at1005['fuel']);
        $at1020 = $calculator->calculate($from, new Coordinates(1, 1, 7), ['small_cargo' => 1, 'colony_ship' => 0], $research, 10);
        self::assertSame(4573, $at1020['arrival_offset']);
        self::assertSame(9147, $at1020['return_offset']);
        $this->expectException(\App\Domain\Economy\EconomyDataException::class);
        $calculator->calculate($from, new Coordinates(1, 1, 4), ['small_cargo' => 1], $research, 100);
    }

    public function testAccountStateRoundTripAndClockRegressionDoNotMutate(): void
    {
        $economy = new PlanetEconomyState(ResourceAmounts::fromStrings('500', '500', '0'), BuildingLevels::fromArray([]), 40, 163, 0, 100);
        $planet = new AccountPlanet('12', new Coordinates(1, 1, 3), 100, $economy);
        $state = AccountState::withResearch('owner-1', 100, ['12' => $planet]);
        $roundTrip = AccountState::fromArray($state->toArray());
        self::assertSame($state->toArray(), $roundTrip->toArray());

        $transition = (new AccountEngine(EconomySettings::defaults()))->advance($state, 99);
        self::assertFalse($transition->isAccepted());
        self::assertSame($state->toArray(), $transition->state->toArray());
    }

    public function testReplayedPendingTokenIsRejectedBeforeItsDueBoundaryIsAdvanced(): void
    {
        $entry = \App\Domain\Economy\ConstructionEntry::active('due-token', Building::MetalMine, 1, 0, 0, 10);
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('10000', '10000', '10000'), BuildingLevels::fromArray([]), 40, 163, 0, 0, [$entry]));
        $state = AccountState::withResearch('replay-owner', 0, ['1' => $planet]);
        $before = $state->toArray();
        $result = (new AccountEngine(EconomySettings::defaults()))->enqueueConstruction($state, '1', 2, 1, 'due-token', 10);
        self::assertSame('duplicate_or_empty_token', $result->rejection);
        self::assertSame($before, $result->state->toArray());
        self::assertSame([], $result->outcomes);
    }

    public function testDueResearchShipAndFleetTokenReplayChecksPrecedeSettlement(): void
    {
        $engine = new AccountEngine(EconomySettings::defaults());
        $levels = BuildingLevels::fromArray(['laboratory' => 3, 'shipyard' => 2]);
        $resources = ResourceAmounts::fromStrings('100000', '100000', '100000');
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState($resources, $levels, 40, 163, 5, 0), ['small_cargo' => 2, 'colony_ship' => 1]);
        $researchLevels = ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 1];
        $destination = new AccountPlanet('2', new Coordinates(1, 1, 5), 0,
            new PlanetEconomyState(ResourceAmounts::zero(), BuildingLevels::fromArray([]), 40, 163, 0, 0));
        $state = new AccountState('due-token-owner', 0, $researchLevels, [], ['1' => $planet, '2' => $destination]);

        $research = $engine->enqueueResearch($state, '1', Technology::Spy->legacyId(), 4, 'research-due', 0);
        self::assertTrue($research->isAccepted());
        $researchAt = $research->state->researchQueue[0]->completesAt;
        $before = $research->state->toArray();
        $replayedResearch = $engine->enqueueResearch($research->state, '1', Technology::Spy->legacyId(), 4, 'research-due', $researchAt);
        self::assertSame('duplicate_or_empty_token', $replayedResearch->rejection);
        self::assertSame($before, $replayedResearch->state->toArray());
        self::assertSame([], $replayedResearch->outcomes);

        $batch = $engine->enqueueShips($state, '1', Ship::SmallCargo->legacyId(), 1, 'ship-due', 0);
        self::assertTrue($batch->isAccepted());
        $batchAt = $batch->state->planets['1']->batches[0]->completesAt;
        $before = $batch->state->toArray();
        $replayedBatch = $engine->enqueueShips($batch->state, '1', Ship::SmallCargo->legacyId(), 1, 'ship-due', $batchAt);
        self::assertSame('duplicate_or_empty_token', $replayedBatch->rejection);
        self::assertSame($before, $replayedBatch->state->toArray());
        self::assertSame([], $replayedBatch->outcomes);

        $fleet = $engine->dispatch($state, '1', Mission::Transport, new Coordinates(1, 1, 5), '2',
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'], 10, 'fleet-due', 0);
        self::assertTrue($fleet->isAccepted());
        $fleetAt = $fleet->state->fleets[0]->arrivesAt;
        $before = $fleet->state->toArray();
        $replayedFleet = $engine->dispatch($fleet->state, '1', Mission::Transport, new Coordinates(1, 1, 5), '2',
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'],
            10, 'fleet-due', $fleetAt);
        self::assertSame('duplicate_token_or_unknown_source', $replayedFleet->rejection);
        self::assertSame($before, $replayedFleet->state->toArray());
        self::assertSame([], $replayedFleet->outcomes);
    }

    public function testMultiBatchShipyardFifoSurvivesActivationAndSerializedPartitioning(): void
    {
        foreach ([3, 10] as $count) {
            [$bulk, $bulkOutcomes] = $this->runSerializedShipBatchQueue($count, false);
            [$split, $splitOutcomes] = $this->runSerializedShipBatchQueue($count, true);
            self::assertSame($bulk->toArray(), $split->toArray());
            self::assertSame($bulkOutcomes, $splitOutcomes);
            self::assertSame($count, $bulk->planets['1']->ships[Ship::SmallCargo->value]);
            self::assertSame([], $bulk->planets['1']->batches);
            self::assertCount($count, $bulkOutcomes);
            foreach ($bulkOutcomes as $index => $outcome) {
                self::assertSame('batch-'.($index + 1), $outcome['token']);
                self::assertSame(($index) * 1152, $outcome['started_at']);
                self::assertSame(($index + 1) * 1152, $outcome['completes_at']);
                self::assertSame(($index + 1) * 1152, $outcome['resolved_at']);
            }
            self::assertSame((string) (100000 - 2000 * $count), (string) $bulk->planets['1']->economy->resources->get(\App\Domain\Economy\Resource::Metal));
            self::assertSame((string) (100000 - 2000 * $count), (string) $bulk->planets['1']->economy->resources->get(\App\Domain\Economy\Resource::Crystal));
        }
    }

    public function testFleetSerializationRejectsFractionalCurrentAndLaunchCargoAndEmptyTransportPayload(): void
    {
        $levels = BuildingLevels::fromArray([]);
        $source = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 0, 0),
            ['small_cargo' => 1, 'colony_ship' => 0]);
        $destination = new AccountPlanet('2', new Coordinates(1, 1, 4), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 0, 0));
        $state = new AccountState('fractional-fleet', 0,
            ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0], [], ['1' => $source, '2' => $destination]);
        $dispatched = (new AccountEngine(EconomySettings::defaults()))->dispatch($state, '1', Mission::Transport,
            $destination->coordinates, '2', ['small_cargo' => 1, 'colony_ship' => 0],
            ['metal' => '100', 'crystal' => '100', 'deuterium' => '100'], 10, 'whole-cargo', 0);
        self::assertTrue($dispatched->isAccepted());
        $original = $dispatched->state->toArray();

        $fractionalPair = $original;
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $fractionalPair['fleets'][0]['cargo'][$resource] = '1/2';
            $fractionalPair['fleets'][0]['launch_cargo'][$resource] = '1/2';
        }
        $this->assertFleetSerializationRejectedForWholeCargo($fractionalPair);

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $fractionalCurrent = $original;
            $fractionalCurrent['fleets'][0]['cargo'][$resource] = '199/2';
            $this->assertFleetSerializationRejectedForWholeCargo($fractionalCurrent);

            $fractionalLaunch = $original;
            $fractionalLaunch['fleets'][0]['launch_cargo'][$resource] = '201/2';
            $this->assertFleetSerializationRejectedForWholeCargo($fractionalLaunch);
        }
        $emptyTransport = $original;
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $emptyTransport['fleets'][0]['cargo'][$resource] = '0/1';
            $emptyTransport['fleets'][0]['launch_cargo'][$resource] = '0/1';
        }
        try {
            AccountState::fromArray($emptyTransport);
            self::fail('A transport fleet must preserve a positive launch cargo payload.');
        } catch (\App\Domain\Economy\EconomyDataException $error) {
            self::assertSame('Transport fleet launch cargo must be positive.', $error->getMessage());
        }

        // Zero-cargo colonization remains valid through the existing real dispatch/arrival regression.
        $colonyInputs = new ArrivalInputs([], ['empty-colony' => ['temperature_max' => 85, 'fields_total' => 160]]);
        $colonySource = $source->evolve(ships: ['small_cargo' => 0, 'colony_ship' => 1]);
        $colonyState = new AccountState('empty-colony', 0,
            ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 1], [], ['1' => $colonySource]);
        $colony = (new AccountEngine(EconomySettings::defaults()))->dispatch($colonyState, '1', Mission::Colonize,
            new Coordinates(1, 1, 4), null, ['small_cargo' => 0, 'colony_ship' => 1],
            ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'], 10, 'empty-colony', 0, $colonyInputs);
        self::assertTrue($colony->isAccepted());
        self::assertSame('0/1', $colony->state->fleets[0]->launchCargo->toCanonicalArray()['metal']);
    }

    public function testSerializedAggregateRehydrationRejectsOmittedResearchShipAndFacilityKeys(): void
    {
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::zero(), BuildingLevels::fromArray([]), 40, 163, 0, 0));
        $state = AccountState::withResearch('strict-owner', 0, ['1' => $planet]);

        $missingResearch = $state->toArray();
        unset($missingResearch['research'][Technology::Spy->value]);
        $this->assertRejectedSerializedState($missingResearch);

        $missingShip = $state->toArray();
        unset($missingShip['planets']['1']['ships'][Ship::SmallCargo->value]);
        $this->assertRejectedSerializedState($missingShip);

        $missingFacility = $state->toArray();
        unset($missingFacility['planets']['1']['economy']['levels'][Building::Laboratory->value]);
        $this->assertRejectedSerializedState($missingFacility);
    }

    public function testAccountResearchAndRoboticsCompleteOnScheduledBoundaries(): void
    {
        $settings = EconomySettings::defaults();
        $levels = BuildingLevels::fromArray(['laboratory' => 3]);
        $economy = new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 3, 0);
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0, $economy);
        $fundingPlanet = new AccountPlanet('2', new Coordinates(1, 1, 5), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 3, 0));
        $base = AccountState::withResearch('owner-1', 0, ['1' => $planet, '2' => $fundingPlanet]);
        $engine = new AccountEngine($settings);

        $research = $engine->enqueueResearch($base, '1', 106, 1, 'spy-1', 0);
        self::assertTrue($research->isAccepted());
        $research = $engine->enqueueResearch($research->state, '2', 106, 2, 'spy-2', 0);
        self::assertTrue($research->isAccepted());
        $researchCompletion = $research->state->researchQueue[0]->completesAt;
        $completedResearch = $engine->advance($research->state, $researchCompletion);
        self::assertTrue($completedResearch->isAccepted());
        self::assertSame(1, $completedResearch->state->research[Technology::Spy->value]);
        self::assertSame($researchCompletion, $completedResearch->state->researchQueue[0]->startedAt);
        self::assertSame(2, $completedResearch->state->researchQueue[0]->targetLevel);
        self::assertSame('99200', (string) $completedResearch->state->planets['2']->economy->resources->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame($researchCompletion, $completedResearch->state->settledAt);

        $zeroLevels = BuildingLevels::fromArray([]);
        $richPlanet = new AccountPlanet('2', new Coordinates(1, 1, 5), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('10000', '10000', '10000'), $zeroLevels, 40, 163, 0, 0));
        $constructionState = AccountState::withResearch('owner-2', 0, ['2' => $richPlanet]);
        $construction = $engine->enqueueConstruction($constructionState, '2', 14, 1, 'robot-1', 0);
        self::assertTrue($construction->isAccepted());
        $completion = $construction->state->planets['2']->economy->pendingEntries[0]->completesAt;
        $settled = $engine->advance($construction->state, $completion);
        self::assertTrue($settled->isAccepted());
        self::assertSame(1, $settled->state->planets['2']->economy->levels->get(Building::RoboticsFactory));
        self::assertCount(1, $settled->outcomes);
        self::assertSame($completion, $settled->outcomes[0]['completes_at']);

        $waitingState = AccountState::withResearch('owner-3', 0, ['2' => $richPlanet]);
        $robotQueue = $engine->enqueueConstruction($waitingState, '2', 14, 1, 'robot-first', 0);
        self::assertTrue($robotQueue->isAccepted());
        $mineQueue = $engine->enqueueConstruction($robotQueue->state, '2', 1, 1, 'mine-waits', 0);
        self::assertTrue($mineQueue->isAccepted());
        $robotCompletes = $mineQueue->state->planets['2']->economy->pendingEntries[0]->completesAt;
        $activated = $engine->advance($mineQueue->state, $robotCompletes);
        self::assertTrue($activated->isAccepted());
        $mine = $activated->state->planets['2']->economy->pendingEntries[0];
        self::assertSame($robotCompletes, $mine->startedAt);
        self::assertSame($robotCompletes + 81, $mine->completesAt);
    }

    public function testMillionShipBatchMaterializesInBulkAtPartialBoundary(): void
    {
        $levels = BuildingLevels::fromArray(['shipyard' => 2]);
        $planet = new AccountPlanet('7', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('3000000000', '3000000000', '1000'), $levels, 40, 163, 2, 0));
        $research = ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0];
        $state = new AccountState('owner-7', 0, $research, [], ['7' => $planet]);
        $engine = new AccountEngine(EconomySettings::defaults());
        $batch = $engine->enqueueShips($state, '7', 202, 1_000_000, 'cargo-batch', 0);
        self::assertTrue($batch->isAccepted());
        $unitSeconds = $batch->state->planets['7']->batches[0]->unitSeconds;
        $partial = $engine->advance($batch->state, intdiv($unitSeconds * 1_000_000, 2));
        self::assertTrue($partial->isAccepted());
        self::assertSame(500_000, $partial->state->planets['7']->ships[Ship::SmallCargo->value]);
        self::assertSame(500_000, $partial->state->planets['7']->batches[0]->produced);
    }

    public function testColonyBirthUsesArrivalBoundaryAndConsumesSoleColonyShip(): void
    {
        $levels = BuildingLevels::fromArray(['shipyard' => 4]);
        $planet = new AccountPlanet('9', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 4, 0),
            ['small_cargo' => 0, 'colony_ship' => 1]);
        $research = ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 1];
        $state = new AccountState('owner-9', 0, $research, [], ['9' => $planet]);
        $inputs = new ArrivalInputs([], ['colony-flight' => ['temperature_max' => 85, 'fields_total' => 160]]);
        $engine = new AccountEngine(EconomySettings::defaults());
        $dispatch = $engine->dispatch($state, '9', Mission::Colonize, new Coordinates(1, 1, 4), null,
            ['small_cargo' => 0, 'colony_ship' => 1], ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'], 10,
            'colony-flight', 0, $inputs);
        self::assertTrue($dispatch->isAccepted());
        $arrival = $dispatch->state->fleets[0]->arrivesAt;
        $finalAt = $arrival + 3600;
        $bulk = $engine->advance($dispatch->state, $finalAt, $inputs);
        $resolvedAtArrival = $engine->advance($dispatch->state, $arrival, $inputs);
        self::assertTrue($resolvedAtArrival->isAccepted());
        $partitioned = $engine->advance(AccountState::fromArray($resolvedAtArrival->state->toArray()), $finalAt, $inputs);
        $resolved = $bulk;
        self::assertTrue($resolved->isAccepted(), json_encode([$resolved->rejection, $resolved->context], JSON_THROW_ON_ERROR));
        self::assertTrue($partitioned->isAccepted());
        self::assertSame($bulk->state->toArray(), $partitioned->state->toArray());
        self::assertArrayHasKey('colony:colony-flight', $resolved->state->planets);
        $colony = $resolved->state->planets['colony:colony-flight'];
        self::assertSame($arrival, $colony->bornAt);
        self::assertSame($finalAt, $colony->economy->lastSettledAt);
        self::assertSame(85, $colony->economy->temperatureMax);
        self::assertSame(160, $colony->economy->fieldsTotal);
        self::assertSame('colonized', $resolved->state->fleets[0]->outcome);
        self::assertSame('complete', $resolved->state->fleets[0]->status);
        self::assertSame($arrival, $resolved->state->fleets[0]->arrivalResolvedAt);
        self::assertSame($arrival, $resolved->state->fleets[0]->resolvedAt);
        self::assertSame($resolved->state->toArray(), AccountState::fromArray($resolved->state->toArray())->toArray());
        self::assertSame(['small_cargo' => 0, 'colony_ship' => 1], $resolved->state->fleets[0]->launchShips);
        self::assertSame('0', (string) $resolved->state->fleets[0]->launchCargo->get(\App\Domain\Economy\Resource::Metal));
    }

    public function testMixedColonyFleetRetainsLaunchPayloadAfterColonizationAndReturn(): void
    {
        $levels = BuildingLevels::fromArray(['laboratory' => 3]);
        $source = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 3, 0),
            ['small_cargo' => 1, 'colony_ship' => 1]);
        $state = new AccountState('mixed-colony', 0,
            ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 1], [], ['1' => $source]);
        $inputs = new ArrivalInputs([], ['mixed-token' => ['temperature_max' => 85, 'fields_total' => 160]]);
        $engine = new AccountEngine(EconomySettings::defaults());
        $dispatch = $engine->dispatch($state, '1', Mission::Colonize, new Coordinates(1, 1, 4), null,
            ['small_cargo' => 1, 'colony_ship' => 1], ['metal' => '50', 'crystal' => '0', 'deuterium' => '0'],
            10, 'mixed-token', 0, $inputs);
        self::assertTrue($dispatch->isAccepted());
        $launchArray = $dispatch->state->fleets[0]->toArray();
        self::assertSame(['small_cargo' => 1, 'colony_ship' => 1], $dispatch->state->fleets[0]->launchShips);
        self::assertSame('50', (string) $dispatch->state->fleets[0]->launchCargo->get(\App\Domain\Economy\Resource::Metal));
        $arrived = $engine->advance($dispatch->state, $dispatch->state->fleets[0]->arrivesAt, $inputs);
        self::assertTrue($arrived->isAccepted(), json_encode([$arrived->rejection, $arrived->context], JSON_THROW_ON_ERROR));
        self::assertSame(['small_cargo' => 1, 'colony_ship' => 0], $arrived->state->fleets[0]->ships);
        self::assertSame($launchArray['launch_ships'], $arrived->state->fleets[0]->toArray()['launch_ships']);
        self::assertSame($launchArray['launch_cargo'], $arrived->state->fleets[0]->toArray()['launch_cargo']);
        $returned = $engine->advance($arrived->state, $arrived->state->fleets[0]->returnsAt, $inputs);
        self::assertTrue($returned->isAccepted(), json_encode([$returned->rejection, $returned->context], JSON_THROW_ON_ERROR));
        self::assertSame(['small_cargo' => 1, 'colony_ship' => 1], $returned->state->fleets[0]->launchShips);
        self::assertSame('50', (string) $returned->state->fleets[0]->launchCargo->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame(['small_cargo' => 0, 'colony_ship' => 0], $returned->state->fleets[0]->ships);
        self::assertSame($returned->state->fleets[0]->toArray(), FleetState::fromArray($returned->state->fleets[0]->toArray())->toArray());
    }

    public function testIneligibleColonyDispatchWarnsThenReturnsItsShipWithoutCreatingAPlanet(): void
    {
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('10000', '10000', '10000'), BuildingLevels::fromArray([]), 40, 163, 0, 0),
            ['small_cargo' => 0, 'colony_ship' => 1]);
        $state = AccountState::withResearch('owner-1', 0, ['1' => $planet]);
        $engine = new AccountEngine(EconomySettings::defaults());
        $dispatch = $engine->dispatch($state, '1', Mission::Colonize, new Coordinates(1, 1, 4), null,
            ['small_cargo' => 0, 'colony_ship' => 1], ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'], 10, 'early-colony', 0);
        self::assertTrue($dispatch->isAccepted());
        self::assertSame('colony_eligibility_not_met_at_dispatch', $dispatch->outcomes[0]['code']);
        $fleet = $dispatch->state->fleets[0];
        $arrived = $engine->advance($dispatch->state, $fleet->arrivesAt);
        self::assertTrue($arrived->isAccepted());
        self::assertCount(1, $arrived->state->planets);
        self::assertSame('insufficient_expedition_technology', $arrived->state->fleets[0]->outcome);
        $returned = $engine->advance($arrived->state, $fleet->returnsAt);
        self::assertTrue($returned->isAccepted(), json_encode([$returned->rejection, $returned->context], JSON_THROW_ON_ERROR));
        self::assertSame(1, $returned->state->planets['1']->ships[Ship::ColonyShip->value]);
        self::assertSame($fleet->returnsAt, $returned->state->fleets[0]->resolvedAt);
    }

    public function testTransportDeliversCargoOnceAndReturnsShips(): void
    {
        $source = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), BuildingLevels::fromArray([]), 40, 163, 0, 0),
            ['small_cargo' => 1, 'colony_ship' => 0]);
        $destination = new AccountPlanet('2', new Coordinates(1, 1, 4), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), BuildingLevels::fromArray([]), 40, 163, 0, 0));
        $state = AccountState::withResearch('owner-1', 0, ['1' => $source, '2' => $destination]);
        $state = $state->evolve(research: ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0]);
        $engine = new AccountEngine(EconomySettings::defaults());
        $dispatch = $engine->dispatch($state, '1', Mission::Transport, new Coordinates(1, 1, 4), '2',
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '100', 'crystal' => '0', 'deuterium' => '0'], 10, 'transport-1', 0);
        self::assertTrue($dispatch->isAccepted());
        $fleet = $dispatch->state->fleets[0];
        $returned = $engine->advance($dispatch->state, $fleet->returnsAt);
        self::assertTrue($returned->isAccepted(), json_encode([$returned->rejection, $returned->context], JSON_THROW_ON_ERROR));
        self::assertSame('100100', (string) $returned->state->planets['2']->economy->resources->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame(1, $returned->state->planets['1']->ships[Ship::SmallCargo->value]);
        self::assertSame('0', (string) $returned->state->fleets[0]->cargo->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame($fleet->arrivesAt, $returned->state->fleets[0]->arrivalResolvedAt);
        self::assertSame($fleet->returnsAt, $returned->state->fleets[0]->resolvedAt);
        self::assertSame(['small_cargo' => 1, 'colony_ship' => 0], $fleet->launchShips);
        self::assertSame('100', (string) $fleet->launchCargo->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame(['small_cargo' => 1, 'colony_ship' => 0], $returned->state->fleets[0]->launchShips);
        self::assertSame('100', (string) $returned->state->fleets[0]->launchCargo->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame($returned->state->fleets[0]->toArray(), FleetState::fromArray($returned->state->fleets[0]->toArray())->toArray());
    }

    private function assertRejectedSerializedState(array $serialized): void
    {
        try {
            AccountState::fromArray($serialized);
            self::fail('Malformed serialized account state must not be default-filled.');
        } catch (\App\Domain\Economy\EconomyDataException) {
            self::assertTrue(true);
        }
    }

    public function testSameBoundaryDeliveryStartsResearchBeforeConstructionAndMaterializesBatch(): void
    {
        $researchLevels = ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 0];
        $flight = (new FleetCalculator())->calculate(new Coordinates(1, 1, 3), new Coordinates(1, 1, 4),
            ['small_cargo' => 5, 'colony_ship' => 0], $researchLevels, 10);
        $boundary = $flight['arrival_offset'];

        $researchCalculator = new ResearchCalculator();
        $expeditionCost = $researchCalculator->priceFor(Technology::Expedition, 1);
        $levels = BuildingLevels::fromArray(['laboratory' => 3]);
        $activeResearch = new ResearchEntry('exp-active', Technology::Expedition, 1, '1', 0, 0, $boundary, $expeditionCost, 'active');
        $waitingResearch = new ResearchEntry('spy-next', Technology::Spy, 4, '1', 0);
        $constructionQueue = [
            ConstructionEntry::active('mine-due', Building::MetalMine, 1, 0, 0, $boundary),
            ConstructionEntry::waiting('robot-after', Building::RoboticsFactory, 1, 0),
        ];
        $fundedPlanet = new AccountPlanet('1', new Coordinates(1, 1, 4), 0,
            new PlanetEconomyState(ResourceAmounts::zero(), $levels, 40, 163, 3, 0, $constructionQueue));
        $batch = new ShipyardBatch('batch-due', Ship::SmallCargo, 1,
            (new ShipyardCalculator())->priceFor(Ship::SmallCargo, 1), 0, 0, $boundary, 0, $boundary, null, 'active');
        $fleetOrigin = new AccountPlanet('2', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'),
                BuildingLevels::fromArray(['shipyard' => 2]), 40, 163, 2, 0),
            ['small_cargo' => 6, 'colony_ship' => 0], [$batch]);
        $state = new AccountState('boundary-owner', 0, $researchLevels, [$activeResearch, $waitingResearch],
            ['1' => $fundedPlanet, '2' => $fleetOrigin]);

        $engine = new AccountEngine(EconomySettings::defaults());
        $dispatch = $engine->dispatch($state, '2', Mission::Transport, new Coordinates(1, 1, 4), '1',
            ['small_cargo' => 5, 'colony_ship' => 0],
            ['metal' => '3200', 'crystal' => '16000', 'deuterium' => '3200'], 10, 'delivery-at-boundary', 0);
        self::assertTrue($dispatch->isAccepted());
        self::assertSame($boundary, $dispatch->state->fleets[0]->arrivesAt);

        $settled = $engine->advance($dispatch->state, $boundary);
        self::assertTrue($settled->isAccepted(), json_encode([$settled->rejection, $settled->context], JSON_THROW_ON_ERROR));
        self::assertSame(1, $settled->state->research[Technology::Expedition->value]);
        self::assertSame(4, $settled->state->researchQueue[0]->targetLevel);
        self::assertSame($boundary, $settled->state->researchQueue[0]->startedAt);
        self::assertSame(0, $settled->state->planets['1']->economy->resources->get(\App\Domain\Economy\Resource::Deuterium)->toInt());
        self::assertSame(0, $settled->state->planets['1']->economy->levels->get(Building::RoboticsFactory));
        $constructionFailures = array_values(array_filter($settled->outcomes, static fn (array $outcome): bool =>
            ($outcome['type'] ?? null) === 'construction' && ($outcome['token'] ?? $outcome['command_token'] ?? null) === 'robot-after'));
        self::assertCount(1, $constructionFailures);
        self::assertSame('failed', $constructionFailures[0]['status']);
        self::assertSame($boundary, $constructionFailures[0]['resolved_at']);
        self::assertSame(2, $settled->state->planets['2']->ships[Ship::SmallCargo->value]);
        self::assertSame('returning', $settled->state->fleets[0]->status);
        self::assertSame($boundary, $settled->state->fleets[0]->arrivalResolvedAt);

        $beforeRejectedDispatch = $settled->state->toArray();
        $slotResult = $engine->dispatch($settled->state, '2', Mission::Transport, new Coordinates(1, 1, 4), '1',
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'],
            10, 'return-slot-stays-busy', $boundary);
        self::assertSame('fleet_slot_unavailable', $slotResult->rejection);
        self::assertSame($beforeRejectedDispatch, $slotResult->state->toArray());
        self::assertSame([], $slotResult->outcomes);
    }

    public function testAllAdmissionsForwardValidatedArrivalInputsAndKeepRejectionsAtomic(): void
    {
        $engine = new AccountEngine(EconomySettings::defaults());
        foreach (['construction', 'research', 'ships'] as $command) {
            foreach ([false, true] as $occupied) {
                [$state, $inputs, $arrival] = $this->eligibleColonyAtArrival('arrival-'.$command.'-'.(int) $occupied, $occupied);
                $result = match ($command) {
                    'construction' => $engine->enqueueConstruction($state, '1', Building::MetalMine->legacyId(), 1,
                        'new-'.$command, $arrival, $inputs),
                    'research' => $engine->enqueueResearch($state, '1', Technology::Spy->legacyId(), 4,
                        'new-'.$command, $arrival, $inputs),
                    default => $engine->enqueueShips($state, '1', Ship::SmallCargo->legacyId(), 1,
                        'new-'.$command, $arrival, $inputs),
                };
                self::assertTrue($result->isAccepted(), json_encode([$command, $occupied, $result->rejection, $result->context], JSON_THROW_ON_ERROR));
                self::assertNotEmpty(array_filter($result->outcomes, static fn (array $row): bool =>
                    ($row['type'] ?? null) === 'fleet' && ($row['status'] ?? null) === ($occupied ? 'coordinate_occupied' : 'colonized')));
                self::assertSame($occupied ? 2 : 3, count($result->state->planets));
            }
        }

        [$poor, $inputs, $arrival] = $this->eligibleColonyAtArrival('arrival-poor', false, true);
        $before = $poor->toArray();
        $unaffordable = $engine->enqueueConstruction($poor, '1', Building::MetalMine->legacyId(), 1, 'poor-build', $arrival, $inputs);
        self::assertSame('unaffordable', $unaffordable->rejection);
        self::assertSame($before, $unaffordable->state->toArray());
        self::assertSame([], $unaffordable->outcomes);

        [$stale, $inputs, $arrival] = $this->eligibleColonyAtArrival('arrival-stale', false);
        $before = $stale->toArray();
        $staleResearch = $engine->enqueueResearch($stale, '1', Technology::Spy->legacyId(), 3, 'stale-spy', $arrival, $inputs);
        self::assertSame('stale_research_target', $staleResearch->rejection);
        self::assertSame($before, $staleResearch->state->toArray());
        self::assertSame([], $staleResearch->outcomes);

        [$slotState, $inputs, $arrival] = $this->eligibleColonyAtArrival('arrival-slot', true);
        $before = $slotState->toArray();
        $slot = $engine->dispatch($slotState, '1', Mission::Transport, new Coordinates(1, 1, 5), '2',
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'],
            10, 'other-flight', $arrival, $inputs);
        self::assertSame('fleet_slot_unavailable', $slot->rejection);
        self::assertSame($before, $slot->state->toArray());
        self::assertSame([], $slot->outcomes);

        [$missing, , $arrival] = $this->eligibleColonyAtArrival('arrival-no-draw', false);
        $before = $missing->toArray();
        $missingDraw = new ArrivalInputs();
        $safeReject = $engine->enqueueConstruction($missing, '1', Building::MetalMine->legacyId(), 1, 'no-draw', $arrival, $missingDraw);
        self::assertSame('invalid_account_transition', $safeReject->rejection);
        self::assertSame($before, $safeReject->state->toArray());
        self::assertSame([], $safeReject->outcomes);
    }

    public function testQueuedResearchAndShipBatchesRejectProjectedOverflowBeforeCharging(): void
    {
        $engine = new AccountEngine(EconomySettings::defaults());
        $lab = BuildingLevels::fromArray(['laboratory' => 3]);
        $funded = ResourceAmounts::fromStrings('100000', '100000', '100000');
        $researchPlanet = new AccountPlanet('r', new Coordinates(1, 1, 3), PHP_INT_MAX - 3000,
            new PlanetEconomyState($funded, $lab, 40, 163, 3, PHP_INT_MAX - 3000));
        $researchState = AccountState::withResearch('overflow-research', PHP_INT_MAX - 3000, ['r' => $researchPlanet]);
        $first = $engine->enqueueResearch($researchState, 'r', Technology::Energy->legacyId(), 1, 'energy-max', PHP_INT_MAX - 3000);
        self::assertTrue($first->isAccepted());
        $before = $first->state->toArray();
        $second = $engine->enqueueResearch($first->state, 'r', Technology::Spy->legacyId(), 1, 'spy-overflow', PHP_INT_MAX - 3000);
        self::assertSame('timestamp_range', $second->rejection);
        self::assertSame($before, $second->state->toArray());
        self::assertSame([], $second->outcomes);

        $yard = BuildingLevels::fromArray(['shipyard' => 2]);
        $shipPlanet = new AccountPlanet('s', new Coordinates(1, 1, 3), PHP_INT_MAX - 2000,
            new PlanetEconomyState($funded, $yard, 40, 163, 2, PHP_INT_MAX - 2000));
        $shipState = new AccountState('overflow-ships', PHP_INT_MAX - 2000,
            ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0], [], ['s' => $shipPlanet]);
        $firstBatch = $engine->enqueueShips($shipState, 's', Ship::SmallCargo->legacyId(), 1, 'cargo-first', PHP_INT_MAX - 2000);
        self::assertTrue($firstBatch->isAccepted());
        self::assertSame('98000', (string) $firstBatch->state->planets['s']->economy->resources->get(\App\Domain\Economy\Resource::Metal));
        $before = $firstBatch->state->toArray();
        $secondBatch = $engine->enqueueShips($firstBatch->state, 's', Ship::SmallCargo->legacyId(), 1, 'cargo-overflow', PHP_INT_MAX - 2000);
        self::assertSame('timestamp_range', $secondBatch->rejection);
        self::assertSame($before, $secondBatch->state->toArray());
        self::assertSame([], $secondBatch->outcomes);

        $adjacentResearchPlanet = new AccountPlanet('r', new Coordinates(1, 1, 3), PHP_INT_MAX - 3600,
            new PlanetEconomyState($funded, $lab, 40, 163, 3, PHP_INT_MAX - 3600));
        $adjacentResearch = AccountState::withResearch('adjacent-research', PHP_INT_MAX - 3600, ['r' => $adjacentResearchPlanet]);
        $energy = $engine->enqueueResearch($adjacentResearch, 'r', Technology::Energy->legacyId(), 1, 'energy-last', PHP_INT_MAX - 3600);
        $spy = $engine->enqueueResearch($energy->state, 'r', Technology::Spy->legacyId(), 1, 'spy-last', PHP_INT_MAX - 3600);
        self::assertTrue($spy->isAccepted());
        self::assertNull($spy->state->researchQueue[1]->completesAt);
        self::assertLessThanOrEqual(PHP_INT_MAX, $spy->state->researchQueue[0]->completesAt);
        $settledResearch = $engine->advance($spy->state, PHP_INT_MAX);
        self::assertTrue($settledResearch->isAccepted(), json_encode([$settledResearch->rejection, $settledResearch->context], JSON_THROW_ON_ERROR));
        self::assertSame(1, $settledResearch->state->research[Technology::Spy->value]);

        $shipUnit = (new ShipyardCalculator())->unitDurationSeconds(Ship::SmallCargo, 1, 2);
        $shipStart = PHP_INT_MAX - 2 * $shipUnit;
        $adjacentShipPlanet = new AccountPlanet('s', new Coordinates(1, 1, 3), $shipStart,
            new PlanetEconomyState($funded, $yard, 40, 163, 2, $shipStart));
        $adjacentShips = new AccountState('adjacent-ships', $shipStart,
            ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0], [], ['s' => $adjacentShipPlanet]);
        $batchOne = $engine->enqueueShips($adjacentShips, 's', Ship::SmallCargo->legacyId(), 1, 'cargo-last-one', $shipStart);
        $batchTwo = $engine->enqueueShips($batchOne->state, 's', Ship::SmallCargo->legacyId(), 1, 'cargo-last-two', $shipStart);
        self::assertTrue($batchTwo->isAccepted());
        $settledShips = $engine->advance($batchTwo->state, PHP_INT_MAX);
        self::assertTrue($settledShips->isAccepted(), json_encode([$settledShips->rejection, $settledShips->context], JSON_THROW_ON_ERROR));
        self::assertSame(2, $settledShips->state->planets['s']->ships[Ship::SmallCargo->value]);
    }

    public function testShipyardFourRequiresRoboticsButNotImpulseWhileColonyShipStillNeedsImpulse(): void
    {
        $engine = new AccountEngine(EconomySettings::defaults());
        $levels = BuildingLevels::fromArray(['robotics_factory' => 2, 'shipyard' => 3]);
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 5, 0));
        $research = ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0];
        $state = new AccountState('yard-rule', 0, $research, [], ['1' => $planet]);
        $yard = $engine->enqueueConstruction($state, '1', Building::Shipyard->legacyId(), 4, 'yard-four', 0);
        self::assertTrue($yard->isAccepted());

        $colonyYard = new AccountPlanet('2', new Coordinates(1, 1, 4), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), BuildingLevels::fromArray(['robotics_factory' => 2, 'shipyard' => 4]), 40, 163, 6, 0));
        $denied = $engine->enqueueShips(new AccountState('colony-drive', 0, $research, [], ['2' => $colonyYard]),
            '2', Ship::ColonyShip->legacyId(), 1, 'no-colony-drive', 0);
        self::assertSame('ship_technology_prerequisite', $denied->rejection);
        $withDrive = $research;
        $withDrive['impulse'] = 3;
        $allowed = $engine->enqueueShips(new AccountState('colony-drive-ok', 0, $withDrive, [], ['2' => $colonyYard]),
            '2', Ship::ColonyShip->legacyId(), 1, 'colony-drive-ok', 0);
        self::assertTrue($allowed->isAccepted());
    }

    public function testPublishedAggregateRejectsDuplicateEventsAndUnearnedBatchProgress(): void
    {
        [$state, $inputs] = $this->eligibleColonyAtArrival('duplicate-fleet', false);
        $duplicate = $state->toArray();
        $duplicate['fleets'][] = $duplicate['fleets'][0];
        $this->assertRejectedSerializedState($duplicate);
        $secondActive = $state->toArray();
        $secondActive['fleets'][0]['token'] = 'active-two';
        $secondActive['fleets'][0]['launch_ships'] = ['small_cargo' => 1, 'colony_ship' => 0];
        $secondActive['fleets'][0]['ships'] = ['small_cargo' => 1, 'colony_ship' => 0];
        $secondActive['fleets'][] = $secondActive['fleets'][0];
        $secondActive['fleets'][1]['token'] = 'active-three';
        $this->assertRejectedSerializedState($secondActive);

        $levels = BuildingLevels::fromArray(['shipyard' => 2]);
        $premature = new ShipyardBatch('premature', Ship::SmallCargo, 10, ResourceAmounts::zero(), 0, 0, 1152, 5, 11520, null, 'active');
        try {
            $base = AccountState::withResearch('premature-batch', 0, ['p' => new AccountPlanet('p', new Coordinates(1, 1, 3), 0,
                new PlanetEconomyState(ResourceAmounts::zero(), $levels, 40, 163, 2, 0))])->toArray();
            $base['planets']['p']['ships'] = ['small_cargo' => 5, 'colony_ship' => 0];
            $base['planets']['p']['batches'] = [$premature->toArray()];
            $this->assertRejectedSerializedState($base);
        } catch (\App\Domain\Economy\EconomyDataException) { self::assertTrue(true); }

        $lab = BuildingLevels::fromArray(['laboratory' => 3]);
        $source = new AccountPlanet('r', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $lab, 40, 163, 3, 0));
        $other = new AccountPlanet('s', new Coordinates(1, 1, 4), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $lab, 40, 163, 3, 0));
        $queueState = AccountState::withResearch('duplicate-research', 0, ['r' => $source, 's' => $other]);
        $queued = (new AccountEngine(EconomySettings::defaults()))->enqueueResearch($queueState, 'r', Technology::Spy->legacyId(), 1, 'r1', 0);
        $queued = (new AccountEngine(EconomySettings::defaults()))->enqueueResearch($queued->state, 's', Technology::Spy->legacyId(), 2, 'r2', 0);
        self::assertTrue($queued->isAccepted());
        $duplicateResearch = $queued->state->toArray();
        $duplicateResearch['research_queue'][1]['token'] = $duplicateResearch['research_queue'][0]['token'];
        $this->assertRejectedSerializedState($duplicateResearch);
    }

    public function testPendingTokensAreScopedByApprovedCommandNamespace(): void
    {
        $engine = new AccountEngine(EconomySettings::defaults());
        $levels = BuildingLevels::fromArray(['laboratory' => 3, 'robotics_factory' => 2, 'shipyard' => 2]);
        $resources = ResourceAmounts::fromStrings('100000', '100000', '100000');
        $one = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState($resources, $levels, 40, 163, 7, 0), ['small_cargo' => 2, 'colony_ship' => 1]);
        $two = new AccountPlanet('2', new Coordinates(1, 1, 5), 0, new PlanetEconomyState($resources, $levels, 40, 163, 7, 0));
        $research = ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 1];
        $state = new AccountState('namespace-owner', 0, $research, [], ['1' => $one, '2' => $two]);
        $construction1 = $engine->enqueueConstruction($state, '1', Building::MetalMine->legacyId(), 1, 'shared', 0);
        $this->assertTrue($construction1->isAccepted());
        $construction2 = $engine->enqueueConstruction($construction1->state, '2', Building::MetalMine->legacyId(), 1, 'shared', 0);
        self::assertTrue($construction2->isAccepted());
        $researchResult = $engine->enqueueResearch($construction2->state, '1', Technology::Spy->legacyId(), 4, 'shared', 0);
        self::assertTrue($researchResult->isAccepted());
        $shipyardResult = $engine->enqueueShips($researchResult->state, '1', Ship::SmallCargo->legacyId(), 1, 'shared', 0);
        self::assertTrue($shipyardResult->isAccepted());
        $fleet = $engine->dispatch($shipyardResult->state, '1', Mission::Transport, new Coordinates(1, 1, 5), '2',
            ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'], 10, 'shared', 0);
        self::assertTrue($fleet->isAccepted());
        $before = $fleet->state->toArray();
        $collision = $engine->dispatch($fleet->state, '1', Mission::Colonize, new Coordinates(1, 1, 6), null,
            ['small_cargo' => 0, 'colony_ship' => 1], ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'],
            10, 'shared', 0);
        self::assertSame('duplicate_token_or_unknown_source', $collision->rejection);
        self::assertSame($before, $collision->state->toArray());
        self::assertSame([], $collision->outcomes);
    }

    /** @return array{AccountState,ArrivalInputs,int} */
    private function eligibleColonyAtArrival(string $token, bool $occupied, bool $poor = false): array
    {
        $resources = $poor ? ResourceAmounts::fromStrings('0', '0', '100000') : ResourceAmounts::fromStrings('100000', '100000', '100000');
        $levels = BuildingLevels::fromArray(['laboratory' => 3, 'robotics_factory' => 2, 'shipyard' => 2]);
        $source = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState($resources, $levels, 40, 163, 7, 0), ['small_cargo' => 1, 'colony_ship' => 1]);
        $targetPlanet = new AccountPlanet('2', new Coordinates(1, 1, 5), 0,
            new PlanetEconomyState(ResourceAmounts::zero(), BuildingLevels::fromArray([]), 40, 163, 0, 0));
        $research = ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => 1];
        $state = new AccountState('arrival-owner-'.$token, 0, $research, [], ['1' => $source, '2' => $targetPlanet]);
        $launchInputs = new ArrivalInputs([], 
            [$token => ['temperature_max' => 85, 'fields_total' => 160]]);
        $dispatch = (new AccountEngine(EconomySettings::defaults()))->dispatch($state, '1', Mission::Colonize,
            new Coordinates(1, 1, 4), null, ['small_cargo' => 0, 'colony_ship' => 1],
            ['metal' => '0', 'crystal' => '0', 'deuterium' => '0'], 10, $token, 0, $launchInputs);
        self::assertTrue($dispatch->isAccepted(), json_encode([$dispatch->rejection, $dispatch->context], JSON_THROW_ON_ERROR));
        $arrivalInputs = new ArrivalInputs($occupied ? [(new Coordinates(1, 1, 4))->key()] : [],
            [$token => ['temperature_max' => 85, 'fields_total' => 160]]);
        return [$dispatch->state, $arrivalInputs, $dispatch->state->fleets[0]->arrivesAt];
    }

    /** @return array{AccountState,list<array<string,mixed>>} */
    private function runSerializedShipBatchQueue(int $count, bool $partitioned): array
    {
        $levels = BuildingLevels::fromArray(['shipyard' => 4]);
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $levels, 40, 163, 4, 0));
        $state = new AccountState('fifo-'.$count, 0,
            ['spy' => 0, 'energy' => 0, 'combustion' => 2, 'impulse' => 0, 'expedition' => 0], [], ['1' => $planet]);
        $engine = new AccountEngine(EconomySettings::defaults());
        for ($index = 1; $index <= $count; ++$index) {
            $admitted = $engine->enqueueShips($state, '1', Ship::SmallCargo->legacyId(), 1, 'batch-'.$index, 0);
            self::assertTrue($admitted->isAccepted(), json_encode([$index, $admitted->rejection, $admitted->context], JSON_THROW_ON_ERROR));
            $state = $admitted->state;
            self::assertSame(0, $state->planets['1']->batches[$index - 1]->enqueuedAt);
        }
        self::assertSame((string) (100000 - 2000 * $count), (string) $state->planets['1']->economy->resources->get(\App\Domain\Economy\Resource::Metal));
        self::assertSame((string) (100000 - 2000 * $count), (string) $state->planets['1']->economy->resources->get(\App\Domain\Economy\Resource::Crystal));

        $outcomes = [];
        if (!$partitioned) {
            $result = $engine->advance($state, $count * 1152);
            self::assertTrue($result->isAccepted(), json_encode([$result->rejection, $result->context], JSON_THROW_ON_ERROR));
            return [$result->state, $result->outcomes];
        }
        for ($index = 1; $index <= $count; ++$index) {
            $boundary = $index * 1152;
            $result = $engine->advance($state, $boundary);
            self::assertTrue($result->isAccepted(), json_encode([$boundary, $result->rejection, $result->context], JSON_THROW_ON_ERROR));
            self::assertSame($boundary, $result->state->settledAt);
            if ($index < $count) {
                self::assertSame(($index) * 1152, $result->state->planets['1']->batches[0]->startedAt);
                self::assertSame(($index + 1) * 1152, $result->state->planets['1']->batches[0]->completesAt);
            }
            foreach ($result->state->planets['1']->batches as $batch) {
                self::assertSame(0, $batch->enqueuedAt);
            }
            array_push($outcomes, ...$result->outcomes);
            $state = AccountState::fromArray($result->state->toArray());
        }
        return [$state, $outcomes];
    }

    private function assertFleetSerializationRejectedForWholeCargo(array $serialized): void
    {
        try {
            AccountState::fromArray($serialized);
            self::fail('Persisted fleet current and launch cargo must contain whole units.');
        } catch (\App\Domain\Economy\EconomyDataException $error) {
            self::assertSame('Fleet cargo and launch cargo must use whole units.', $error->getMessage());
        }
    }
}
