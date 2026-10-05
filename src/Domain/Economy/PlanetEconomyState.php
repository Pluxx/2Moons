<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class PlanetEconomyState
{
    /** @param list<ConstructionEntry> $pendingEntries */
    public function __construct(
        public ResourceAmounts $resources,
        public BuildingLevels $levels,
        public int $temperatureMax,
        public int $fieldsTotal,
        public int $fieldsUsed,
        public int $lastSettledAt,
        public array $pendingEntries = [],
    ) {
        if (!array_is_list($pendingEntries) || $fieldsTotal < 0 || $fieldsUsed < 0 || $fieldsUsed > $fieldsTotal || count($pendingEntries) > 5
            || $fieldsUsed + count($pendingEntries) > $fieldsTotal) {
            throw new EconomyDataException('Planet fields or pending queue violate domain limits.');
        }

        $completedLevels = array_sum($this->levels->toArray());
        if ($fieldsUsed !== $completedLevels) {
            throw new EconomyDataException('Occupied fields must equal the sum of completed building levels.');
        }

        $seenTokens = [];
        $priorForBuilding = [];
        $previousEnqueuedAt = null;
        foreach ($pendingEntries as $index => $entry) {
            if (!$entry instanceof ConstructionEntry) {
                throw new EconomyDataException('Pending construction queue contains an invalid entry.');
            }
            if ($entry->isActive() !== ($index === 0)) {
                throw new EconomyDataException('A nonempty queue must have exactly one active head.');
            }
            if (isset($seenTokens[$entry->commandToken])) {
                throw new EconomyDataException('Pending construction command tokens must be unique per planet.');
            }
            if ($entry->enqueuedAt > $this->lastSettledAt
                || ($previousEnqueuedAt !== null && $entry->enqueuedAt < $previousEnqueuedAt)) {
                throw new EconomyDataException('Pending entries must be enqueued no later than settlement and remain FIFO ordered.');
            }
            if ($entry->isActive()
                && ($entry->startedAt > $this->lastSettledAt || $entry->completesAt < $this->lastSettledAt)) {
                throw new EconomyDataException('Active construction timestamps must contain the last settlement time.');
            }
            $seenTokens[$entry->commandToken] = true;
            $previousEnqueuedAt = $entry->enqueuedAt;
            $expected = $this->levels->get($entry->building) + ($priorForBuilding[$entry->building->value] ?? 0) + 1;
            if ($entry->targetLevel !== $expected) {
                throw new EconomyDataException('Pending same-building target levels must form a consecutive chain.');
            }
            $priorForBuilding[$entry->building->value] = ($priorForBuilding[$entry->building->value] ?? 0) + 1;
        }
    }

    public static function newHome(int $timestamp, EconomySettings $settings): self
    {
        return new self(
            $settings->startingResources,
            BuildingLevels::fromArray([], $settings->maximumLevel),
            $settings->homeTemperatureMax,
            $settings->homeFields,
            0,
            $timestamp,
        );
    }

    /** @param list<ConstructionEntry>|null $pendingEntries */
    public function evolve(
        ?ResourceAmounts $resources = null,
        ?BuildingLevels $levels = null,
        ?int $fieldsUsed = null,
        ?int $lastSettledAt = null,
        ?array $pendingEntries = null,
    ): self {
        return new self(
            $resources ?? $this->resources,
            $levels ?? $this->levels,
            $this->temperatureMax,
            $this->fieldsTotal,
            $fieldsUsed ?? $this->fieldsUsed,
            $lastSettledAt ?? $this->lastSettledAt,
            $pendingEntries ?? $this->pendingEntries,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resources' => $this->resources->toCanonicalArray(),
            'levels' => $this->levels->toArray(),
            'temperature_max' => $this->temperatureMax,
            'fields_total' => $this->fieldsTotal,
            'fields_used' => $this->fieldsUsed,
            'last_settled_at' => $this->lastSettledAt,
            'pending' => array_map(static fn (ConstructionEntry $entry): array => $entry->toArray(), $this->pendingEntries),
        ];
    }

    public static function fromArray(array $data): self
    {
        if (!is_array($data['resources'] ?? null) || !is_array($data['levels'] ?? null)
            || !is_int($data['temperature_max'] ?? null) || !is_int($data['fields_total'] ?? null)
            || !is_int($data['fields_used'] ?? null) || !is_int($data['last_settled_at'] ?? null)
            || !is_array($data['pending'] ?? null)) {
            throw new EconomyDataException('Malformed serialized planet economy state.');
        }

        $entries = [];
        if (!array_is_list($data['pending'])) {
            throw new EconomyDataException('Serialized pending queue must be a canonical ordered list.');
        }
        foreach ($data['pending'] as $entry) {
            if (!is_array($entry)) {
                throw new EconomyDataException('Malformed serialized pending queue.');
            }
            $entries[] = ConstructionEntry::fromArray($entry);
        }

        return new self(
            ResourceAmounts::fromCanonical($data['resources']),
            BuildingLevels::fromCompleteArray($data['levels']),
            $data['temperature_max'],
            $data['fields_total'],
            $data['fields_used'],
            $data['last_settled_at'],
            $entries,
        );
    }
}
