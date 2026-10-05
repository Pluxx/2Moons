<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomyCalculator;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\EconomyEngine;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;

final readonly class AccountEngine
{
    private EconomyEngine $economy;
    private EconomyCalculator $calculator;
    private ResearchCalculator $researchCalculator;
    private ShipyardCalculator $shipyardCalculator;
    private FleetCalculator $fleetCalculator;
    private ColonyRules $colonyRules;

    public function __construct(private EconomySettings $settings)
    {
        $this->calculator = new EconomyCalculator();
        $this->economy = new EconomyEngine($this->calculator);
        $this->researchCalculator = new ResearchCalculator();
        $this->shipyardCalculator = new ShipyardCalculator();
        $this->fleetCalculator = new FleetCalculator();
        $this->colonyRules = new ColonyRules();
    }

    public function advance(AccountState $state, int $now, ArrivalInputs $inputs = new ArrivalInputs()): AccountTransition
    {
        if ($now < $state->settledAt) {
            return AccountTransition::rejected($state, 'clock_regression', ['requested_at' => $now, 'settled_at' => $state->settledAt]);
        }
        $working = $state;
        $outcomes = [];
        $constructionTails = [];
        try {
            while (($boundary = $this->nextBoundary($working, $now)) !== null) {
                $planets = $working->planets;
                $researchTail = [];
                $batchTails = [];
                foreach ($planets as $reference => $planet) {
                    $planets[$reference] = $planet->evolve($this->economy->accrueToBoundary($planet->economy, $boundary, $this->settings));
                }
                $working = $working->evolve(settledAt: $boundary, planets: $planets);

                // Production uses the activation-time unit duration and integer division, never per-unit work.
                $planets = $working->planets;
                foreach ($planets as $reference => $planet) {
                    $ships = $planet->ships;
                    $batches = $planet->batches;
                    if (($batch = $batches[0] ?? null) !== null && $batch->startedAt !== null) {
                        $earned = $this->earnedUnits($batch, $boundary);
                        $delta = $earned - $batch->produced;
                        if ($delta > 0) {
                            $ships[$batch->ship->value] = $this->checkedAdd($ships[$batch->ship->value], $delta);
                            $batch = new ShipyardBatch($batch->commandToken, $batch->ship, $batch->quantity, $batch->cost,
                                $batch->enqueuedAt, $batch->startedAt, $batch->unitSeconds, $earned, $batch->completesAt, $batch->resolvedAt, $batch->status);
                            $batches[0] = $batch;
                        }
                        if ($batch->completesAt <= $boundary) {
                            $outcomes[] = ['type' => 'ship_batch', 'token' => $batch->commandToken, 'status' => 'completed',
                                'started_at' => $batch->startedAt, 'completes_at' => $batch->completesAt, 'resolved_at' => $batch->completesAt];
                            $batchTails[$reference] = array_slice($batches, 1);
                            $batches = [];
                        }
                    }
                    $planets[$reference] = $planet->evolve(ships: $ships, batches: $batches);
                }
                $working = $working->evolve(planets: $planets);

                // Complete all due research and construction before resolving any arrival.
                $research = $working->research;
                $researchQueue = $working->researchQueue;
                if (($head = $researchQueue[0] ?? null) !== null && $head->completesAt <= $boundary) {
                    if ($head->targetLevel !== $research[$head->technology->value] + 1) {
                        throw new EconomyDataException('Research queue target became stale before completion.');
                    }
                    $research[$head->technology->value] = $head->targetLevel;
                    $outcomes[] = ['type' => 'research', 'token' => $head->commandToken, 'status' => 'completed',
                        'started_at' => $head->startedAt, 'completes_at' => $head->completesAt, 'resolved_at' => $head->completesAt];
                    $researchTail = array_slice($researchQueue, 1);
                    $researchQueue = [];
                }
                $working = $working->evolve(research: $research, researchQueue: $researchQueue);

                $planets = $working->planets;
                foreach ($planets as $reference => $planet) {
                    $head = $planet->economy->pendingEntries[0] ?? null;
                    if ($head !== null && $head->completesAt <= $boundary) {
                        [$completed, $tail, $outcome] = $this->economy->completeConstructionHead($planet->economy, $boundary);
                        $planets[$reference] = $planet->evolve($completed);
                        $constructionTails[$reference] = $tail;
                        $outcomes[] = ['type' => 'construction', ...$outcome->toArray(), 'status' => $outcome->status->value,
                            'resolved_at' => $boundary];
                    }
                }
                $working = $working->evolve(planets: $planets);

                // Resolve arrivals after same-timestamp research, construction and batch completion.
                [$working, $fleetOutcomes] = $this->resolveArrivals($working, $boundary, $inputs);
                array_push($outcomes, ...$fleetOutcomes);
                [$working, $returnOutcomes] = $this->resolveReturns($working, $boundary);
                array_push($outcomes, ...$returnOutcomes);

                // Start research first, then planet-ordered construction, then batches.
                [$working, $researchOutcomes] = $this->activateResearch($working, $boundary, $researchTail);
                array_push($outcomes, ...$researchOutcomes);
                $planets = $working->planets;
                $references = array_keys($planets);
                usort($references, static fn (string $a, string $b): int => self::comparePlanetReferences($a, $b));
                foreach ($references as $reference) {
                    $planet = $planets[$reference];
                    if (isset($constructionTails[$reference])) {
                        $activation = $this->economy->activateConstructionTail($planet->economy, $constructionTails[$reference], $boundary, $this->settings);
                        if (!$activation->isAccepted()) { throw new EconomyDataException('Construction activation failed during account settlement.'); }
                        $planet = $planet->evolve($activation->state);
                        foreach ($activation->outcomes as $failed) {
                            $outcomes[] = ['type' => 'construction', ...$failed->toArray(), 'status' => $failed->status->value, 'resolved_at' => $boundary];
                        }
                        unset($constructionTails[$reference]);
                    }
                    $planets[$reference] = $planet;
                }
                $working = $working->evolve(planets: $planets);
                [$working, $batchOutcomes] = $this->activateBatches($working, $boundary, $batchTails);
                array_push($outcomes, ...$batchOutcomes);
            }

            $planets = $working->planets;
            foreach ($planets as $reference => $planet) {
                $economy = $this->economy->accrueToBoundary($planet->economy, $now, $this->settings);
                $ships = $planet->ships;
                $batches = $planet->batches;
                if (($batch = $batches[0] ?? null) !== null && $batch->startedAt !== null) {
                    $earned = $this->earnedUnits($batch, $now);
                    $delta = $earned - $batch->produced;
                    if ($delta > 0) {
                        $ships[$batch->ship->value] = $this->checkedAdd($ships[$batch->ship->value], $delta);
                        $batches[0] = new ShipyardBatch($batch->commandToken, $batch->ship, $batch->quantity, $batch->cost,
                            $batch->enqueuedAt, $batch->startedAt, $batch->unitSeconds, $earned, $batch->completesAt, $batch->resolvedAt, $batch->status);
                    }
                }
                $planets[$reference] = $planet->evolve($economy, $ships, $batches);
            }
            return AccountTransition::accepted($working->evolve(settledAt: $now, planets: $planets), $outcomes);
        } catch (\Throwable $error) {
            return AccountTransition::rejected($state, 'invalid_account_transition', ['reason' => $error->getMessage()]);
        }
    }

    public function enqueueConstruction(AccountState $state, string $planetReference, int $buildingId, int $expectedTarget, string $token, int $now, ArrivalInputs $inputs = new ArrivalInputs()): AccountTransition
    {
        if ($token === '' || $this->hasConstructionToken($state, $planetReference, $token)) { return AccountTransition::rejected($state, 'duplicate_or_empty_token'); }
        $building = Building::fromLegacyId($buildingId);
        if ($building === null || !isset($state->planets[$planetReference])) { return AccountTransition::rejected($state, 'unknown_building_or_planet'); }
        $settlement = $this->advance($state, $now, $inputs);
        if (!$settlement->isAccepted()) { return $settlement; }
        $working = $settlement->state;
        $planet = $working->planets[$planetReference];
        $levels = $planet->economy->levels;
        $target = $levels->get($building) + count(array_filter($planet->economy->pendingEntries, static fn ($entry): bool => $entry->building === $building)) + 1;
        if (!$this->buildingPrerequisites($building, $target, $levels, $working->research)) { return AccountTransition::rejected($state, 'building_prerequisite'); }
        if ($building === Building::Laboratory && $working->researchQueue !== []) { return AccountTransition::rejected($state, 'laboratory_blocked_by_research'); }
        if ($building === Building::Shipyard && $planet->batches !== []) { return AccountTransition::rejected($state, 'shipyard_busy'); }
        $result = $this->economy->enqueue($planet->economy, $building, $expectedTarget, $token, $now, $this->settings);
        if (!$result->isAccepted()) { return AccountTransition::rejected($state, $result->rejection->code->value, $result->rejection->context); }
        $planets = $working->planets;
        $planets[$planetReference] = $planet->evolve($result->state);
        return AccountTransition::accepted($working->evolve(planets: $planets), $settlement->outcomes);
    }

    public function enqueueResearch(AccountState $state, string $source, int $technologyId, int $expectedTarget, string $token, int $now, ArrivalInputs $inputs = new ArrivalInputs()): AccountTransition
    {
        if ($token === '' || $this->hasResearchToken($state, $token)) { return AccountTransition::rejected($state, 'duplicate_or_empty_token'); }
        $technology = $this->technologyFromId($technologyId);
        if ($technology === null || !isset($state->planets[$source])) { return AccountTransition::rejected($state, 'unknown_technology_or_planet'); }
        $settlement = $this->advance($state, $now, $inputs);
        if (!$settlement->isAccepted()) { return $settlement; }
        $working = $settlement->state;
        $planet = $working->planets[$source];
        foreach ($planet->economy->pendingEntries as $construction) {
            if ($construction->building === Building::Laboratory) { return AccountTransition::rejected($state, 'laboratory_upgrade_pending'); }
        }
        if (!$this->researchCalculator->meetsPrerequisites($technology, $working->research, $planet->economy->levels->get(Building::Laboratory))) {
            return AccountTransition::rejected($state, 'research_prerequisite');
        }
        $target = $working->research[$technology->value] + $this->researchPendingCount($working, $technology) + 1;
        if ($target !== $expectedTarget || $target > 255) { return AccountTransition::rejected($state, 'stale_research_target', ['expected' => $expectedTarget, 'actual' => $target]); }
        if (count($working->researchQueue) >= 2) { return AccountTransition::rejected($state, 'research_queue_full'); }
        try {
            $cost = $this->researchCalculator->priceFor($technology, $target);
            $duration = $this->researchCalculator->durationSeconds($cost, $planet->economy->levels->get(Building::Laboratory));
        } catch (\Throwable) { return AccountTransition::rejected($state, 'research_price_or_duration_range'); }
        $queue = $working->researchQueue;
        $updatedPlanet = $planet;
        if ($queue === []) {
            if (!$planet->economy->resources->canAfford($cost)) { return AccountTransition::rejected($state, 'unaffordable_research'); }
            $end = $this->checkedTime($now, $duration);
            if ($end === null) { return AccountTransition::rejected($state, 'timestamp_range'); }
            $entry = new ResearchEntry($token, $technology, $target, $source, $now, $now, $end, $cost, 'active');
            $updatedPlanet = $planet->evolve($planet->economy->evolve(resources: $planet->economy->resources->minus($cost)));
        } else {
            $end = $this->researchQueueEnd($working, $technology, $source, $target, $now);
            if ($end === null) { return AccountTransition::rejected($state, 'timestamp_range'); }
            $entry = new ResearchEntry($token, $technology, $target, $source, $now);
        }
        $planets = $working->planets;
        $planets[$source] = $updatedPlanet;
        return AccountTransition::accepted($working->evolve(researchQueue: [...$queue, $entry], planets: $planets), $settlement->outcomes);
    }

    public function enqueueShips(AccountState $state, string $planetReference, int $shipId, int $quantity, string $token, int $now, ArrivalInputs $inputs = new ArrivalInputs()): AccountTransition
    {
        if ($token === '' || $this->hasShipyardToken($state, $planetReference, $token)) { return AccountTransition::rejected($state, 'duplicate_or_empty_token'); }
        $ship = $this->shipFromId($shipId);
        if ($ship === null || !isset($state->planets[$planetReference]) || $quantity < 1 || $quantity > 1_000_000) {
            return AccountTransition::rejected($state, 'invalid_ship_batch');
        }
        $settlement = $this->advance($state, $now, $inputs);
        if (!$settlement->isAccepted()) { return $settlement; }
        $working = $settlement->state;
        $planet = $working->planets[$planetReference];
        $yard = $planet->economy->levels->get(Building::Shipyard);
        $requiredYard = $ship === Ship::SmallCargo ? 2 : 4;
        if ($yard < $requiredYard) { return AccountTransition::rejected($state, 'shipyard_prerequisite'); }
        $requiredDrive = $ship === Ship::SmallCargo ? [Technology::Combustion, 2] : [Technology::Impulse, 3];
        if ($working->research[$requiredDrive[0]->value] < $requiredDrive[1]) { return AccountTransition::rejected($state, 'ship_technology_prerequisite'); }
        foreach ($planet->economy->pendingEntries as $construction) {
            if ($construction->building === Building::Shipyard) { return AccountTransition::rejected($state, 'shipyard_upgrade_pending'); }
        }
        if (count($planet->batches) >= 10) { return AccountTransition::rejected($state, 'shipyard_queue_full'); }
        $cost = $this->shipyardCalculator->priceFor($ship, $quantity);
        if (!$planet->economy->resources->canAfford($cost)) { return AccountTransition::rejected($state, 'unaffordable_ship_batch'); }
        foreach (Ship::cases() as $kind) {
            $reserved = BigInteger::of($planet->ships[$kind->value]);
            foreach ($planet->batches as $pending) {
                if ($pending->ship === $kind) { $reserved = $reserved->plus($pending->quantity - $pending->produced); }
            }
            foreach ($working->fleets as $fleet) {
                if ($fleet->sourcePlanet === $planetReference && $fleet->status !== 'complete') {
                    $reserved = $reserved->plus($fleet->ships[$kind->value]);
                }
            }
            if ($kind === $ship) { $reserved = $reserved->plus($quantity); }
            if ($reserved->compareTo(PHP_INT_MAX) > 0) { return AccountTransition::rejected($state, 'ship_inventory_range'); }
        }
        $unitSeconds = $this->shipyardCalculator->unitDurationSeconds($ship, $quantity, $yard);
        if ($this->shipyardQueueEnd($planet, $unitSeconds, $quantity, $now) === null) {
            return AccountTransition::rejected($state, 'timestamp_range');
        }
        $batch = new ShipyardBatch($token, $ship, $quantity, $cost, $now);
        $batches = $planet->batches;
        if ($batches === []) {
            $completion = $this->checkedTime($now, $unitSeconds * $quantity);
            if ($completion === null) { return AccountTransition::rejected($state, 'timestamp_range'); }
            $batch = new ShipyardBatch($token, $ship, $quantity, $cost, $now, $now, $unitSeconds, 0, $completion, null, 'active');
        }
        $planet = $planet->evolve($planet->economy->evolve(resources: $planet->economy->resources->minus($cost)), batches: [...$batches, $batch]);
        $planets = $working->planets;
        $planets[$planetReference] = $planet;
        return AccountTransition::accepted($working->evolve(planets: $planets), $settlement->outcomes);
    }

    /** Cargo accepts only canonical nonnegative whole-number strings. */
    public function dispatch(AccountState $state, string $source, Mission $mission, Coordinates $target, ?string $destination,
        array $ships, array $cargo, int $speedIndex, string $token, int $now, ArrivalInputs $inputs = new ArrivalInputs()): AccountTransition
    {
        if ($token === '' || $this->hasFleetToken($state, $token) || !isset($state->planets[$source])) { return AccountTransition::rejected($state, 'duplicate_token_or_unknown_source'); }
        $cargoKeys = array_keys($cargo); sort($cargoKeys);
        if ($cargoKeys !== ['crystal', 'deuterium', 'metal']) { return AccountTransition::rejected($state, 'invalid_cargo_shape'); }
        foreach ($cargo as $amount) {
            if (!is_string($amount) || strlen($amount) > 19 || !preg_match('/^(0|[1-9][0-9]*)$/D', $amount)) { return AccountTransition::rejected($state, 'invalid_cargo_amount'); }
        }
        try { $cargoAmounts = ResourceAmounts::fromStrings($cargo['metal'], $cargo['crystal'], $cargo['deuterium']); }
        catch (\Throwable) { return AccountTransition::rejected($state, 'invalid_cargo_amount'); }
        $hasCargo = false;
        foreach (Resource::cases() as $resource) { if ($cargoAmounts->get($resource)->isPositive()) { $hasCargo = true; } }
        if (($mission === Mission::Transport && !$hasCargo) || ($mission === Mission::Colonize && $destination !== null)) {
            return AccountTransition::rejected($state, 'invalid_mission_payload');
        }
        $settlement = $this->advance($state, $now, $inputs);
        if (!$settlement->isAccepted()) { return $settlement; }
        $working = $settlement->state;
        $origin = $working->planets[$source];
        if ($mission === Mission::Transport && ($destination === null || !isset($working->planets[$destination]) || $destination === $source
            || $working->planets[$destination]->coordinates->key() !== $target->key())) {
            return AccountTransition::rejected($state, 'transport_destination_not_owned');
        }
        if ($mission === Mission::Colonize && in_array($target->key(), $this->occupiedKeys($working, $inputs), true)) {
            return AccountTransition::rejected($state, 'coordinate_occupied');
        }
        if (count(array_filter($working->fleets, static fn (FleetState $fleet): bool => $fleet->status !== 'complete')) >= 1) {
            return AccountTransition::rejected($state, 'fleet_slot_unavailable');
        }
        $normalizedShips = [];
        $shipKeys = array_keys($ships); sort($shipKeys);
        if ($shipKeys !== ['colony_ship', 'small_cargo']) { return AccountTransition::rejected($state, 'invalid_ship_count'); }
        foreach ([Ship::SmallCargo, Ship::ColonyShip] as $ship) {
            $value = $ships[$ship->value] ?? null;
            if (!is_int($value) || $value < 0 || $value > $origin->ships[$ship->value]) { return AccountTransition::rejected($state, 'invalid_ship_count'); }
            $normalizedShips[$ship->value] = $value;
        }
        if (BigInteger::of($normalizedShips['small_cargo'])->plus($normalizedShips['colony_ship'])->isZero()
            || ($mission === Mission::Colonize && $normalizedShips['colony_ship'] < 1)) {
            return AccountTransition::rejected($state, 'mission_requires_ships');
        }
        try { $flight = $this->fleetCalculator->calculate($origin->coordinates, $target, $normalizedShips, $working->research, $speedIndex); }
        catch (\Throwable $error) { return AccountTransition::rejected($state, 'invalid_fleet_calculation', ['reason' => $error->getMessage()]); }
        $cargoTotal = BigRational::zero();
        foreach (Resource::cases() as $resource) { $cargoTotal = $cargoTotal->plus($cargoAmounts->get($resource)); }
        if ($cargoTotal->plus($flight['fuel'])->compareTo($flight['capacity']) > 0) { return AccountTransition::rejected($state, 'fleet_capacity_exceeded'); }
        $fuel = ResourceAmounts::fromStrings('0', '0', (string) $flight['fuel']);
        $payment = $cargoAmounts->plus($fuel);
        if (!$origin->economy->resources->canAfford($payment)) { return AccountTransition::rejected($state, 'unaffordable_cargo_or_fuel'); }
        $arrival = $this->checkedTime($now, $flight['arrival_offset']);
        $return = $this->checkedTime($now, $flight['return_offset']);
        if ($arrival === null || $return === null) { return AccountTransition::rejected($state, 'timestamp_range'); }
        $fleet = new FleetState($token, $mission, $source, $target, $destination, $normalizedShips, $cargoAmounts, $flight['fuel'],
            $now, $arrival, $return, calculation: $flight + ['speed_index' => $speedIndex, 'research' => $working->research],
            launchShips: $normalizedShips, launchCargo: $cargoAmounts);
        $remaining = [];
        foreach ($normalizedShips as $name => $count) { $remaining[$name] = $origin->ships[$name] - $count; }
        $origin = $origin->evolve($origin->economy->evolve(resources: $origin->economy->resources->minus($payment)), $remaining);
        $planets = $working->planets;
        $planets[$source] = $origin;
        $outcomes = $settlement->outcomes;
        if ($mission === Mission::Colonize) {
            $required = $this->colonyRules->requiredExpeditionLevel($target->position);
            $limit = $this->colonyRules->maximumOwnedPlanets($working->research[Technology::Expedition->value]);
            if ($working->research[Technology::Expedition->value] < $required || count($working->planets) >= $limit) {
                $outcomes[] = ['type' => 'warning', 'code' => 'colony_eligibility_not_met_at_dispatch',
                    'required_expedition_level' => $required, 'current_expedition_level' => $working->research[Technology::Expedition->value],
                    'current_planets' => count($working->planets), 'planet_limit' => $limit,
                    'note' => 'Eligibility is checked again at scheduled arrival; dispatch is not blocked.'];
            }
        }
        return AccountTransition::accepted($working->evolve(planets: $planets, fleets: [...$working->fleets, $fleet]), $outcomes);
    }

    private function nextBoundary(AccountState $state, int $now): ?int
    {
        $next = null;
        $consider = static function (?int $candidate) use (&$next, $now): void {
            if ($candidate !== null && $candidate <= $now && ($next === null || $candidate < $next)) { $next = $candidate; }
        };
        if (($entry = $state->researchQueue[0] ?? null) !== null && $entry->completesAt !== null) { $consider($entry->completesAt); }
        foreach ($state->planets as $planet) {
            if (($entry = $planet->economy->pendingEntries[0] ?? null) !== null) { $consider($entry->completesAt); }
            if (($batch = $planet->batches[0] ?? null) !== null && $batch->completesAt !== null) { $consider($batch->completesAt); }
        }
        foreach ($state->fleets as $fleet) {
            if ($fleet->status === 'outbound') { $consider($fleet->arrivesAt); }
            elseif ($fleet->status === 'returning') { $consider($fleet->returnsAt); }
        }
        return $next;
    }

    private function activateResearch(AccountState $state, int $at, array $waiting = []): array
    {
        $queue = $state->researchQueue === [] ? $waiting : $state->researchQueue;
        $planets = $state->planets;
        $outcomes = [];
        while ($queue !== [] && !$queue[0]->active()) {
            $candidate = array_shift($queue);
            $planet = $planets[$candidate->sourcePlanet] ?? null;
            if ($planet === null) { throw new EconomyDataException('Research source planet disappeared.'); }
            $cost = $this->researchCalculator->priceFor($candidate->technology, $candidate->targetLevel);
            if (!$planet->economy->resources->canAfford($cost)) {
                $outcomes[] = ['type' => 'research', 'token' => $candidate->commandToken, 'status' => 'failed', 'resolved_at' => $at, 'failure_reason' => 'insufficient_resources_at_activation'];
                $dependent = [];
                foreach ($queue as $entry) {
                    if ($entry->technology === $candidate->technology) { $dependent[] = $entry; }
                }
                foreach ($dependent as $entry) { $outcomes[] = ['type' => 'research', 'token' => $entry->commandToken, 'status' => 'failed', 'resolved_at' => $at, 'failure_reason' => 'dependent_upgrade_failed']; }
                $queue = array_values(array_filter($queue, static fn (ResearchEntry $entry): bool => $entry->technology !== $candidate->technology));
                continue;
            }
            $duration = $this->researchCalculator->durationSeconds($cost, $planet->economy->levels->get(Building::Laboratory));
            $end = $this->checkedTime($at, $duration);
            if ($end === null) { throw new EconomyDataException('Research completion time overflows.'); }
            $queue = [new ResearchEntry($candidate->commandToken, $candidate->technology, $candidate->targetLevel, $candidate->sourcePlanet,
                $candidate->enqueuedAt, $at, $end, $cost, 'active'), ...$queue];
            $planets[$candidate->sourcePlanet] = $planet->evolve($planet->economy->evolve(resources: $planet->economy->resources->minus($cost)));
            break;
        }
        return [$state->evolve(researchQueue: $queue, planets: $planets), $outcomes];
    }

    private function activateBatches(AccountState $state, int $at, array $waitingByPlanet = []): array
    {
        $planets = $state->planets;
        $outcomes = [];
        foreach ($planets as $reference => $planet) {
            $queue = $planet->batches === [] ? ($waitingByPlanet[$reference] ?? []) : $planet->batches;
            if ($queue === [] || $queue[0]->startedAt !== null) { continue; }
            $batch = array_shift($queue);
            $unit = $this->shipyardCalculator->unitDurationSeconds($batch->ship, $batch->quantity, $planet->economy->levels->get(Building::Shipyard));
            if ($unit > intdiv(PHP_INT_MAX, $batch->quantity)) {
                throw new EconomyDataException('Ship batch completion time overflows.');
            }
            $end = $this->checkedTime($at, $unit * $batch->quantity);
            if ($end === null) { throw new EconomyDataException('Ship batch completion time overflows.'); }
            $active = new ShipyardBatch($batch->commandToken, $batch->ship, $batch->quantity, $batch->cost, $batch->enqueuedAt, $at, $unit, 0, $end, null, 'active');
            $planets[$reference] = $planet->evolve(batches: [$active, ...$queue]);
        }
        return [$state->evolve(planets: $planets), $outcomes];
    }

    private function resolveArrivals(AccountState $state, int $at, ArrivalInputs $inputs): array
    {
        $fleets = $state->fleets;
        $planets = $state->planets;
        $research = $state->research;
        $outcomes = [];
        foreach ($fleets as $index => $fleet) {
            if ($fleet->status !== 'outbound' || $fleet->arrivesAt !== $at) { continue; }
            $ships = $fleet->ships;
            $cargo = $fleet->cargo;
            $reason = null;
            $colony = null;
            if ($fleet->mission === Mission::Transport) {
                if ($fleet->destinationPlanet === null || !isset($planets[$fleet->destinationPlanet])) {
                    $reason = 'destination_unavailable';
                } else {
                    $destination = $planets[$fleet->destinationPlanet];
                    $planets[$fleet->destinationPlanet] = $destination->evolve($destination->economy->evolve(resources: $destination->economy->resources->plus($cargo)));
                    $cargo = ResourceAmounts::zero();
                    $reason = 'delivered';
                }
            } else {
                $owned = count($planets);
                $expedition = $research[Technology::Expedition->value];
                $required = $this->colonyRules->requiredExpeditionLevel($fleet->target->position);
                $planetLimit = $this->colonyRules->maximumOwnedPlanets($expedition);
                $occupied = in_array($fleet->target->key(), $this->occupiedKeys($state, $inputs), true);
                if ($occupied) { $reason = 'coordinate_occupied'; }
                elseif ($expedition < $required) { $reason = 'insufficient_expedition_technology'; }
                elseif ($owned >= $planetLimit) { $reason = 'planet_limit_reached'; }
                else {
                    $draw = $inputs->colonyDraws[$fleet->commandToken] ?? null;
                    if ($draw === null) { throw new EconomyDataException('A valid colony arrival requires its prevalidated climate and fields draw.'); }
                    if (!$this->colonyRules->validateDraw($fleet->target->position, $draw['temperature_max'], $draw['fields_total'])) {
                        throw new EconomyDataException('Colony climate draw is outside the source position band.');
                    }
                    $colony = 'colony:'.$fleet->commandToken;
                    if (isset($planets[$colony])) { throw new EconomyDataException('Newborn logical planet reference is not unique.'); }
                    $resources = ResourceAmounts::fromStrings('500', '500', '0')->plus($cargo);
                    $economy = new PlanetEconomyState($resources, BuildingLevels::fromArray([], 255), $draw['temperature_max'], $draw['fields_total'], 0, $at);
                    $planets[$colony] = new AccountPlanet($colony, $fleet->target, $at, $economy);
                    $ships[Ship::ColonyShip->value]--;
                    $cargo = ResourceAmounts::zero();
                    if ($ships[Ship::SmallCargo->value] + $ships[Ship::ColonyShip->value] === 0) {
                        $fleets[$index] = new FleetState($fleet->commandToken, $fleet->mission, $fleet->sourcePlanet, $fleet->target,
                             $fleet->destinationPlanet, $ships, $cargo, $fleet->fuel, $fleet->departedAt, $fleet->arrivesAt, $fleet->returnsAt,
                             'complete', 'colonized', $colony, $fleet->calculation, $at, $at, $fleet->launchShips, $fleet->launchCargo);
                        $outcomes[] = ['type' => 'fleet', 'token' => $fleet->commandToken, 'status' => 'colonized', 'resolved_at' => $at, 'colony_reference' => $colony];
                        continue;
                    }
                    $reason = 'colonized';
                }
            }
            $status = $reason === 'colonized' || $reason === 'delivered' ? 'returning' : 'returning';
            $fleets[$index] = new FleetState($fleet->commandToken, $fleet->mission, $fleet->sourcePlanet, $fleet->target,
                $fleet->destinationPlanet, $ships, $cargo, $fleet->fuel, $fleet->departedAt, $fleet->arrivesAt, $fleet->returnsAt,
                $status, $reason, $colony, $fleet->calculation, $at, null, $fleet->launchShips, $fleet->launchCargo);
            $outcomes[] = ['type' => 'fleet', 'token' => $fleet->commandToken, 'status' => $reason, 'resolved_at' => $at, 'colony_reference' => $colony];
        }
        return [$state->evolve(planets: $planets, fleets: $fleets), $outcomes];
    }

    private function resolveReturns(AccountState $state, int $at): array
    {
        $fleets = $state->fleets;
        $planets = $state->planets;
        $outcomes = [];
        foreach ($fleets as $index => $fleet) {
            if ($fleet->status !== 'returning' || $fleet->returnsAt !== $at) { continue; }
            $origin = $planets[$fleet->sourcePlanet] ?? null;
            if ($origin === null) { throw new EconomyDataException('Returning fleet origin planet is missing; assets cannot be discarded.'); }
            $ships = $origin->ships;
            foreach ($fleet->ships as $name => $count) { $ships[$name] = $this->checkedAdd($ships[$name], $count); }
            $planets[$fleet->sourcePlanet] = $origin->evolve(ships: $ships,
                economy: $origin->economy->evolve(resources: $origin->economy->resources->plus($fleet->cargo)));
            $fleets[$index] = new FleetState($fleet->commandToken, $fleet->mission, $fleet->sourcePlanet, $fleet->target,
                $fleet->destinationPlanet, ['small_cargo' => 0, 'colony_ship' => 0], ResourceAmounts::zero(), $fleet->fuel, $fleet->departedAt, $fleet->arrivesAt,
                $fleet->returnsAt, 'complete', $fleet->outcome, $fleet->colonyReference, $fleet->calculation, $fleet->arrivalResolvedAt, $at,
                $fleet->launchShips, $fleet->launchCargo);
            $outcomes[] = ['type' => 'fleet', 'token' => $fleet->commandToken, 'status' => 'returned', 'resolved_at' => $at];
        }
        return [$state->evolve(planets: $planets, fleets: $fleets), $outcomes];
    }

    private function buildingPrerequisites(Building $building, int $target, BuildingLevels $levels, array $research): bool
    {
        return match ($building) {
            Building::RoboticsFactory => true,
            Building::Shipyard => $levels->get(Building::RoboticsFactory) >= 2,
            Building::Laboratory => true,
            default => true,
        };
    }

    private function occupiedKeys(AccountState $state, ArrivalInputs $inputs): array
    {
        return [...$inputs->occupiedCoordinates, ...array_map(static fn (AccountPlanet $planet): string => $planet->coordinates->key(), array_values($state->planets))];
    }
    private function researchPendingCount(AccountState $state, Technology $technology): int
    {
        return count(array_filter($state->researchQueue, static fn (ResearchEntry $entry): bool => $entry->technology === $technology));
    }
    private function hasConstructionToken(AccountState $state, string $planet, string $token): bool
    {
        foreach ($state->planets[$planet]->economy->pendingEntries ?? [] as $entry) { if ($entry->commandToken === $token) return true; }
        return false;
    }
    private function hasResearchToken(AccountState $state, string $token): bool
    {
        foreach ($state->researchQueue as $entry) { if ($entry->commandToken === $token) return true; }
        return false;
    }
    private function hasShipyardToken(AccountState $state, string $planet, string $token): bool
    {
        foreach ($state->planets[$planet]->batches ?? [] as $entry) { if ($entry->commandToken === $token) return true; }
        return false;
    }
    private function hasFleetToken(AccountState $state, string $token): bool
    {
        foreach ($state->fleets as $fleet) { if ($fleet->commandToken === $token) return true; }
        return false;
    }
    private function researchQueueEnd(AccountState $state, Technology $technology, string $source, int $target, int $now): ?int
    {
        $end = BigInteger::of($now);
        foreach ($state->researchQueue as $entry) {
            if ($entry->active()) { $end = BigInteger::of($entry->completesAt); continue; }
            $planet = $state->planets[$entry->sourcePlanet];
            $cost = $this->researchCalculator->priceFor($entry->technology, $entry->targetLevel);
            $seconds = $this->researchCalculator->durationSeconds($cost, $planet->economy->levels->get(Building::Laboratory));
            $end = $end->plus($seconds);
        }
        $planet = $state->planets[$source];
        $cost = $this->researchCalculator->priceFor($technology, $target);
        $seconds = $this->researchCalculator->durationSeconds($cost, $planet->economy->levels->get(Building::Laboratory));
        $candidate = $end->plus($seconds);
        return $candidate->compareTo(PHP_INT_MAX) > 0 ? null : $candidate->toInt();
    }
    private function shipyardQueueEnd(AccountPlanet $planet, int $candidateUnit, int $candidateQuantity, int $now): ?int
    {
        $end = BigInteger::of($now);
        foreach ($planet->batches as $batch) {
            if ($batch->startedAt !== null) { $end = BigInteger::of($batch->completesAt); continue; }
            $unit = $this->shipyardCalculator->unitDurationSeconds($batch->ship, $batch->quantity, $planet->economy->levels->get(Building::Shipyard));
            $end = $end->plus(BigInteger::of($unit)->multipliedBy($batch->quantity));
        }
        $candidate = $end->plus(BigInteger::of($candidateUnit)->multipliedBy($candidateQuantity));
        return $candidate->compareTo(PHP_INT_MAX) > 0 ? null : $candidate->toInt();
    }
    private function checkedTime(int $base, int $delta): ?int
    {
        $result = BigInteger::of($base)->plus($delta);
        return $result->compareTo(PHP_INT_MAX) > 0 || $result->compareTo(PHP_INT_MIN) < 0 ? null : $result->toInt();
    }
    private function checkedAdd(int $a, int $b): int
    {
        $sum = BigInteger::of($a)->plus($b);
        if ($sum->isNegative() || $sum->compareTo(PHP_INT_MAX) > 0) { throw new EconomyDataException('Ship inventory exceeds signed 64-bit capacity.'); }
        return $sum->toInt();
    }
    private function earnedUnits(ShipyardBatch $batch, int $timestamp): int
    {
        if ($batch->startedAt === null || $batch->unitSeconds === null || $timestamp < $batch->startedAt) {
            throw new EconomyDataException('Ship production boundary precedes its activation.');
        }
        $elapsed = BigInteger::of($timestamp)->minus($batch->startedAt);
        $earned = $elapsed->dividedBy($batch->unitSeconds, \Brick\Math\RoundingMode::Down);
        return $earned->compareTo($batch->quantity) >= 0 ? $batch->quantity : $earned->toInt();
    }
    private function technologyFromId(int $id): ?Technology
    {
        foreach (Technology::cases() as $technology) { if ($technology->legacyId() === $id) return $technology; }
        return null;
    }
    private function shipFromId(int $id): ?Ship
    {
        foreach (Ship::cases() as $ship) { if ($ship->legacyId() === $id) return $ship; }
        return null;
    }
    private static function comparePlanetReferences(string $a, string $b): int
    {
        if (ctype_digit($a) && ctype_digit($b)) { return BigInteger::of($a)->compareTo(BigInteger::of($b)); }
        if (ctype_digit($a)) return -1;
        if (ctype_digit($b)) return 1;
        return strcmp($a, $b);
    }
}
