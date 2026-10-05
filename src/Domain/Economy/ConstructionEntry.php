<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class ConstructionEntry
{
    private function __construct(
        public string $commandToken,
        public Building $building,
        public int $targetLevel,
        public int $enqueuedAt,
        public ?int $startedAt,
        public ?int $completesAt,
    ) {
        if ($commandToken === '' || $targetLevel < 1 || $targetLevel > $building->maximumLevel()) {
            throw new EconomyDataException('Construction entry has invalid identity or target level.');
        }
        if (($startedAt === null) !== ($completesAt === null)) {
            throw new EconomyDataException('Active construction timestamps must be present together.');
        }
        if ($startedAt !== null && ($startedAt < $enqueuedAt || $completesAt <= $startedAt)) {
            throw new EconomyDataException('Active construction timestamps are inconsistent.');
        }
    }

    public static function active(
        string $commandToken,
        Building $building,
        int $targetLevel,
        int $enqueuedAt,
        int $startedAt,
        int $completesAt,
    ): self {
        return new self($commandToken, $building, $targetLevel, $enqueuedAt, $startedAt, $completesAt);
    }

    public static function waiting(string $commandToken, Building $building, int $targetLevel, int $enqueuedAt): self
    {
        return new self($commandToken, $building, $targetLevel, $enqueuedAt, null, null);
    }

    public function isActive(): bool
    {
        return $this->startedAt !== null;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'command_token' => $this->commandToken,
            'building' => $this->building->value,
            'target_level' => $this->targetLevel,
            'enqueued_at' => $this->enqueuedAt,
            'started_at' => $this->startedAt,
            'completes_at' => $this->completesAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        $expectedKeys = ['command_token', 'building', 'target_level', 'enqueued_at', 'started_at', 'completes_at'];
        $actualKeys = array_keys($data);
        sort($expectedKeys);
        sort($actualKeys);
        if ($expectedKeys !== $actualKeys) {
            throw new EconomyDataException('Malformed construction entry shape.');
        }

        $building = is_string($data['building'] ?? null) ? Building::tryFrom($data['building']) : null;
        if ($building === null || !is_string($data['command_token'] ?? null)
            || !is_int($data['target_level'] ?? null) || !is_int($data['enqueued_at'] ?? null)
            || (!is_int($data['started_at'] ?? null) && ($data['started_at'] ?? null) !== null)
            || (!is_int($data['completes_at'] ?? null) && ($data['completes_at'] ?? null) !== null)) {
            throw new EconomyDataException('Malformed construction entry.');
        }

        return new self(
            $data['command_token'],
            $building,
            $data['target_level'],
            $data['enqueued_at'],
            $data['started_at'] ?? null,
            $data['completes_at'] ?? null,
        );
    }
}
