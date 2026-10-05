<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class EconomyResult
{
    /** @param list<ConstructionOutcome> $outcomes */
    private function __construct(
        public PlanetEconomyState $state,
        public array $outcomes,
        public ?EconomyRejection $rejection,
    ) {
    }

    public static function accepted(PlanetEconomyState $state, array $outcomes = []): self
    {
        return new self($state, $outcomes, null);
    }

    public static function rejected(PlanetEconomyState $original, RejectionCode $code, array $context = []): self
    {
        return new self($original, [], new EconomyRejection($code, $context));
    }

    public function isAccepted(): bool
    {
        return $this->rejection === null;
    }
}
