<?php

declare(strict_types=1);

namespace App\Tests\Domain\Economy;

use App\Domain\Economy\Building;
use App\Domain\Economy\ConstructionEntry;
use App\Domain\Economy\ConstructionOutcome;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\EconomyEngine;
use App\Domain\Economy\EconomyResult;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\OutcomeStatus;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\RejectionCode;
use App\Domain\Economy\ResourceAmounts;

final class EconomyEngineTest extends EconomyTestCase
{
    public function testWaitingQueueReservesFieldsButDoesNotEscrowOrRequireCurrentAffordability(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $state = PlanetEconomyState::newHome(0, $settings);
        $state = $this->enqueue($engine, $state, Building::MetalMine, 1, 'head', $settings)->state;
        $afterPayment = $state->resources->toCanonicalArray();

        foreach ([
            [Building::CrystalMine, 'crystal'],
            [Building::DeuteriumSynthesizer, 'deuterium'],
            [Building::SolarPlant, 'solar'],
            [Building::MetalStorage, 'unaffordable-storage'],
        ] as [$building, $token]) {
            $result = $this->enqueue($engine, $state, $building, 1, $token, $settings);
            self::assertTrue($result->isAccepted());
            $state = $result->state;
        }

        self::assertCount(5, $state->pendingEntries);
        self::assertSame($afterPayment, $state->resources->toCanonicalArray());
        self::assertTrue($state->pendingEntries[0]->isActive());
        foreach (array_slice($state->pendingEntries, 1) as $entry) {
            self::assertFalse($entry->isActive());
        }

        $quote = $engine->quote($state, Building::MetalStorage, $settings);
        self::assertTrue($quote->isQuoted());
        self::assertFalse($quote->quote->affordableNow);
        self::assertFalse($quote->quote->queueSlotAvailable);
        self::assertTrue($quote->quote->fieldAvailable);
    }

    public function testQueueRejectsSixthEntryAndUsesActiveEntryForTargetsAndForecastFields(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $state = PlanetEconomyState::newHome(0, $settings);
        $state = $this->enqueue($engine, $state, Building::MetalMine, 1, 'metal-1', $settings)->state;

        $quote = $engine->quote($state, Building::MetalMine, $settings);
        self::assertTrue($quote->isQuoted());
        self::assertSame(2, $quote->quote->targetLevel);
        self::assertSame($state->pendingEntries[0]->completesAt, $quote->quote->projectedStartAt);
        self::assertTrue($quote->quote->fieldAvailable);

        $state = $this->enqueue($engine, $state, Building::MetalMine, 2, 'metal-2', $settings)->state;
        $quote = $engine->quote($state, Building::MetalMine, $settings);
        self::assertSame(3, $quote->quote->targetLevel);
        self::assertGreaterThan($state->pendingEntries[0]->completesAt, $quote->quote->projectedStartAt);

        foreach ([
            [Building::CrystalMine, 'crystal-1'],
            [Building::SolarPlant, 'solar-1'],
            [Building::MetalStorage, 'storage-1'],
        ] as [$building, $token]) {
            $target = 1;
            $state = $this->enqueue($engine, $state, $building, $target, $token, $settings)->state;
        }
        self::assertCount(5, $state->pendingEntries);
        $before = $state->toArray();
        $rejected = $this->enqueue($engine, $state, Building::CrystalStorage, 1, 'sixth', $settings);
        self::assertSame(RejectionCode::QueueFull, $rejected->rejection->code);
        self::assertSame($before, $rejected->state->toArray());
    }

    public function testFieldReservationsAreCheckedSeparatelyFromQueueCapacity(): void
    {
        $base = $this->settings();
        $settings = new EconomySettings(
            $base->gameSpeed,
            $base->resourceSpeed,
            $base->storageMultiplier,
            $base->energySpeed,
            $base->maximumOverflow,
            $base->minimumBuildSeconds,
            $base->roboticsLevel,
            $base->naniteLevel,
            4,
            $base->maximumLevel,
            $base->queueCapacity,
            $base->homeTemperatureMax,
            $base->startingResources,
            $base->basicIncomePerHour,
        );
        $engine = new EconomyEngine();
        $state = PlanetEconomyState::newHome(0, $settings);
        $state = $this->enqueue($engine, $state, Building::MetalMine, 1, 'field-active', $settings)->state;
        foreach ([[Building::CrystalMine, 'f2'], [Building::SolarPlant, 'f3'], [Building::MetalStorage, 'f4']] as [$building, $token]) {
            $state = $this->enqueue($engine, $state, $building, 1, $token, $settings)->state;
        }

        $quote = $engine->quote($state, Building::CrystalStorage, $settings);
        self::assertTrue($quote->quote->queueSlotAvailable);
        self::assertFalse($quote->quote->fieldAvailable);
        $before = $state->toArray();
        $result = $this->enqueue($engine, $state, Building::CrystalStorage, 1, 'field-5', $settings);
        self::assertSame(RejectionCode::FieldsFull, $result->rejection->code);
        self::assertSame($before, $result->state->toArray());
    }

    public function testWaitingEntryIsChargedExactlyOnceAtActivationTimestamp(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $state = PlanetEconomyState::newHome(0, $settings);
        $state = $this->enqueue($engine, $state, Building::MetalMine, 1, 'first', $settings)->state;
        $afterFirstPayment = $state->resources->toCanonicalArray();
        $state = $this->enqueue($engine, $state, Building::MetalMine, 2, 'second', $settings)->state;
        self::assertSame($afterFirstPayment, $state->resources->toCanonicalArray());

        $atCompletion = $engine->advance($state, 162, $settings);
        self::assertSame([OutcomeStatus::Completed], array_map(static fn ($event) => $event->status, $atCompletion->outcomes));
        self::assertSame('2759/10', $atCompletion->state->resources->toCanonicalArray()['metal']);
        self::assertSame('2221/5', $atCompletion->state->resources->toCanonicalArray()['crystal']);
        self::assertSame(2, $atCompletion->state->pendingEntries[0]->targetLevel);
        self::assertSame(162, $atCompletion->state->pendingEntries[0]->startedAt);
        self::assertSame(0, $atCompletion->outcomes[0]->startedAt);
        self::assertSame(162, $atCompletion->outcomes[0]->completesAt);
        self::assertSame(162, $atCompletion->outcomes[0]->resolvedAt);

        $sameTime = $engine->advance($atCompletion->state, 162, $settings);
        self::assertSame([], $sameTime->outcomes);
        self::assertSame($atCompletion->state->toArray(), $sameTime->state->toArray());
    }

    public function testActivationShortfallUsesEventTimeAndFailsDependentsButStartsUnrelatedEntry(): void
    {
        $active = ConstructionEntry::active('paid-head', Building::MetalMine, 1, 0, 0, 162);
        $pending = [
            $active,
            ConstructionEntry::waiting('metal-2', Building::MetalMine, 2, 0),
            ConstructionEntry::waiting('solar-1', Building::SolarPlant, 1, 0),
            ConstructionEntry::waiting('metal-3', Building::MetalMine, 3, 0),
        ];
        $state = new PlanetEconomyState(
            ResourceAmounts::fromStrings('120', '45', '0'),
            \App\Domain\Economy\BuildingLevels::fromArray([]),
            40,
            163,
            0,
            0,
            $pending,
        );
        $result = (new EconomyEngine())->advance($state, 162 + 7 * 3600, $this->settings());

        self::assertTrue($result->isAccepted());
        self::assertSame(['paid-head', 'metal-2', 'metal-3', 'solar-1'], array_map(static fn ($event) => $event->commandToken, $result->outcomes));
        self::assertSame([OutcomeStatus::Completed, OutcomeStatus::Failed, OutcomeStatus::Failed, OutcomeStatus::Completed], array_map(static fn ($event) => $event->status, $result->outcomes));
        self::assertSame(162, $result->outcomes[1]->resolvedAt);
        self::assertSame('insufficient_resources_at_activation', $result->outcomes[1]->failureReason);
        self::assertSame('dependent_upgrade_failed', $result->outcomes[2]->failureReason);
        self::assertNull($result->outcomes[1]->startedAt);
        self::assertNull($result->outcomes[1]->completesAt);
        self::assertNull($result->outcomes[2]->startedAt);
        self::assertNull($result->outcomes[2]->completesAt);
        self::assertSame(1, $result->state->levels->get(Building::MetalMine));
        self::assertSame(1, $result->state->levels->get(Building::SolarPlant));
        self::assertSame(2, $result->state->fieldsUsed);
        self::assertSame([], $result->state->pendingEntries);
        self::assertGreaterThanOrEqual(0, $result->state->resources->get(\App\Domain\Economy\Resource::Metal)->compareTo(135));
    }

    public function testEveryOverdueEntryCanFailWithoutConsumingTimeOrLeavingPendingQueue(): void
    {
        $pending = [
            ConstructionEntry::active('head', Building::MetalMine, 1, 0, 0, 162),
            ConstructionEntry::waiting('metal-wait', Building::MetalMine, 2, 0),
            ConstructionEntry::waiting('solar-wait', Building::SolarPlant, 1, 0),
            ConstructionEntry::waiting('crystal-wait', Building::CrystalMine, 1, 0),
        ];
        $state = new PlanetEconomyState(ResourceAmounts::zero(), \App\Domain\Economy\BuildingLevels::fromArray([]), 40, 163, 0, 0, $pending);
        $result = (new EconomyEngine())->advance($state, 10000, $this->settings());

        self::assertSame([], $result->state->pendingEntries);
        self::assertSame(1, $result->state->fieldsUsed);
        self::assertSame(['head', 'metal-wait', 'solar-wait', 'crystal-wait'], array_map(static fn ($event) => $event->commandToken, $result->outcomes));
        self::assertSame([OutcomeStatus::Completed, OutcomeStatus::Failed, OutcomeStatus::Failed, OutcomeStatus::Failed], array_map(static fn ($event) => $event->status, $result->outcomes));
        self::assertSame(162, $result->outcomes[1]->resolvedAt);
        self::assertSame(162, $result->outcomes[3]->resolvedAt);
    }

    public function testClockRegressionAndRejectedEnqueueReturnTheOriginalStateWithoutPartialSettlement(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $home = PlanetEconomyState::newHome(100, $settings);
        $backwards = $engine->advance($home, 99, $settings);
        self::assertSame(RejectionCode::ClockRegression, $backwards->rejection->code);
        self::assertSame($home, $backwards->state);

        $active = $this->enqueue($engine, $home, Building::MetalMine, 1, 'due', $settings)->state;
        $rejected = $engine->enqueue($active, Building::MetalMine, 3, 'stale-after-settlement', 262, $settings);
        self::assertSame(RejectionCode::StaleTarget, $rejected->rejection->code);
        self::assertSame($active, $rejected->state);
        self::assertSame(100, $rejected->state->lastSettledAt);
        self::assertSame([], $rejected->outcomes);
    }

    public function testSameTimestampStillProcessesAnAlreadyDueHead(): void
    {
        $state = new PlanetEconomyState(
            ResourceAmounts::zero(),
            \App\Domain\Economy\BuildingLevels::fromArray([]),
            40,
            163,
            0,
            162,
            [ConstructionEntry::active('due-now', Building::MetalMine, 1, 0, 0, 162)],
        );
        $result = (new EconomyEngine())->advance($state, 162, $this->settings());

        self::assertSame(1, $result->state->levels->get(Building::MetalMine));
        self::assertSame(1, $result->state->fieldsUsed);
        self::assertSame(OutcomeStatus::Completed, $result->outcomes[0]->status);
        self::assertSame(0, $result->outcomes[0]->startedAt);
        self::assertSame(162, $result->outcomes[0]->completesAt);
        self::assertSame(162, $result->outcomes[0]->resolvedAt);
    }

    public function testLateCatchupOutcomesCarryScheduledExecutionIntervalsAndKeepCanonicalAccounting(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $state = PlanetEconomyState::newHome(10_000, $settings);
        $state = $this->enqueue($engine, $state, Building::MetalMine, 1, 'catchup-mine', $settings)->state;
        $state = $this->enqueue($engine, $state, Building::SolarPlant, 1, 'catchup-solar', $settings)->state;

        $result = $engine->advance($state, 10_388, $settings);

        self::assertTrue($result->isAccepted());
        self::assertSame([], $result->state->pendingEntries);
        self::assertSame(1, $result->state->levels->get(Building::MetalMine));
        self::assertSame(1, $result->state->levels->get(Building::SolarPlant));
        self::assertSame(2, $result->state->fieldsUsed);
        self::assertSame(10_388, $result->state->lastSettledAt);
        self::assertSame([
            [10_000, 10_162, 10_162],
            [10_162, 10_388, 10_388],
        ], array_map(static fn ($outcome): array => [$outcome->startedAt, $outcome->completesAt, $outcome->resolvedAt], $result->outcomes));
        self::assertSame('catchup-mine', $result->outcomes[0]->toArray()['command_token']);
        self::assertSame(10_162, $result->outcomes[0]->toArray()['completes_at']);
        self::assertSame('catchup-solar', $result->outcomes[1]->toArray()['command_token']);
        self::assertSame(10_162, $result->outcomes[1]->toArray()['started_at']);
        self::assertSame('26969/90', $result->state->resources->toCanonicalArray()['metal']);
        self::assertSame('19511/45', $result->state->resources->toCanonicalArray()['crystal']);
        self::assertSame('0/1', $result->state->resources->toCanonicalArray()['deuterium']);
    }

    public function testConstructionOutcomeExecutionMetadataIsValidatedAndSerialized(): void
    {
        $completed = ConstructionOutcome::completed('done', Building::MetalMine, 1, 100, 162);
        self::assertSame([
            'command_token' => 'done',
            'building' => 'metal_mine',
            'target_level' => 1,
            'status' => 'completed',
            'resolved_at' => 162,
            'failure_reason' => null,
            'started_at' => 100,
            'completes_at' => 162,
        ], $completed->toArray());

        $failed = ConstructionOutcome::failed('failed', Building::SolarPlant, 1, 162, 'insufficient_resources_at_activation');
        self::assertNull($failed->startedAt);
        self::assertNull($failed->completesAt);

        foreach ([
            ['completed', null, 162, 162, null],
            ['completed', 200, 162, 162, null],
            ['completed', 100, 162, 163, null],
            ['failed', null, null, 162, null],
            ['failed', 100, 162, 162, 'failure'],
        ] as [$status, $startedAt, $completesAt, $resolvedAt, $failureReason]) {
            try {
                new ConstructionOutcome(
                    'invalid',
                    Building::MetalMine,
                    1,
                    OutcomeStatus::from($status),
                    $resolvedAt,
                    $failureReason,
                    $startedAt,
                    $completesAt,
                );
                self::fail('Invalid terminal execution metadata was accepted.');
            } catch (EconomyDataException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTimestampOverflowAndMaximumLevelRejectWithoutChangingInput(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $nearMax = PlanetEconomyState::newHome(PHP_INT_MAX - 10, $settings);
        $before = $nearMax->toArray();
        $overflow = $this->enqueue($engine, $nearMax, Building::MetalMine, 1, 'overflow', $settings);
        self::assertSame(RejectionCode::TimestampRange, $overflow->rejection->code);
        self::assertSame($before, $overflow->state->toArray());

        $level255 = $this->planet(levels: ['metal_mine' => 255], fieldsTotal: 300);
        $tooHigh = $this->enqueue($engine, $level255, Building::MetalMine, 256, 'over-level', $settings);
        self::assertSame(RejectionCode::LevelLimit, $tooHigh->rejection->code);
        self::assertSame($level255->toArray(), $tooHigh->state->toArray());
    }

    public function testWaitingQueueProjectionRejectsTimestampOverflowWithoutChangingState(): void
    {
        $state = new PlanetEconomyState(
            ResourceAmounts::fromStrings('100000', '100000', '100000'),
            \App\Domain\Economy\BuildingLevels::fromArray([]),
            40,
            163,
            0,
            0,
            [
                ConstructionEntry::active('near-end', Building::MetalMine, 1, 0, 0, PHP_INT_MAX - 100),
                ConstructionEntry::waiting('wait-storage', Building::MetalStorage, 1, 0),
            ],
        );
        $engine = new EconomyEngine();
        $before = $state->toArray();

        $quote = $engine->quote($state, Building::SolarPlant, $this->settings());
        self::assertFalse($quote->isQuoted());
        self::assertSame(RejectionCode::TimestampRange, $quote->rejection->code);

        $result = $engine->enqueue($state, Building::SolarPlant, 1, 'overflow-behind-wait', 0, $this->settings());
        self::assertSame(RejectionCode::TimestampRange, $result->rejection->code);
        self::assertSame($before, $result->state->toArray());
        self::assertSame([], $result->outcomes);
    }

    public function testPendingTokenCannotBeReusedBeforeAtOrDuringCatchupCompletion(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $active = $this->enqueue($engine, PlanetEconomyState::newHome(0, $settings), Building::MetalMine, 1, 'same-token', $settings)->state;

        foreach ([
            [Building::CrystalMine, 1, 1],
            [Building::MetalMine, 2, 162],
        ] as [$building, $target, $timestamp]) {
            $before = $active->toArray();
            $duplicate = $engine->enqueue($active, $building, $target, 'same-token', $timestamp, $settings);
            self::assertSame(RejectionCode::DuplicatePendingToken, $duplicate->rejection->code);
            self::assertSame($before, $duplicate->state->toArray());
            self::assertSame([], $duplicate->outcomes);
        }

        $waitingState = new PlanetEconomyState(
            ResourceAmounts::zero(),
            \App\Domain\Economy\BuildingLevels::fromArray([]),
            40,
            163,
            0,
            0,
            [
                ConstructionEntry::active('paid-head', Building::MetalMine, 1, 0, 0, 162),
                ConstructionEntry::waiting('same-token', Building::MetalMine, 2, 0),
            ],
        );
        $catchup = $engine->advance($waitingState, 1000, $settings);
        self::assertContains('same-token', array_map(static fn ($outcome) => $outcome->commandToken, $catchup->outcomes));
        self::assertFalse(array_values(array_filter($catchup->outcomes, static fn ($outcome) => $outcome->commandToken === 'same-token'))[0]->status === OutcomeStatus::Completed);

        $before = $waitingState->toArray();
        $duplicate = $engine->enqueue($waitingState, Building::SolarPlant, 1, 'same-token', 1000, $settings);
        self::assertSame(RejectionCode::DuplicatePendingToken, $duplicate->rejection->code);
        self::assertSame($before, $duplicate->state->toArray());
        self::assertSame([], $duplicate->outcomes);
    }

    public function testBulkAndBoundaryCatchupMatchAcrossDependentFailureAndUnrelatedActivation(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $state = new PlanetEconomyState(
            ResourceAmounts::fromStrings('120', '45', '0'),
            \App\Domain\Economy\BuildingLevels::fromArray([]),
            40,
            163,
            0,
            0,
            [
                ConstructionEntry::active('paid-head', Building::MetalMine, 1, 0, 0, 162),
                ConstructionEntry::waiting('metal-2', Building::MetalMine, 2, 0),
                ConstructionEntry::waiting('solar-1', Building::SolarPlant, 1, 0),
                ConstructionEntry::waiting('metal-3', Building::MetalMine, 3, 0),
            ],
        );
        $finalTimestamp = 162 + 7 * 3600;
        $bulk = $engine->advance($state, $finalTimestamp, $settings);
        self::assertTrue($bulk->isAccepted());

        $partitionedState = PlanetEconomyState::fromArray($state->toArray());
        $partitionedOutcomes = [];
        while ($partitionedState->pendingEntries !== []) {
            $boundary = $partitionedState->pendingEntries[0]->completesAt;
            $step = $engine->advance($partitionedState, $boundary, $settings);
            self::assertTrue($step->isAccepted());
            $partitionedState = PlanetEconomyState::fromArray($step->state->toArray());
            array_push($partitionedOutcomes, ...$step->outcomes);
        }
        $last = $engine->advance($partitionedState, $finalTimestamp, $settings);
        array_push($partitionedOutcomes, ...$last->outcomes);
        $partitionedState = $last->state;

        self::assertSame($bulk->state->toArray(), $partitionedState->toArray());
        self::assertSame(
            array_map(static fn ($outcome) => [
                $outcome->commandToken,
                $outcome->building->value,
                $outcome->targetLevel,
                $outcome->status->value,
                $outcome->resolvedAt,
                $outcome->failureReason,
            ], $bulk->outcomes),
            array_map(static fn ($outcome) => [
                $outcome->commandToken,
                $outcome->building->value,
                $outcome->targetLevel,
                $outcome->status->value,
                $outcome->resolvedAt,
                $outcome->failureReason,
            ], $partitionedOutcomes),
        );
    }

    public function testBulkCatchupMatchesEachCompletionBoundary(): void
    {
        $engine = new EconomyEngine();
        $settings = $this->settings();
        $state = $this->planet(['metal' => '100000', 'crystal' => '100000', 'deuterium' => '100000']);
        foreach ([
            [Building::MetalMine, 'bulk-metal'],
            [Building::CrystalMine, 'bulk-crystal'],
            [Building::DeuteriumSynthesizer, 'bulk-deut'],
            [Building::SolarPlant, 'bulk-solar'],
            [Building::MetalStorage, 'bulk-storage'],
        ] as [$building, $token]) {
            $target = $state->levels->get($building);
            foreach ($state->pendingEntries as $entry) {
                if ($entry->building === $building) {
                    ++$target;
                }
            }
            $result = $this->enqueue($engine, $state, $building, $target + 1, $token, $settings);
            self::assertTrue($result->isAccepted());
            $state = $result->state;
        }

        $bulk = $engine->advance($state, 10000, $settings);
        self::assertTrue($bulk->isAccepted());

        $steppedState = $state;
        $steppedOutcomes = [];
        while ($steppedState->pendingEntries !== []) {
            $nextBoundary = $steppedState->pendingEntries[0]->completesAt;
            $step = $engine->advance($steppedState, $nextBoundary, $settings);
            self::assertTrue($step->isAccepted());
            $steppedState = PlanetEconomyState::fromArray($step->state->toArray());
            array_push($steppedOutcomes, ...$step->outcomes);
        }
        $final = $engine->advance($steppedState, 10000, $settings);
        array_push($steppedOutcomes, ...$final->outcomes);

        self::assertSame($bulk->state->toArray(), $final->state->toArray());
        self::assertSame(
            array_map(static fn ($event) => [$event->commandToken, $event->status->value, $event->resolvedAt], $bulk->outcomes),
            array_map(static fn ($event) => [$event->commandToken, $event->status->value, $event->resolvedAt], $steppedOutcomes),
        );
    }

    private function enqueue(EconomyEngine $engine, PlanetEconomyState $state, Building $building, int $target, string $token, EconomySettings $settings): EconomyResult
    {
        return $engine->enqueue($state, $building, $target, $token, $state->lastSettledAt, $settings);
    }
}
