<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;

final readonly class Coordinates
{
    public function __construct(public int $galaxy, public int $system, public int $position)
    {
        if ($galaxy < 1 || $galaxy > 9 || $system < 1 || $system > 400 || $position < 1 || $position > 15) {
            throw new EconomyDataException('Coordinates must be within galaxy 1..9, system 1..400, position 1..15.');
        }
    }

    public function key(): string
    {
        return $this->galaxy.':'.$this->system.':'.$this->position;
    }

    public function toArray(): array { return ['galaxy' => $this->galaxy, 'system' => $this->system, 'position' => $this->position]; }

    public static function fromArray(array $data): self
    {
        if (array_keys($data) !== ['galaxy', 'system', 'position'] || !is_int($data['galaxy'] ?? null)
            || !is_int($data['system'] ?? null) || !is_int($data['position'] ?? null)) {
            throw new EconomyDataException('Malformed serialized coordinates.');
        }
        return new self($data['galaxy'], $data['system'], $data['position']);
    }

    public function distanceTo(self $target): int
    {
        if ($this->key() === $target->key()) {
            return 5;
        }
        if ($this->galaxy !== $target->galaxy) {
            return 20000 * abs($this->galaxy - $target->galaxy);
        }
        if ($this->system !== $target->system) {
            return 95 * abs($this->system - $target->system) + 2700;
        }

        return 5 * abs($this->position - $target->position) + 1000;
    }
}
