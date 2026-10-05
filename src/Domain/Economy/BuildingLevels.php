<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class BuildingLevels
{
    /** @param array<string, int> $levels */
    private function __construct(private array $levels)
    {
    }

    /** Missing values are the explicit level-zero fixture/catalog defaults. */
    public static function fromArray(array $levels, int $maximumLevel = 255): self
    {
        foreach ($levels as $name => $level) {
            if (Building::tryFrom((string) $name) === null) {
                throw new EconomyDataException(sprintf('Unknown building level key "%s".', $name));
            }
            if (!is_int($level) || $level < 0 || $level > $maximumLevel) {
                throw new EconomyDataException(sprintf('Building level for "%s" is out of range.', $name));
            }
        }

        $complete = [];
        foreach (Building::cases() as $building) {
            $complete[$building->value] = $levels[$building->value] ?? 0;
        }

        return new self($complete);
    }

    /** Strict persisted-state rehydration: unlike fromArray(), no level may be omitted. */
    public static function fromCompleteArray(array $levels, int $maximumLevel = 255): self
    {
        $complete = self::fromArray($levels, $maximumLevel);
        if (count($levels) !== count(Building::cases())) {
            throw new EconomyDataException('Persisted building levels must contain all ten building keys.');
        }

        return $complete;
    }

    public function get(Building $building): int
    {
        return $this->levels[$building->value];
    }

    public function with(Building $building, int $level): self
    {
        if ($level < 0 || $level > $building->maximumLevel()) {
            throw new EconomyDataException('Building level is outside the supported catalogue range.');
        }

        $levels = $this->levels;
        $levels[$building->value] = $level;

        return new self($levels);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return $this->levels;
    }
}
