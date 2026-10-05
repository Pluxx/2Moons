<?php

declare(strict_types=1);

namespace App\Tests\Domain\Economy;

use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use Brick\Math\BigRational;
use PHPUnit\Framework\TestCase;

abstract class EconomyTestCase extends TestCase
{
    protected function settings(): EconomySettings
    {
        return EconomySettings::defaults();
    }

    protected function amounts(array $values): ResourceAmounts
    {
        return ResourceAmounts::fromStrings(
            (string) ($values[Resource::Metal->value] ?? '0'),
            (string) ($values[Resource::Crystal->value] ?? '0'),
            (string) ($values[Resource::Deuterium->value] ?? '0'),
        );
    }

    protected function planet(
        array $resources = ['metal' => '500', 'crystal' => '500', 'deuterium' => '0'],
        array $levels = [],
        int $temperatureMax = 40,
        int $timestamp = 0,
        int $fieldsTotal = 163,
        ?int $fieldsUsed = null,
    ): PlanetEconomyState {
        $fieldsUsed ??= array_sum(BuildingLevels::fromArray($levels)->toArray());

        return new PlanetEconomyState(
            $this->amounts($resources),
            BuildingLevels::fromArray($levels),
            $temperatureMax,
            $fieldsTotal,
            $fieldsUsed,
            $timestamp,
        );
    }

    protected function assertRational(string $expected, BigRational $actual, string $message = ''): void
    {
        self::assertSame(0, $actual->compareTo(BigRational::of($expected)), $message ?: sprintf(
            'Expected rational %s; got %s.',
            $expected,
            $actual->getNumerator().'/'.$actual->getDenominator(),
        ));
    }

    protected function assertAmounts(array $expected, ResourceAmounts $actual, string $message = ''): void
    {
        foreach (Resource::cases() as $resource) {
            $this->assertRational((string) ($expected[$resource->value] ?? '0'), $actual->get($resource), $message);
        }
    }
}
