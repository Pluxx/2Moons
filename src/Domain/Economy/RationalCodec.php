<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigRational;

final class RationalCodec
{
    public static function canonical(BigRational $value): string
    {
        return $value->getNumerator().'/' .$value->getDenominator();
    }

    public static function parseCanonicalBalance(string $value): BigRational
    {
        if (preg_match('/\A(0|[1-9][0-9]*)\/([1-9][0-9]*)\z/D', $value, $matches) !== 1) {
            throw new EconomyDataException('Balance must use a nonnegative canonical numerator/denominator string.');
        }

        $rational = BigRational::ofFraction($matches[1], $matches[2]);
        if (self::canonical($rational) !== $value) {
            throw new EconomyDataException('Balance rational is not reduced to canonical form.');
        }

        return $rational;
    }
}
