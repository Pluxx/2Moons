<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class EconomyEngine
{
    public function __construct(private EconomyCalculator $calculator = new EconomyCalculator())
    {
    }

    public function calculate(PlanetEconomyState $state, EconomySettings $settings): ProductionSnapshot
    {
        return $this->calculator->calculate($state, $settings);
    }

    public function quote(PlanetEconomyState $state, Building $building, EconomySettings $settings): QuoteResult
    {
        $targetLevel = $state->levels->get($building) + $this->pendingCountFor($state, $building) + 1;
        if ($targetLevel > min($settings->maximumLevel, $building->maximumLevel())) {
            return QuoteResult::rejected(RejectionCode::LevelLimit, ['building' => $building->value]);
        }

        $cost = $this->calculator->priceFor($building, $targetLevel);
        $roboticsLevel = $state->levels->get(Building::RoboticsFactory);
        $duration = $this->calculator->buildDurationSeconds($building, $targetLevel, $settings, $roboticsLevel);
        if ($duration === null) {
            return QuoteResult::rejected(RejectionCode::TimestampRange, ['building' => $building->value]);
        }

        $projectedStart = $state->lastSettledAt;
        if ($state->pendingEntries !== []) {
            $head = $state->pendingEntries[0];
            $projectedStart = $head->completesAt;
            for ($index = 1, $count = count($state->pendingEntries); $index < $count; ++$index) {
                $entry = $state->pendingEntries[$index];
                $priorDuration = $this->calculator->buildDurationSeconds($entry->building, $entry->targetLevel, $settings, $roboticsLevel);
                if ($priorDuration === null) {
                    return QuoteResult::rejected(RejectionCode::TimestampRange, ['building' => $entry->building->value]);
                }
                $next = $this->calculator->checkedTimestampAdd($projectedStart, $priorDuration);
                if ($next === null) {
                    return QuoteResult::rejected(RejectionCode::TimestampRange, ['building' => $entry->building->value]);
                }
                $projectedStart = $next;
            }
        }

        $projectedCompletion = $this->calculator->checkedTimestampAdd($projectedStart, $duration);
        if ($projectedCompletion === null) {
            return QuoteResult::rejected(RejectionCode::TimestampRange, ['building' => $building->value]);
        }

        return QuoteResult::quoted(new ConstructionQuote(
            $building,
            $targetLevel,
            $cost,
            $duration,
            $projectedStart,
            $projectedCompletion,
            $state->resources->canAfford($cost),
            count($state->pendingEntries) < $settings->queueCapacity,
            $state->fieldsUsed + count($state->pendingEntries) < $state->fieldsTotal,
        ));
    }

    public function advance(PlanetEconomyState $state, int $timestamp, EconomySettings $settings): EconomyResult
    {
        if ($timestamp < $state->lastSettledAt) {
            return EconomyResult::rejected($state, RejectionCode::ClockRegression, [
                'requested_at' => $timestamp,
                'last_settled_at' => $state->lastSettledAt,
            ]);
        }

        $working = $state;
        $outcomes = [];
        while ($working->pendingEntries !== [] && $working->pendingEntries[0]->completesAt <= $timestamp) {
            $head = $working->pendingEntries[0];
            $boundary = $head->completesAt;
            $working = $this->settleInterval($working, $boundary, $settings);

            $currentLevel = $working->levels->get($head->building);
            if ($head->targetLevel !== $currentLevel + 1) {
                return EconomyResult::rejected($state, RejectionCode::StaleTarget, ['command_token' => $head->commandToken]);
            }
            $remaining = array_slice($working->pendingEntries, 1);
            $working = $working->evolve(
                levels: $working->levels->with($head->building, $head->targetLevel),
                fieldsUsed: $working->fieldsUsed + 1,
                pendingEntries: [],
            );
            if ($head->startedAt === null || $head->completesAt === null || $head->completesAt !== $boundary) {
                throw new EconomyDataException('Completed active construction is missing its scheduled execution interval.');
            }
            $outcomes[] = ConstructionOutcome::completed(
                $head->commandToken,
                $head->building,
                $head->targetLevel,
                $head->startedAt,
                $head->completesAt,
            );

            $activation = $this->activateNext($working, $remaining, $boundary, $settings);
            if (!$activation->isAccepted()) {
                return EconomyResult::rejected($state, $activation->rejection->code, $activation->rejection->context);
            }
            $working = $activation->state;
            array_push($outcomes, ...$activation->outcomes);
        }

        $working = $this->settleInterval($working, $timestamp, $settings);

        return EconomyResult::accepted($working, $outcomes);
    }

    /** Accrue only; account timelines complete queues globally after all planets reach a boundary. */
    public function accrueToBoundary(PlanetEconomyState $state, int $timestamp, EconomySettings $settings): PlanetEconomyState
    {
        if ($timestamp < $state->lastSettledAt) {
            throw new EconomyDataException('Account timeline cannot accrue a planet backwards.');
        }

        return $this->settleInterval($state, $timestamp, $settings);
    }

    /** Complete one due construction head without activating its waiting tail. @return array{PlanetEconomyState,list<ConstructionEntry>,ConstructionOutcome} */
    public function completeConstructionHead(PlanetEconomyState $state, int $timestamp): array
    {
        $head = $state->pendingEntries[0] ?? null;
        if ($head === null || !$head->isActive() || $head->completesAt > $timestamp || $state->lastSettledAt !== $timestamp) {
            throw new EconomyDataException('Construction completion is not due at the supplied account boundary.');
        }
        if ($head->targetLevel !== $state->levels->get($head->building) + 1) {
            throw new EconomyDataException('Construction target became stale before completion.');
        }
        $completed = $state->evolve(
            levels: $state->levels->with($head->building, $head->targetLevel),
            fieldsUsed: $state->fieldsUsed + 1,
            pendingEntries: [],
        );

        return [$completed, array_slice($state->pendingEntries, 1), ConstructionOutcome::completed(
            $head->commandToken, $head->building, $head->targetLevel, $head->startedAt, $head->completesAt,
        )];
    }

    /** @param list<ConstructionEntry> $waiting */
    public function activateConstructionTail(PlanetEconomyState $state, array $waiting, int $timestamp, EconomySettings $settings): EconomyResult
    {
        if ($state->lastSettledAt !== $timestamp) {
            throw new EconomyDataException('Construction activation must occur at its account boundary.');
        }

        return $this->activateNext($state, $waiting, $timestamp, $settings);
    }

    public function enqueue(
        PlanetEconomyState $state,
        Building $building,
        int $expectedTargetLevel,
        string $commandToken,
        int $timestamp,
        EconomySettings $settings,
    ): EconomyResult {
        if ($commandToken === '') {
            return EconomyResult::rejected($state, RejectionCode::InvalidCommandToken);
        }

        foreach ($state->pendingEntries as $entry) {
            if ($entry->commandToken === $commandToken) {
                return EconomyResult::rejected($state, RejectionCode::DuplicatePendingToken);
            }
        }

        $settlement = $this->advance($state, $timestamp, $settings);
        if (!$settlement->isAccepted()) {
            return $settlement;
        }
        $settled = $settlement->state;

        $targetLevel = $settled->levels->get($building) + $this->pendingCountFor($settled, $building) + 1;
        if ($targetLevel > min($settings->maximumLevel, $building->maximumLevel())) {
            return EconomyResult::rejected($state, RejectionCode::LevelLimit, ['building' => $building->value]);
        }
        if ($expectedTargetLevel !== $targetLevel) {
            return EconomyResult::rejected($state, RejectionCode::StaleTarget, [
                'expected' => $expectedTargetLevel,
                'actual' => $targetLevel,
            ]);
        }
        if (count($settled->pendingEntries) >= $settings->queueCapacity) {
            return EconomyResult::rejected($state, RejectionCode::QueueFull);
        }
        if ($settled->fieldsUsed + count($settled->pendingEntries) + 1 > $settled->fieldsTotal) {
            return EconomyResult::rejected($state, RejectionCode::FieldsFull);
        }

        $quoteResult = $this->quote($settled, $building, $settings);
        if (!$quoteResult->isQuoted()) {
            return EconomyResult::rejected($state, $quoteResult->rejection->code, $quoteResult->rejection->context);
        }
        $quote = $quoteResult->quote;

        if ($settled->pendingEntries === []) {
            if (!$settled->resources->canAfford($quote->cost)) {
                return EconomyResult::rejected($state, RejectionCode::Unaffordable, ['building' => $building->value]);
            }
            $completion = $this->calculator->checkedTimestampAdd($timestamp, $quote->durationSeconds);
            if ($completion === null) {
                return EconomyResult::rejected($state, RejectionCode::TimestampRange, ['building' => $building->value]);
            }
            $entry = ConstructionEntry::active($commandToken, $building, $targetLevel, $timestamp, $timestamp, $completion);
            $pending = [$entry];
            $resources = $settled->resources->minus($quote->cost);
        } else {
            // Waiting entries reserve a field, not resources, and may be unaffordable now.
            $pending = [...$settled->pendingEntries, ConstructionEntry::waiting($commandToken, $building, $targetLevel, $timestamp)];
            $resources = $settled->resources;
        }

        $accepted = $settled->evolve(resources: $resources, pendingEntries: $pending);

        return EconomyResult::accepted($accepted, $settlement->outcomes);
    }

    private function activateNext(PlanetEconomyState $state, array $waitingQueue, int $timestamp, EconomySettings $settings): EconomyResult
    {
        $working = $state;
        $outcomes = [];
        $queue = $waitingQueue;

        while ($queue !== []) {
            $candidate = array_shift($queue);
            if ($candidate->targetLevel !== $working->levels->get($candidate->building) + 1) {
                return EconomyResult::rejected($state, RejectionCode::StaleTarget, ['command_token' => $candidate->commandToken]);
            }

            $duration = $this->calculator->buildDurationSeconds($candidate->building, $candidate->targetLevel, $settings,
                $working->levels->get(Building::RoboticsFactory));
            $completion = $duration === null ? null : $this->calculator->checkedTimestampAdd($timestamp, $duration);
            if ($completion === null) {
                return EconomyResult::rejected($state, RejectionCode::TimestampRange, ['command_token' => $candidate->commandToken]);
            }

            $cost = $this->calculator->priceFor($candidate->building, $candidate->targetLevel);
            if ($working->resources->canAfford($cost)) {
                array_unshift($queue, ConstructionEntry::active(
                    $candidate->commandToken,
                    $candidate->building,
                    $candidate->targetLevel,
                    $candidate->enqueuedAt,
                    $timestamp,
                    $completion,
                ));

                return EconomyResult::accepted(
                    $working->evolve(resources: $working->resources->minus($cost), pendingEntries: $queue),
                    $outcomes,
                );
            }

            $outcomes[] = ConstructionOutcome::failed(
                $candidate->commandToken,
                $candidate->building,
                $candidate->targetLevel,
                $timestamp,
                'insufficient_resources_at_activation',
            );

            // A skipped target invalidates every later queued upgrade of that same building.
            $dependent = [];
            $unrelated = [];
            foreach ($queue as $entry) {
                if ($entry->building === $candidate->building) {
                    $dependent[] = $entry;
                } else {
                    $unrelated[] = $entry;
                }
            }
            foreach ($dependent as $entry) {
                $outcomes[] = ConstructionOutcome::failed(
                    $entry->commandToken,
                    $entry->building,
                    $entry->targetLevel,
                    $timestamp,
                    'dependent_upgrade_failed',
                );
            }
            $queue = $unrelated;
        }

        return EconomyResult::accepted($working, $outcomes);
    }

    private function settleInterval(PlanetEconomyState $state, int $timestamp, EconomySettings $settings): PlanetEconomyState
    {
        if ($timestamp < $state->lastSettledAt) {
            throw new EconomyDataException('Internal settlement attempted to move backwards.');
        }
        $elapsed = \Brick\Math\BigInteger::of($timestamp)->minus($state->lastSettledAt);
        $production = $this->calculator->calculate($state, $settings);
        $resources = $this->calculator->accrue($state->resources, $production->totalRatesPerHour, $production->capacities, $elapsed);

        return $state->evolve(resources: $resources, lastSettledAt: $timestamp);
    }

    private function pendingCountFor(PlanetEconomyState $state, Building $building): int
    {
        $count = 0;
        foreach ($state->pendingEntries as $entry) {
            if ($entry->building === $building) {
                ++$count;
            }
        }

        return $count;
    }
}
