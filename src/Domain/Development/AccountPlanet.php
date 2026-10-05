<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\PlanetEconomyState;

final readonly class AccountPlanet
{
    /** @param array<string,int> $ships @param list<ShipyardBatch> $batches */
    public function __construct(
        public string $reference,
        public Coordinates $coordinates,
        public int $bornAt,
        public PlanetEconomyState $economy,
        public array $ships = ['small_cargo' => 0, 'colony_ship' => 0],
        public array $batches = [],
    ) {
        if ($reference === '' || $bornAt > $economy->lastSettledAt || !is_array($ships) || !is_array($batches) || !array_is_list($batches)) {
            throw new EconomyDataException('Account planet reference or timeline metadata is invalid.');
        }
        $keys = array_keys($ships);
        sort($keys);
        if ($keys !== ['colony_ship', 'small_cargo']) {
            throw new EconomyDataException('Account planet must contain exactly both ship inventory keys.');
        }
        foreach ($ships as $count) {
            if (!is_int($count) || $count < 0) {
                throw new EconomyDataException('Ship inventory must be nonnegative whole counts.');
            }
        }
        if (count($batches) > 10) {
            throw new EconomyDataException('Shipyard queue exceeds ten batches including active work.');
        }
        $seenTokens = [];
        $previousEnqueued = null;
        foreach ($batches as $index => $batch) {
            if (!$batch instanceof ShipyardBatch || (($batch->startedAt !== null) !== ($index === 0))
                || $batch->enqueuedAt > $economy->lastSettledAt || ($previousEnqueued !== null && $batch->enqueuedAt < $previousEnqueued)
                || ($batch->startedAt !== null && ($batch->startedAt > $economy->lastSettledAt || $batch->completesAt < $economy->lastSettledAt))
                || isset($seenTokens[$batch->commandToken])) {
                throw new EconomyDataException('Shipyard batch queue must have exactly one active head.');
            }
            if ($batch->startedAt !== null) {
                $earned = intdiv($economy->lastSettledAt - $batch->startedAt, $batch->unitSeconds);
                if ($batch->produced > min($batch->quantity, $earned)) {
                    throw new EconomyDataException('Shipyard batch production exceeds elapsed scheduled work.');
                }
            }
            $seenTokens[$batch->commandToken] = true;
            $previousEnqueued = $batch->enqueuedAt;
        }
    }

    public function evolve(?PlanetEconomyState $economy = null, ?array $ships = null, ?array $batches = null): self
    {
        return new self($this->reference, $this->coordinates, $this->bornAt, $economy ?? $this->economy, $ships ?? $this->ships, $batches ?? $this->batches);
    }

    public function toArray(): array
    {
        return ['reference' => $this->reference, 'coordinates' => $this->coordinates->toArray(), 'born_at' => $this->bornAt,
            'economy' => $this->economy->toArray(), 'ships' => $this->ships,
            'batches' => array_map(static fn (ShipyardBatch $batch): array => $batch->toArray(), $this->batches)];
    }

    public static function fromArray(array $data): self
    {
        $keys = ['reference','coordinates','born_at','economy','ships','batches'];
        if (array_keys($data) !== $keys || !is_string($data['reference'] ?? null) || !is_array($data['coordinates'] ?? null)
            || !is_int($data['born_at'] ?? null) || !is_array($data['economy'] ?? null) || !is_array($data['ships'] ?? null)
            || !is_array($data['batches'] ?? null) || !array_is_list($data['batches'])) {
            throw new EconomyDataException('Malformed serialized account planet.');
        }
        $batches = array_map(static fn (array $row): ShipyardBatch => ShipyardBatch::fromArray($row), $data['batches']);
        return new self($data['reference'], Coordinates::fromArray($data['coordinates']), $data['born_at'],
            PlanetEconomyState::fromArray($data['economy']), $data['ships'], $batches);
    }
}
