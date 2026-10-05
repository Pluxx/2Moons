<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;

final readonly class ColonyRules
{
    public function requiredExpeditionLevel(int $position): int
    {
        return match ($position) { 1, 15 => 8, 2, 14 => 6, 3, 13 => 4, 4, 5, 6, 7, 8, 9, 10, 11, 12 => 1,
            default => throw new EconomyDataException('Colony position must be within 1..15.') };
    }

    public function maximumOwnedPlanets(int $expeditionLevel): int
    {
        if ($expeditionLevel < 0 || $expeditionLevel > 255) { throw new EconomyDataException('Expedition level is outside 0..255.'); }
        return min(20, 9 + intdiv($expeditionLevel + 1, 2));
    }

    /** @return array{int,int,int,int} max-temperature low/high, fields low/high */
    public function climateBand(int $position): array
    {
        return match ($position) {
            1 => [220, 260, 95, 108], 2 => [170, 210, 97, 110], 3 => [120, 160, 98, 137],
            4 => [70, 110, 123, 203], 5 => [60, 100, 148, 210], 6 => [50, 90, 148, 226],
            7 => [40, 80, 141, 273], 8 => [30, 70, 169, 246], 9 => [20, 60, 161, 238],
            10 => [10, 50, 154, 224], 11 => [0, 40, 148, 204], 12 => [-10, 30, 136, 171],
            13 => [-50, -10, 109, 121], 14 => [-90, -50, 81, 93], 15 => [-130, -90, 65, 74],
            default => throw new EconomyDataException('Colony position must be within 1..15.'),
        };
    }

    public function validateDraw(int $position, int $maximumTemperature, int $fields): bool
    {
        [$minTemperature, $maxTemperature, $minFields, $maxFields] = $this->climateBand($position);
        return $maximumTemperature >= $minTemperature && $maximumTemperature <= $maxTemperature
            && $fields >= $minFields && $fields <= $maxFields;
    }
}
