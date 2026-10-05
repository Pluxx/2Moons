<?php

declare(strict_types=1);

namespace App\Domain\Development;

use App\Domain\Economy\EconomyDataException;

final readonly class ArrivalInputs
{
    /** @param list<string> $occupiedCoordinates @param array<string,array{temperature_max:int,fields_total:int}> $colonyDraws */
    public function __construct(public array $occupiedCoordinates = [], public array $colonyDraws = [])
    {
        if (!array_is_list($occupiedCoordinates)) {
            throw new EconomyDataException('Occupied coordinates must be an ordered list.');
        }
        if (count(array_unique($occupiedCoordinates)) !== count($occupiedCoordinates)) {
            throw new EconomyDataException('Occupied coordinates cannot contain duplicates.');
        }
        foreach ($occupiedCoordinates as $coordinate) {
            if (!is_string($coordinate) || preg_match('/^[1-9]:[1-9][0-9]{0,2}:(?:[1-9]|1[0-5])$/D', $coordinate) !== 1) {
                throw new EconomyDataException('Occupied coordinate keys must be canonical galaxy:system:position values.');
            }
            [$galaxy, $system, $position] = array_map('intval', explode(':', $coordinate));
            new Coordinates($galaxy, $system, $position);
        }
        foreach ($colonyDraws as $token => $draw) {
            $keys = is_array($draw) ? array_keys($draw) : [];
            sort($keys);
            if (!is_string($token) || !is_array($draw) || $keys !== ['fields_total', 'temperature_max']
                || !is_int($draw['temperature_max'] ?? null) || !is_int($draw['fields_total'] ?? null)) {
                throw new EconomyDataException('Colony climate draws must be keyed by fleet token and contain integer values.');
            }
        }
    }
}
