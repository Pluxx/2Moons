<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class ConstructionOutcome
{
    public function __construct(
        public string $commandToken,
        public Building $building,
        public int $targetLevel,
        public OutcomeStatus $status,
        public int $resolvedAt,
        public ?string $failureReason = null,
        public ?int $startedAt = null,
        public ?int $completesAt = null,
    ) {
        if ($status === OutcomeStatus::Completed) {
            if ($failureReason !== null || $startedAt === null || $completesAt === null
                || $startedAt > $completesAt || $completesAt !== $resolvedAt) {
                throw new EconomyDataException('Completed construction outcomes require their valid scheduled execution interval.');
            }
        } elseif ($failureReason === null || $failureReason === '' || $startedAt !== null || $completesAt !== null) {
            throw new EconomyDataException('Failed construction outcomes require a reason and no execution interval.');
        }
    }

    public static function completed(
        string $commandToken,
        Building $building,
        int $targetLevel,
        int $startedAt,
        int $completesAt,
    ): self {
        return new self(
            $commandToken,
            $building,
            $targetLevel,
            OutcomeStatus::Completed,
            $completesAt,
            startedAt: $startedAt,
            completesAt: $completesAt,
        );
    }

    public static function failed(
        string $commandToken,
        Building $building,
        int $targetLevel,
        int $resolvedAt,
        string $failureReason,
    ): self {
        return new self(
            $commandToken,
            $building,
            $targetLevel,
            OutcomeStatus::Failed,
            $resolvedAt,
            $failureReason,
        );
    }

    /** @return array{command_token: string, building: string, target_level: int, status: string, resolved_at: int, failure_reason: ?string, started_at: ?int, completes_at: ?int} */
    public function toArray(): array
    {
        return [
            'command_token' => $this->commandToken,
            'building' => $this->building->value,
            'target_level' => $this->targetLevel,
            'status' => $this->status->value,
            'resolved_at' => $this->resolvedAt,
            'failure_reason' => $this->failureReason,
            'started_at' => $this->startedAt,
            'completes_at' => $this->completesAt,
        ];
    }
}
