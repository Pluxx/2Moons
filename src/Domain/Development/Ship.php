<?php

declare(strict_types=1);

namespace App\Domain\Development;

use Brick\Math\BigRational;

enum Ship: string
{
    case SmallCargo = 'small_cargo';
    case ColonyShip = 'colony_ship';

    public function legacyId(): int
    {
        return $this === self::SmallCargo ? 202 : 208;
    }

    public function baseCost(): array
    {
        return $this === self::SmallCargo ? [2000, 2000, 0] : [10000, 20000, 10000];
    }

    public function baseSpeed(): int
    {
        return $this === self::SmallCargo ? 5000 : 2500;
    }

    public function consumption(int $combustion, int $impulse): int
    {
        return $this === self::ColonyShip ? 1000 : ($impulse >= 5 ? 20 : 10);
    }

    public function capacity(): int
    {
        return $this === self::SmallCargo ? 5000 : 7500;
    }

    public function speed(int $combustion, int $impulse): BigRational
    {
        return match ($this) {
            self::SmallCargo => BigRational::of(5000)->multipliedBy($impulse >= 5
                ? BigRational::of(1)->plus(BigRational::ofFraction($impulse, 5))
                : BigRational::of(1)->plus(BigRational::ofFraction($combustion, 10))),
            self::ColonyShip => BigRational::of(2500)->multipliedBy(BigRational::of(1)->plus(BigRational::ofFraction($impulse, 5))),
        };
    }
}
