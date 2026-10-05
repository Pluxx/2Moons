<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\Building;

final readonly class BuildingPrerequisite
{
    private function __construct(public Building $building)
    {
    }

    public static function laboratory(): self
    {
        return new self(Building::Laboratory);
    }
}
