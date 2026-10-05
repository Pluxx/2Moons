<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

final readonly class ResearchCalculator
{
    public function priceFor(Technology $technology, int $targetLevel): ResourceAmounts
    {
        if ($targetLevel < 1 || $targetLevel > 255) {
            throw new EconomyDataException('Research target is outside levels 1..255.');
        }
        [$metal, $crystal, $deuterium] = $technology->baseCost();
        $factor = $technology === Technology::Expedition
            ? BigRational::ofFraction(7, 4)->power($targetLevel)
            : BigRational::of(2)->power($targetLevel);

        return new ResourceAmounts(
            BigRational::of($metal)->multipliedBy($factor),
            BigRational::of($crystal)->multipliedBy($factor),
            BigRational::of($deuterium)->multipliedBy($factor),
        );
    }

    public function durationSeconds(ResourceAmounts $cost, int $laboratoryLevel): int
    {
        if ($laboratoryLevel < 0 || $laboratoryLevel > 255) {
            throw new EconomyDataException('Laboratory level is outside levels 0..255.');
        }
        $sum = $cost->get(Resource::Metal)->plus($cost->get(Resource::Crystal));
        $seconds = $sum->dividedBy(1000 * (1 + $laboratoryLevel))->multipliedBy(3600)->toScale(0, RoundingMode::Floor)->toBigInteger();
        if ($seconds->isLessThan(1)) { $seconds = \Brick\Math\BigInteger::one(); }
        if ($seconds->compareTo(PHP_INT_MAX) > 0) { throw new EconomyDataException('Research duration exceeds the signed 64-bit timestamp range.'); }
        return $seconds->toInt();
    }

    public function meetsPrerequisites(Technology $technology, array $research, int $laboratoryLevel): bool
    {
        foreach ($technology->prerequisites() as [$prerequisite, $level]) {
            if ($prerequisite instanceof BuildingPrerequisite && $laboratoryLevel < $level) {
                return false;
            }
            if ($prerequisite instanceof Technology && ($research[$prerequisite->value] ?? 0) < $level) {
                return false;
            }
        }

        return true;
    }
}
