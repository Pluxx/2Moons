<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigRational;

final readonly class EconomySettings
{
    public function __construct(
        public BigRational $gameSpeed,
        public BigRational $resourceSpeed,
        public BigRational $storageMultiplier,
        public BigRational $energySpeed,
        public BigRational $maximumOverflow,
        public int $minimumBuildSeconds,
        public int $roboticsLevel,
        public int $naniteLevel,
        public int $homeFields,
        public int $maximumLevel,
        public int $queueCapacity,
        public int $homeTemperatureMax,
        public ResourceAmounts $startingResources,
        public ResourceRates $basicIncomePerHour,
    ) {
        foreach ([$gameSpeed, $resourceSpeed, $storageMultiplier, $energySpeed, $maximumOverflow] as $setting) {
            if ($setting->isNegative()) {
                throw new EconomyDataException('Economy multipliers cannot be negative.');
            }
        }
        if ($gameSpeed->isZero() || $minimumBuildSeconds < 1 || $roboticsLevel < 0 || $naniteLevel < 0
            || $homeFields < 1 || $maximumLevel < 1 || $maximumLevel > 255 || $queueCapacity < 1 || $queueCapacity > 5) {
            throw new EconomyDataException('Economy settings are outside the supported domain range.');
        }
    }

    public static function defaults(): self
    {
        return new self(
            BigRational::of('2500'),
            BigRational::one(),
            BigRational::one(),
            BigRational::one(),
            BigRational::one(),
            1,
            0,
            0,
            163,
            255,
            5,
            40,
            ResourceAmounts::fromStrings('500', '500', '0'),
            ResourceRates::fromStrings('20', '10', '0'),
        );
    }
}
