<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum Technology: string
{
    case Spy = 'spy';
    case Energy = 'energy';
    case Combustion = 'combustion';
    case Impulse = 'impulse';
    case Expedition = 'expedition';

    public function legacyId(): int
    {
        return match ($this) {
            self::Spy => 106,
            self::Energy => 113,
            self::Combustion => 115,
            self::Impulse => 117,
            self::Expedition => 124,
        };
    }

    public function baseCost(): array
    {
        return match ($this) {
            self::Spy => [200, 1000, 200],
            self::Energy => [0, 800, 400],
            self::Combustion => [400, 0, 600],
            self::Impulse => [2000, 4000, 600],
            self::Expedition => [4000, 8000, 4000],
        };
    }

    public function prerequisites(): array
    {
        return match ($this) {
            self::Spy => [[BuildingPrerequisite::laboratory(), 3]],
            self::Energy => [[BuildingPrerequisite::laboratory(), 1]],
            self::Combustion => [[BuildingPrerequisite::laboratory(), 1], [self::Energy, 1]],
            self::Impulse => [[BuildingPrerequisite::laboratory(), 2], [self::Energy, 1]],
            self::Expedition => [[BuildingPrerequisite::laboratory(), 3], [self::Spy, 3], [self::Impulse, 3]],
        };
    }
}
