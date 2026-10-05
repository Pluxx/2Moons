<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\ResourceAmounts;
use App\Domain\Economy\EconomyDataException;

final readonly class ResearchEntry
{
    public function __construct(
        public string $commandToken,
        public Technology $technology,
        public int $targetLevel,
        public string $sourcePlanet,
        public int $enqueuedAt,
        public ?int $startedAt = null,
        public ?int $completesAt = null,
        public ?ResourceAmounts $cost = null,
        public ?string $status = null,
        public ?int $resolvedAt = null,
        public ?string $failureReason = null,
    ) {
        if ($commandToken === '' || $targetLevel < 1 || $targetLevel > 255 || $sourcePlanet === ''
            || (($startedAt === null) !== ($completesAt === null))
            || ($startedAt !== null && ($startedAt < $enqueuedAt || $completesAt <= $startedAt || $cost === null || $status !== 'active'))
            || ($startedAt === null && ($cost !== null || $status !== null || $resolvedAt !== null || $failureReason !== null))) {
            throw new EconomyDataException('Research entry identity, target, or execution times are invalid.');
        }
    }

    public function active(): bool
    {
        return $this->startedAt !== null;
    }

    public function toArray(): array
    {
        return ['token' => $this->commandToken, 'technology' => $this->technology->value, 'target_level' => $this->targetLevel,
            'source_planet' => $this->sourcePlanet, 'enqueued_at' => $this->enqueuedAt, 'started_at' => $this->startedAt,
            'completes_at' => $this->completesAt, 'cost' => $this->cost?->toCanonicalArray(), 'status' => $this->status,
            'resolved_at' => $this->resolvedAt, 'failure_reason' => $this->failureReason];
    }

    public static function fromArray(array $data): self
    {
        $keys = ['token','technology','target_level','source_planet','enqueued_at','started_at','completes_at','cost','status','resolved_at','failure_reason'];
        $actual = array_keys($data); sort($keys); sort($actual);
        if ($actual !== $keys || !is_string($data['token']) || !is_string($data['technology']) || !is_int($data['target_level'])
            || !is_string($data['source_planet']) || !is_int($data['enqueued_at'])
            || (!is_int($data['started_at']) && $data['started_at'] !== null)
            || (!is_int($data['completes_at']) && $data['completes_at'] !== null)
            || ($data['cost'] !== null && !is_array($data['cost']))
            || ($data['status'] !== null && !is_string($data['status']))
            || ($data['resolved_at'] !== null && !is_int($data['resolved_at']))
            || ($data['failure_reason'] !== null && !is_string($data['failure_reason']))) {
            throw new EconomyDataException('Malformed serialized research entry.');
        }
        $technology = Technology::tryFrom($data['technology']);
        if ($technology === null) { throw new EconomyDataException('Unknown serialized research technology.'); }
        return new self($data['token'], $technology, $data['target_level'], $data['source_planet'], $data['enqueued_at'],
            $data['started_at'], $data['completes_at'], $data['cost'] === null ? null : ResourceAmounts::fromCanonical($data['cost']),
            $data['status'], $data['resolved_at'], $data['failure_reason']);
    }
}
