<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

final class EconomyCalculator
{
    private const MAX_SECONDS = PHP_INT_MAX;
    private const MIN_SECONDS = PHP_INT_MIN;

    public function calculate(PlanetEconomyState $state, EconomySettings $settings): ProductionSnapshot
    {
        $levels = $state->levels;
        $growth = BigRational::ofFraction(11, 10);
        $percent = BigRational::one(); // The approved fixtures use 100% operation.

        $metalLevel = $levels->get(Building::MetalMine);
        $crystalLevel = $levels->get(Building::CrystalMine);
        $deuteriumLevel = $levels->get(Building::DeuteriumSynthesizer);
        $solarLevel = $levels->get(Building::SolarPlant);

        $metalBase = $this->levelCurve($metalLevel, $growth)->multipliedBy(30)->multipliedBy($percent);
        $crystalBase = $this->levelCurve($crystalLevel, $growth)->multipliedBy(20)->multipliedBy($percent);
        $temperatureFactor = BigRational::ofFraction(32, 25)->minus(BigRational::of($state->temperatureMax)->dividedBy(500));
        $deuteriumBase = $this->levelCurve($deuteriumLevel, $growth)
            ->multipliedBy(10)
            ->multipliedBy($temperatureFactor)
            ->multipliedBy($percent);

        $metalDemand = $this->levelCurve($metalLevel, $growth)->multipliedBy(10)->multipliedBy($percent);
        $crystalDemand = $this->levelCurve($crystalLevel, $growth)->multipliedBy(10)->multipliedBy($percent);
        $deuteriumDemand = $this->levelCurve($deuteriumLevel, $growth)->multipliedBy(30)->multipliedBy($percent);
        $energyDemand = $metalDemand->plus($crystalDemand)->plus($deuteriumDemand);

        $rawSolar = $this->levelCurve($solarLevel, $growth)
            ->multipliedBy(20)
            ->multipliedBy($percent)
            ->multipliedBy($settings->energySpeed);
        $generatedEnergyInteger = $rawSolar->toScale(0, RoundingMode::HalfUp)->toBigInteger();
        $generatedEnergy = BigRational::of($generatedEnergyInteger);
        $productionFactor = null;
        if (!$energyDemand->isZero()) {
            $productionFactor = $generatedEnergy->dividedBy($energyDemand);
            if ($productionFactor->compareTo(1) > 0) {
                $productionFactor = BigRational::one();
            }
        }

        // The legacy cache gives zero mine additions when no energy is demanded.
        $allocated = $productionFactor === null ? BigRational::zero() : $productionFactor;
        $gross = new ResourceRates($metalBase, $crystalBase, $deuteriumBase);
        $mineRates = $gross->multipliedBy($allocated)->multipliedBy($settings->resourceSpeed);
        $basicRates = $settings->basicIncomePerHour->multipliedBy($settings->resourceSpeed);
        $totalRates = $basicRates->plus($mineRates);

        return new ProductionSnapshot(
            $generatedEnergy,
            $rawSolar,
            $energyDemand,
            $productionFactor,
            $gross,
            $totalRates,
            $this->capacities($levels, $settings),
        );
    }

    public function priceFor(Building $building, int $targetLevel): ResourceAmounts
    {
        if ($targetLevel < 1 || $targetLevel > $building->maximumLevel()) {
            throw new EconomyDataException('Construction price target is outside the catalogue level range.');
        }

        $factorPower = $building->costFactor()->power($targetLevel);
        $base = $building->baseCost();

        return new ResourceAmounts(
            $base->get(Resource::Metal)->multipliedBy($factorPower),
            $base->get(Resource::Crystal)->multipliedBy($factorPower),
            $base->get(Resource::Deuterium)->multipliedBy($factorPower),
        );
    }

    public function buildDurationSeconds(Building $building, int $targetLevel, EconomySettings $settings, ?int $roboticsLevel = null): ?int
    {
        $cost = $this->priceFor($building, $targetLevel);
        $denominator = $settings->gameSpeed->multipliedBy(1 + ($roboticsLevel ?? $settings->roboticsLevel));
        $duration = $cost->get(Resource::Metal)
            ->plus($cost->get(Resource::Crystal))
            ->dividedBy($denominator)
            ->multipliedBy(BigRational::ofFraction(1, 2)->power($settings->naniteLevel))
            ->multipliedBy(3600);
        $wholeSeconds = $duration->toScale(0, RoundingMode::Floor)->toBigInteger();

        if ($wholeSeconds->compareTo($settings->minimumBuildSeconds) < 0) {
            return $settings->minimumBuildSeconds;
        }

        try {
            return $wholeSeconds->toInt();
        } catch (\Brick\Math\Exception\IntegerOverflowException) {
            return null;
        }
    }

    public function capacityFor(BuildingLevels $levels, Resource $resource, EconomySettings $settings): BigRational
    {
        $storage = match ($resource) {
            Resource::Metal => Building::MetalStorage,
            Resource::Crystal => Building::CrystalStorage,
            Resource::Deuterium => Building::DeuteriumStorage,
        };
        $level = $levels->get($storage);
        $formula = BigRational::ofFraction(5, 2)
            ->multipliedBy(BigRational::ofFraction(18331954764, 10000000000)->power($level));
        $flooredFactor = BigRational::of($formula->toScale(0, RoundingMode::Floor)->toBigInteger());

        return $flooredFactor
            ->multipliedBy(5000)
            ->multipliedBy($settings->storageMultiplier)
            ->multipliedBy($settings->maximumOverflow);
    }

    public function capacities(BuildingLevels $levels, EconomySettings $settings): ResourceAmounts
    {
        return new ResourceAmounts(
            $this->capacityFor($levels, Resource::Metal, $settings),
            $this->capacityFor($levels, Resource::Crystal, $settings),
            $this->capacityFor($levels, Resource::Deuterium, $settings),
        );
    }

    public function accrue(
        ResourceAmounts $balances,
        ResourceRates $ratesPerHour,
        ResourceAmounts $capacities,
        int|BigInteger $elapsedSeconds,
    ): ResourceAmounts {
        $elapsed = BigInteger::of($elapsedSeconds);
        if ($elapsed->isNegative()) {
            throw new EconomyDataException('Accrual elapsed time cannot be negative.');
        }

        $elapsedHours = BigRational::of($elapsed)->dividedBy(3600);
        $updated = $balances;
        foreach (Resource::cases() as $resource) {
            $balance = $balances->get($resource);
            $rate = $ratesPerHour->get($resource);
            $capacity = $capacities->get($resource);

            if ($rate->isPositive()) {
                if ($balance->compareTo($capacity) <= 0) {
                    $next = $balance->plus($rate->multipliedBy($elapsedHours));
                    if ($next->compareTo($capacity) > 0) {
                        $next = $capacity;
                    }
                    $updated = $updated->with($resource, $next);
                }
            } elseif ($rate->isNegative()) {
                $next = $balance->plus($rate->multipliedBy($elapsedHours));
                if ($next->isNegative()) {
                    $next = BigRational::zero();
                }
                $updated = $updated->with($resource, $next);
            }
        }

        return $updated;
    }

    public function checkedTimestampAdd(int $timestamp, int $seconds): ?int
    {
        $sum = BigInteger::of($timestamp)->plus($seconds);
        if ($sum->compareTo(self::MAX_SECONDS) > 0 || $sum->compareTo(self::MIN_SECONDS) < 0) {
            return null;
        }

        return $sum->toInt();
    }

    private function levelCurve(int $level, BigRational $growth): BigRational
    {
        if ($level === 0) {
            return BigRational::zero();
        }

        return BigRational::of($level)->multipliedBy($growth->power($level));
    }
}
