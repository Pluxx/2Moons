<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\ResourceAmounts;
use App\Domain\Economy\EconomyDataException;
use Brick\Math\BigInteger;

final readonly class ShipyardBatch
{
    public function __construct(
        public string $commandToken,
        public Ship $ship,
        public int $quantity,
        public ResourceAmounts $cost,
        public int $enqueuedAt,
        public ?int $startedAt = null,
        public ?int $unitSeconds = null,
        public int $produced = 0,
        public ?int $completesAt = null,
        public ?int $resolvedAt = null,
        public ?string $status = null,
    ) {
        if ($commandToken === '' || $quantity < 1 || $quantity > 1_000_000 || $produced < 0 || $produced > $quantity
            || (($startedAt === null) !== ($unitSeconds === null))
            || (($startedAt === null) !== ($completesAt === null))
            || ($unitSeconds !== null && $unitSeconds < 1)
            || ($startedAt !== null && ($startedAt < $enqueuedAt
                || $status !== 'active' || $resolvedAt !== null
                || !BigInteger::of($startedAt)->plus(BigInteger::of($unitSeconds)->multipliedBy($quantity))->isEqualTo($completesAt)))
            || ($startedAt === null && ($produced !== 0 || $status !== null || $resolvedAt !== null))) {
            throw new EconomyDataException('Shipyard batch metadata is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['token' => $this->commandToken, 'ship' => $this->ship->value, 'quantity' => $this->quantity,
            'cost' => $this->cost->toCanonicalArray(), 'enqueued_at' => $this->enqueuedAt, 'started_at' => $this->startedAt,
            'unit_seconds' => $this->unitSeconds, 'produced' => $this->produced, 'completes_at' => $this->completesAt,
            'resolved_at' => $this->resolvedAt, 'status' => $this->status];
    }

    public static function fromArray(array $data): self
    {
        $keys = ['token','ship','quantity','cost','enqueued_at','started_at','unit_seconds','produced','completes_at','resolved_at','status'];
        $actual = array_keys($data); sort($keys); sort($actual);
        if ($actual !== $keys || !is_string($data['token']) || !is_string($data['ship']) || !is_int($data['quantity'])
            || !is_array($data['cost']) || !is_int($data['enqueued_at']) || !is_int($data['produced'])
            || (!is_int($data['started_at']) && $data['started_at'] !== null)
            || (!is_int($data['unit_seconds']) && $data['unit_seconds'] !== null)
            || (!is_int($data['completes_at']) && $data['completes_at'] !== null)
            || (!is_int($data['resolved_at']) && $data['resolved_at'] !== null)
            || ($data['status'] !== null && !is_string($data['status']))) {
            throw new EconomyDataException('Malformed serialized shipyard batch.');
        }
        $ship = Ship::tryFrom($data['ship']);
        if ($ship === null) { throw new EconomyDataException('Unknown serialized ship.'); }
        return new self($data['token'], $ship, $data['quantity'], ResourceAmounts::fromCanonical($data['cost']), $data['enqueued_at'],
            $data['started_at'], $data['unit_seconds'], $data['produced'], $data['completes_at'], $data['resolved_at'], $data['status']);
    }
}
