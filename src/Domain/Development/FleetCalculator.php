<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

final readonly class FleetCalculator
{
    /** @param array<string,int> $ships @return array{distance:int,duration_bounds:array{string,string},arrival_offset:int,return_offset:int,fuel:int,capacity:int} */
    public function calculate(Coordinates $from, Coordinates $to, array $ships, array $research, int $speedIndex): array
    {
        if ($speedIndex < 1 || $speedIndex > 10 || $ships === []) {
            throw new EconomyDataException('Fleet requires ships and a speed selector from 1 through 10.');
        }
        if ($from->key() === $to->key()) {
            throw new EconomyDataException('A fleet cannot target its source coordinate.');
        }
        $distance = $from->distanceTo($to);
        $combustion = $research[Technology::Combustion->value] ?? 0;
        $impulse = $research[Technology::Impulse->value] ?? 0;
        $normalized = [];
        foreach ($ships as $key => $count) {
            $ship = is_string($key) ? Ship::tryFrom($key) : null;
            if ($ship === null || !is_int($count) || $count < 0) {
                throw new EconomyDataException('Fleet ship counts must use known ship keys and nonnegative integers.');
            }
            if ($count > 0) {
                $normalized[$ship->value] = [$ship, $count];
            }
        }
        if ($normalized === []) {
            throw new EconomyDataException('At least one ship is required.');
        }

        $slowest = null;
        foreach ($normalized as [$ship]) {
            $speed = $ship->speed($combustion, $impulse);
            $slowest = $slowest === null ? $speed : $slowest->min($speed);
        }
        $root = $this->sqrtBounds(BigRational::of(10 * $distance)->dividedBy($slowest));
        $factor = BigRational::ofFraction(35000, $speedIndex);
        $duration = $this->maxBounds($this->addBounds($this->multiplyBounds($root, $factor), $this->exactBounds(BigRational::of(10))), $this->exactBounds(BigRational::of(5)));
        $arrival = $this->certifiedHalfUp($duration);
        $return = $this->certifiedHalfUp($this->multiplyBounds($duration, BigRational::of(2)));
        $fuel = $this->exactBounds(BigRational::zero());
        $capacity = BigInteger::zero();
        foreach ($normalized as [$ship, $count]) {
            $shipRoot = $this->sqrtBounds(BigRational::of(10 * $distance)->dividedBy($ship->speed($combustion, $impulse)));
            $specificSpeed = $this->divideBounds($this->multiplyBounds($shipRoot, BigRational::of(35000)), BigRational::of($arrival - 10));
            $term = $this->addBounds($this->divideBounds($specificSpeed, BigRational::of(10)), BigRational::of(1));
            $term = $this->multiplyIntervals($term, $term);
            $coefficient = BigRational::of($ship->consumption($combustion, $impulse))
                ->multipliedBy($count)->multipliedBy($distance)->dividedBy(35000);
            $fuel = $this->addBounds($fuel, $this->multiplyBounds($term, $coefficient));
            $capacity = $capacity->plus(BigInteger::of($ship->capacity())->multipliedBy($count));
        }
        $fuelAmount = BigInteger::of($this->certifiedHalfUp($fuel))->plus(1);
        try {
            $fuelInt = $fuelAmount->toInt();
            $capacityInt = $capacity->toInt();
        } catch (\Brick\Math\Exception\IntegerOverflowException) {
            throw new EconomyDataException('Fleet capacity or fuel exceeds the signed 64-bit range.');
        }
        if ($arrival < 5 || $return < $arrival) {
            throw new EconomyDataException('Certified fleet schedule is invalid.');
        }

        return [
            'distance' => $distance,
            'duration_bounds' => [$duration[0]->__toString(), $duration[1]->__toString()],
            'arrival_offset' => $arrival,
            'return_offset' => $return,
            'fuel' => $fuelInt,
            'capacity' => $capacityInt,
        ];
    }

    private function sqrtBounds(BigRational $value): array
    {
        if ($value->isNegative()) {
            throw new EconomyDataException('Cannot calculate a negative fleet root.');
        }
        $numerator = $value->getNumerator();
        $denominator = $value->getDenominator();
        $nroot = $numerator->sqrt(RoundingMode::Down);
        $droot = $denominator->sqrt(RoundingMode::Down);
        if ($nroot->multipliedBy($nroot)->isEqualTo($numerator) && $droot->multipliedBy($droot)->isEqualTo($denominator)) {
            return $this->exactBounds(BigRational::of($nroot)->dividedBy($droot));
        }
        $bounds = null;
        foreach ([16, 32, 64, 128, 256] as $scale) {
            $scaled = $numerator->multipliedBy(BigInteger::of(10)->power(2 * $scale))->dividedBy($denominator, RoundingMode::Down);
            $floor = $scaled->sqrt(RoundingMode::Down);
            $unit = BigInteger::of(10)->power($scale);
            $lower = BigRational::of($floor)->dividedBy($unit);
            $upper = BigRational::of($floor->plus(1))->dividedBy($unit);
            $bounds = [$lower, $upper];
        }
        if ($bounds === null) { throw new EconomyDataException('Fleet square root could not be certified.'); }
        return $bounds;
    }

    private function exactBounds(BigRational $value): array { return [$value, $value]; }
    private function addBounds(array $a, BigRational|array $b): array
    {
        $b = $b instanceof BigRational ? $this->exactBounds($b) : $b;
        return [$a[0]->plus($b[0]), $a[1]->plus($b[1])];
    }
    private function multiplyBounds(array $a, BigRational $b): array
    {
        if ($b->isNegative()) { throw new EconomyDataException('Fleet interval multiplier must be nonnegative.'); }
        return [$a[0]->multipliedBy($b), $a[1]->multipliedBy($b)];
    }
    private function multiplyIntervals(array $a, array $b): array { return [$a[0]->multipliedBy($b[0]), $a[1]->multipliedBy($b[1])]; }
    private function divideBounds(array $a, BigRational $b): array
    {
        if ($b->isZero() || $b->isNegative()) { throw new EconomyDataException('Fleet interval denominator must be positive: '.$b); }
        return [$a[0]->dividedBy($b), $a[1]->dividedBy($b)];
    }
    private function maxBounds(array $a, array $b): array
    {
        return [
            $a[0]->compareTo($b[0]) >= 0 ? $a[0] : $b[0],
            $a[1]->compareTo($b[1]) >= 0 ? $a[1] : $b[1],
        ];
    }
    private function certifiedHalfUp(array $bounds): int
    {
        $lo = $bounds[0]->plus(BigRational::ofFraction(1, 2))->toScale(0, RoundingMode::Floor)->toBigInteger();
        $hi = $bounds[1]->plus(BigRational::ofFraction(1, 2))->toScale(0, RoundingMode::Floor)->toBigInteger();
        if (!$lo->isEqualTo($hi)) {
            throw new EconomyDataException('Fleet rounding boundary could not be certified.');
        }
        try { return $lo->toInt(); } catch (\Brick\Math\Exception\IntegerOverflowException) {
            throw new EconomyDataException('Fleet schedule exceeds the signed 64-bit range.');
        }
    }
}
