<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\ResourceAmounts;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

final readonly class ShipyardCalculator
{
    public function priceFor(Ship $ship, int $quantity): ResourceAmounts
    {
        if ($quantity < 1 || $quantity > 1_000_000) {
            throw new EconomyDataException('Ship batch quantity is outside 1..1000000.');
        }
        [$metal, $crystal, $deuterium] = $ship->baseCost();

        return ResourceAmounts::fromStrings(
            BigInteger::of($metal)->multipliedBy($quantity)->__toString(),
            BigInteger::of($crystal)->multipliedBy($quantity)->__toString(),
            BigInteger::of($deuterium)->multipliedBy($quantity)->__toString(),
        );
    }

    public function unitDurationSeconds(Ship $ship, int $quantity, int $shipyardLevel): int
    {
        if ($quantity < 1 || $quantity > 1_000_000 || $shipyardLevel < 0 || $shipyardLevel > 255) {
            throw new EconomyDataException('Ship batch parameters are outside their domain limits.');
        }
        $cost = $this->priceFor($ship, 1);
        $sum = $cost->get(\App\Domain\Economy\Resource::Metal)->plus($cost->get(\App\Domain\Economy\Resource::Crystal));
        $seconds = $sum->dividedBy(2500 * (1 + $shipyardLevel))->multipliedBy(3600)->toScale(0, RoundingMode::Floor)->toBigInteger();
        if ($seconds->isLessThan(1)) { $seconds = BigInteger::one(); }

        return $seconds->toInt();
    }
}
