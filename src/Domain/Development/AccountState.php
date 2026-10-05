<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;
use Brick\Math\BigInteger;

final readonly class AccountState
{
    /** @param array<string,int> $research @param list<ResearchEntry> $researchQueue @param array<string,AccountPlanet> $planets @param list<FleetState> $fleets */
    public function __construct(
        public string $ownerId,
        public int $settledAt,
        public array $research,
        public array $researchQueue,
        public array $planets,
        public array $fleets = [],
    ) {
        if ($ownerId === '' || !array_is_list($researchQueue) || !is_array($planets) || !array_is_list($fleets) || count($researchQueue) > 2) {
            throw new EconomyDataException('Account aggregate metadata is invalid.');
        }
        $keys = array_keys($research);
        sort($keys);
        $expected = array_map(static fn (Technology $technology): string => $technology->value, Technology::cases());
        sort($expected);
        if ($keys !== $expected) {
            throw new EconomyDataException('Persisted account research must contain all five technology keys.');
        }
        foreach ($research as $level) {
            if (!is_int($level) || $level < 0 || $level > 255) {
                throw new EconomyDataException('Research levels must be integers in 0..255.');
            }
        }
        $coordinates = [];
        $references = [];
        foreach ($planets as $key => $planet) {
            if (!$planet instanceof AccountPlanet || (string) $key !== $planet->reference || $planet->economy->lastSettledAt !== $settledAt) {
                throw new EconomyDataException('Account planet keys and settlement timestamps must align with the account.');
            }
            if (isset($coordinates[$planet->coordinates->key()]) || isset($references[$planet->reference])) {
                throw new EconomyDataException('Account planet coordinates and references must be unique.');
            }
            $coordinates[$planet->coordinates->key()] = true;
            $references[$planet->reference] = true;
        }
        $fleetTokens = [];
        $activeFleets = 0;
        foreach ($fleets as $fleet) {
            if (!$fleet instanceof FleetState || !isset($planets[$fleet->sourcePlanet])) {
                throw new EconomyDataException('Fleet references a missing origin planet.');
            }
            if (isset($fleetTokens[$fleet->commandToken])) { throw new EconomyDataException('Fleet command tokens must be unique.'); }
            $fleetTokens[$fleet->commandToken] = true;
            if ($fleet->status !== 'complete' && ++$activeFleets > 1) { throw new EconomyDataException('Only one active fleet is allowed per account.'); }
            if ($fleet->departedAt > $settledAt
                || ($fleet->status === 'outbound' && $fleet->arrivesAt < $settledAt)
                || ($fleet->status === 'returning' && ($fleet->arrivesAt > $settledAt || $fleet->returnsAt < $settledAt))
                || ($fleet->status === 'complete' && ($fleet->arrivesAt > $settledAt || $fleet->resolvedAt > $settledAt
                    || !in_array($fleet->resolvedAt, [$fleet->arrivesAt, $fleet->returnsAt], true)))
                || ($fleet->status !== 'outbound' && $fleet->arrivalResolvedAt !== $fleet->arrivesAt)) {
                throw new EconomyDataException('Fleet status and account settlement time are inconsistent.');
            }
        }
        foreach ($planets as $reference => $planet) {
            foreach (Ship::cases() as $ship) {
                $future = BigInteger::of($planet->ships[$ship->value]);
                foreach ($planet->batches as $batch) {
                    if ($batch->ship === $ship) { $future = $future->plus($batch->quantity - $batch->produced); }
                }
                foreach ($fleets as $fleet) {
                    if ($fleet->sourcePlanet === (string) $reference && $fleet->status !== 'complete') {
                        $future = $future->plus($fleet->ships[$ship->value]);
                    }
                }
                if ($future->compareTo(PHP_INT_MAX) > 0) {
                    throw new EconomyDataException('Predictable ship inventory addition exceeds signed 64-bit range.');
                }
            }
        }
        $previousEnqueued = null;
        $researchTokens = [];
        foreach ($researchQueue as $index => $entry) {
            if (!$entry instanceof ResearchEntry || ($entry->active() !== ($index === 0))
                || !isset($planets[$entry->sourcePlanet]) || $entry->enqueuedAt > $settledAt
                || ($previousEnqueued !== null && $entry->enqueuedAt < $previousEnqueued)
                || ($entry->active() && ($entry->startedAt > $settledAt || $entry->completesAt < $settledAt))) {
                throw new EconomyDataException('Research queue ordering, source, or timeline metadata is invalid.');
            }
            if (isset($researchTokens[$entry->commandToken])) { throw new EconomyDataException('Research command tokens must be unique.'); }
            $researchTokens[$entry->commandToken] = true;
            if ($index > 0 && $researchQueue[0]->startedAt !== null && $entry->enqueuedAt < $researchQueue[0]->startedAt) {
                throw new EconomyDataException('Waiting research cannot predate its active queue head.');
            }
            $expectedTarget = $research[$entry->technology->value];
            foreach (array_slice($researchQueue, 0, $index) as $prior) {
                if ($prior->technology === $entry->technology) { ++$expectedTarget; }
            }
            if ($entry->targetLevel !== $expectedTarget + 1) {
                throw new EconomyDataException('Research targets must form a consecutive chain per technology.');
            }
            $previousEnqueued = $entry->enqueuedAt;
        }
    }

    public static function withResearch(string $ownerId, int $timestamp, array $planets): self
    {
        $levels = [];
        foreach (Technology::cases() as $technology) { $levels[$technology->value] = 0; }
        return new self($ownerId, $timestamp, $levels, [], $planets);
    }

    public function evolve(?int $settledAt = null, ?array $research = null, ?array $researchQueue = null, ?array $planets = null, ?array $fleets = null): self
    {
        return new self($this->ownerId, $settledAt ?? $this->settledAt, $research ?? $this->research,
            $researchQueue ?? $this->researchQueue, $planets ?? $this->planets, $fleets ?? $this->fleets);
    }

    public function toArray(): array
    {
        $planets = [];
        foreach ($this->planets as $reference => $planet) { $planets[(string) $reference] = $planet->toArray(); }
        return ['owner_id' => $this->ownerId, 'settled_at' => $this->settledAt, 'research' => $this->research,
            'research_queue' => array_map(static fn (ResearchEntry $entry): array => $entry->toArray(), $this->researchQueue),
            'planets' => $planets, 'fleets' => array_map(static fn (FleetState $fleet): array => $fleet->toArray(), $this->fleets)];
    }

    public static function fromArray(array $data): self
    {
        $keys = ['owner_id','settled_at','research','research_queue','planets','fleets'];
        if (array_keys($data) !== $keys || !is_string($data['owner_id'] ?? null) || !is_int($data['settled_at'] ?? null)
            || !is_array($data['research'] ?? null) || !is_array($data['research_queue'] ?? null) || !array_is_list($data['research_queue'])
            || !is_array($data['planets'] ?? null) || !is_array($data['fleets'] ?? null) || !array_is_list($data['fleets'])) {
            throw new EconomyDataException('Malformed serialized account state.');
        }
        $queue = array_map(static fn (array $row): ResearchEntry => ResearchEntry::fromArray($row), $data['research_queue']);
        $planets = [];
        foreach ($data['planets'] as $reference => $row) {
            if (!is_array($row)) { throw new EconomyDataException('Malformed serialized account planet map.'); }
            $planet = AccountPlanet::fromArray($row);
            if ((string) $reference !== $planet->reference) { throw new EconomyDataException('Serialized account planet key/reference mismatch.'); }
            $planets[$planet->reference] = $planet;
        }
        $fleets = array_map(static fn (array $row): FleetState => FleetState::fromArray($row), $data['fleets']);
        return new self($data['owner_id'], $data['settled_at'], $data['research'], $queue, $planets, $fleets);
    }
}
