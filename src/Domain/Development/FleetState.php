<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\ResourceAmounts;

final readonly class FleetState
{
    public array $launchShips;
    public ResourceAmounts $launchCargo;
    /** @param array<string,int> $ships @param array<string,mixed> $calculation */
    public function __construct(
        public string $commandToken,
        public Mission $mission,
        public string $sourcePlanet,
        public Coordinates $target,
        public ?string $destinationPlanet,
        public array $ships,
        public ResourceAmounts $cargo,
        public int $fuel,
        public int $departedAt,
        public int $arrivesAt,
        public int $returnsAt,
        public string $status = 'outbound',
        public ?string $outcome = null,
        public ?string $colonyReference = null,
        public array $calculation = [],
        public ?int $arrivalResolvedAt = null,
        public ?int $resolvedAt = null,
        ?array $launchShips = null,
        ?ResourceAmounts $launchCargo = null,
    ) {
        $this->launchShips = $launchShips ?? $ships;
        $this->launchCargo = $launchCargo ?? $cargo;
        if ($commandToken === '' || $sourcePlanet === '' || $fuel < 0 || $arrivesAt <= $departedAt || $returnsAt < $arrivesAt
            || !in_array($status, ['outbound', 'returning', 'complete'], true) || !is_array($ships)) {
            throw new EconomyDataException('Fleet identity, schedule, or status is invalid.');
        }
        $keys = array_keys($ships); sort($keys);
        if ($keys !== ['colony_ship', 'small_cargo']) { throw new EconomyDataException('Fleet ship payload keys are invalid.'); }
        foreach ($ships as $count) {
            if (!is_int($count) || $count < 0) { throw new EconomyDataException('Fleet ship payload is malformed.'); }
        }
        $launchKeys = array_keys($this->launchShips); sort($launchKeys);
        if ($launchKeys !== ['colony_ship', 'small_cargo']) { throw new EconomyDataException('Immutable fleet launch ship payload keys are invalid.'); }
        foreach ($this->launchShips as $count) {
            if (!is_int($count) || $count < 0) { throw new EconomyDataException('Immutable fleet launch ship payload is malformed.'); }
        }
        foreach (\App\Domain\Economy\Resource::cases() as $resource) {
            if (!$cargo->get($resource)->getDenominator()->isEqualTo(1)
                || !$this->launchCargo->get($resource)->getDenominator()->isEqualTo(1)) {
                throw new EconomyDataException('Fleet cargo and launch cargo must use whole units.');
            }
        }
        if ($mission === Mission::Transport
            && $this->launchCargo->get(\App\Domain\Economy\Resource::Metal)->isZero()
            && $this->launchCargo->get(\App\Domain\Economy\Resource::Crystal)->isZero()
            && $this->launchCargo->get(\App\Domain\Economy\Resource::Deuterium)->isZero()) {
            throw new EconomyDataException('Transport fleet launch cargo must be positive.');
        }
        if ($this->launchShips[Ship::SmallCargo->value] + $this->launchShips[Ship::ColonyShip->value] < 1) {
            throw new EconomyDataException('Immutable fleet launch payload must include a ship.');
        }
        foreach (Ship::cases() as $ship) {
            if ($ships[$ship->value] > $this->launchShips[$ship->value]) {
                throw new EconomyDataException('Remaining fleet ships exceed immutable launch payload.');
            }
        }
        foreach (\App\Domain\Economy\Resource::cases() as $resource) {
            if ($cargo->get($resource)->compareTo($this->launchCargo->get($resource)) > 0) {
                throw new EconomyDataException('Remaining fleet cargo exceeds immutable launch payload.');
            }
        }
        if ($ships[Ship::SmallCargo->value] === 0 && $ships[Ship::ColonyShip->value] === 0
            && !(($mission === Mission::Colonize && $status === 'complete' && $outcome === 'colonized')
                || ($status === 'complete' && $outcome !== null))) {
            throw new EconomyDataException('Fleet must retain at least one ship unless its sole colony ship was consumed on success.');
        }
        if (($mission === Mission::Colonize && $destinationPlanet !== null)
            || ($mission === Mission::Transport && $destinationPlanet === null)
            || ($mission === Mission::Colonize && $status === 'outbound' && $ships[Ship::ColonyShip->value] === 0)) {
            throw new EconomyDataException('Fleet mission payload is inconsistent.');
        }
        if (($status === 'outbound' && ($arrivalResolvedAt !== null || $resolvedAt !== null))
            || ($status === 'returning' && ($arrivalResolvedAt !== $arrivesAt || $resolvedAt !== null))
            || ($status === 'complete' && ($resolvedAt === null || $resolvedAt < $departedAt))
            || ($status !== 'complete' && $resolvedAt !== null)) {
            throw new EconomyDataException('Fleet execution metadata is inconsistent with its lifecycle status.');
        }
        if ($status === 'complete' && ($ships[Ship::SmallCargo->value] !== 0 || $ships[Ship::ColonyShip->value] !== 0
            || !$cargo->get(\App\Domain\Economy\Resource::Metal)->isZero()
            || !$cargo->get(\App\Domain\Economy\Resource::Crystal)->isZero()
            || !$cargo->get(\App\Domain\Economy\Resource::Deuterium)->isZero())) {
            throw new EconomyDataException('Completed fleet cannot retain mutable assets.');
        }
        $cargoEqualsLaunch = true;
        foreach (\App\Domain\Economy\Resource::cases() as $resource) {
            if ($cargo->get($resource)->compareTo($this->launchCargo->get($resource)) !== 0) { $cargoEqualsLaunch = false; }
        }
        if ($status === 'outbound' && ($ships !== $this->launchShips || !$cargoEqualsLaunch)) {
            throw new EconomyDataException('Outbound fleet assets must match its immutable launch payload.');
        }
        if ($status === 'returning') {
            $expectedShips = $this->launchShips;
            $expectedCargo = $cargoEqualsLaunch;
            if ($mission === Mission::Colonize && $outcome === 'colonized') {
                --$expectedShips[Ship::ColonyShip->value];
                $expectedCargo = $cargo->get(\App\Domain\Economy\Resource::Metal)->isZero()
                    && $cargo->get(\App\Domain\Economy\Resource::Crystal)->isZero()
                    && $cargo->get(\App\Domain\Economy\Resource::Deuterium)->isZero();
            } elseif ($mission === Mission::Transport && $outcome === 'delivered') {
                $expectedCargo = $cargo->get(\App\Domain\Economy\Resource::Metal)->isZero()
                    && $cargo->get(\App\Domain\Economy\Resource::Crystal)->isZero()
                    && $cargo->get(\App\Domain\Economy\Resource::Deuterium)->isZero();
            }
            if ($ships !== $expectedShips || !$expectedCargo) {
                throw new EconomyDataException('Returning fleet assets are inconsistent with its immutable launch payload and mission result.');
            }
        }
    }

    public function toArray(): array
    {
        return ['token' => $this->commandToken, 'mission' => $this->mission->value, 'source_planet' => $this->sourcePlanet,
            'target' => $this->target->toArray(), 'destination_planet' => $this->destinationPlanet, 'ships' => $this->ships,
            'cargo' => $this->cargo->toCanonicalArray(), 'fuel' => $this->fuel, 'departed_at' => $this->departedAt,
            'arrives_at' => $this->arrivesAt, 'returns_at' => $this->returnsAt, 'status' => $this->status,
            'outcome' => $this->outcome, 'colony_reference' => $this->colonyReference, 'calculation' => $this->calculation,
            'launch_ships' => $this->launchShips, 'launch_cargo' => $this->launchCargo->toCanonicalArray(),
            'arrival_resolved_at' => $this->arrivalResolvedAt, 'resolved_at' => $this->resolvedAt];
    }

    public static function fromArray(array $data): self
    {
        $keys = ['token','mission','source_planet','target','destination_planet','ships','cargo','fuel','departed_at','arrives_at','returns_at','status','outcome','colony_reference','calculation','launch_ships','launch_cargo','arrival_resolved_at','resolved_at'];
        $actual = array_keys($data); sort($keys); sort($actual);
        if ($actual !== $keys || !is_string($data['token']) || !is_string($data['mission']) || !is_string($data['source_planet'])
            || !is_array($data['target']) || ($data['destination_planet'] !== null && !is_string($data['destination_planet']))
            || !is_array($data['ships']) || !is_array($data['cargo']) || !is_int($data['fuel']) || !is_int($data['departed_at'])
            || !is_int($data['arrives_at']) || !is_int($data['returns_at']) || !is_string($data['status'])
            || ($data['outcome'] !== null && !is_string($data['outcome'])) || ($data['colony_reference'] !== null && !is_string($data['colony_reference']))
            || !is_array($data['calculation']) || !is_array($data['launch_ships']) || !is_array($data['launch_cargo']) || ($data['arrival_resolved_at'] !== null && !is_int($data['arrival_resolved_at']))
            || ($data['resolved_at'] !== null && !is_int($data['resolved_at']))) { throw new EconomyDataException('Malformed serialized fleet state.'); }
        $mission = Mission::tryFrom($data['mission']);
        if ($mission === null) { throw new EconomyDataException('Unknown serialized fleet mission.'); }
        return new self($data['token'], $mission, $data['source_planet'], Coordinates::fromArray($data['target']), $data['destination_planet'],
            $data['ships'], ResourceAmounts::fromCanonical($data['cargo']), $data['fuel'], $data['departed_at'], $data['arrives_at'],
            $data['returns_at'], $data['status'], $data['outcome'], $data['colony_reference'], $data['calculation'],
            $data['arrival_resolved_at'], $data['resolved_at'], $data['launch_ships'], ResourceAmounts::fromCanonical($data['launch_cargo']));
    }
}
