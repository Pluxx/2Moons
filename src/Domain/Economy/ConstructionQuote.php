<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class ConstructionQuote
{
    public function __construct(
        public Building $building,
        public int $targetLevel,
        public ResourceAmounts $cost,
        public int $durationSeconds,
        public int $projectedStartAt,
        public int $projectedCompletionAt,
        public bool $affordableNow,
        public bool $queueSlotAvailable,
        public bool $fieldAvailable,
    ) {
    }
}
