<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigRational;

final readonly class ProductionSnapshot
{
    public function __construct(
        public BigRational $generatedEnergy,
        public BigRational $rawGeneratedEnergy,
        public BigRational $energyDemand,
        public ?BigRational $productionFactor,
        public ResourceRates $grossMineRatesPerHour,
        public ResourceRates $totalRatesPerHour,
        public ResourceAmounts $capacities,
    ) {
    }
}
