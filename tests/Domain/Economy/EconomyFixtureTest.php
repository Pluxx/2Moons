<?php

declare(strict_types=1);

namespace App\Tests\Domain\Economy;

use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\ConstructionEntry;
use App\Domain\Economy\EconomyCalculator;
use App\Domain\Economy\EconomyEngine;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use App\Domain\Economy\ResourceRates;
use Brick\Math\BigRational;

final class EconomyFixtureTest extends EconomyTestCase
{
    public function testEveryPublishedFixtureKindIsExecuted(): void
    {
        $path = dirname(__DIR__, 3).'/docs/fixtures/economy.json';
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $fixtures = $document['fixtures'];
        $handled = [];
        $calculator = new EconomyCalculator();
        $engine = new EconomyEngine($calculator);
        $settings = $this->settings();

        foreach ($fixtures as $fixture) {
            $input = $fixture['input'];
            $expected = $fixture['expected'];
            $handled[$fixture['kind']] = true;

            switch ($fixture['kind']) {
                case 'accrual':
                    $start = $this->amounts($input['start']);
                    $basic = $settings->basicIncomePerHour;
                    $mine = $input['mine_rates_per_hour'];
                    $rates = $basic->plus(ResourceRates::fromStrings(
                        $mine['metal'],
                        $mine['crystal'],
                        $mine['deuterium'],
                    ));
                    $end = $calculator->accrue($start, $rates, $calculator->capacities(BuildingLevels::fromArray([]), $settings), $input['elapsed_seconds']);
                    $this->assertAmounts($expected['end'], $end, $fixture['id']);
                    break;

                case 'storage-capacity':
                    foreach ($input['levels'] as $index => $level) {
                        $levels = BuildingLevels::fromArray([
                            Building::MetalStorage->value => $level,
                            Building::CrystalStorage->value => $level,
                            Building::DeuteriumStorage->value => $level,
                        ]);
                        $capacity = $calculator->capacities($levels, $settings);
                        foreach (Resource::cases() as $resource) {
                            $this->assertRational($expected['capacity_each_level'][$index], $capacity->get($resource), $fixture['id']);
                        }
                    }
                    break;

                case 'accrual-cap':
                    $start = $this->amounts($input['start']);
                    $rates = ResourceRates::fromStrings(
                        $input['positive_metal_rate_per_hour'] ?? $input['net_metal_rate_per_hour'],
                        '10',
                        '0',
                    );
                    $end = $calculator->accrue($start, $rates, $this->amounts($input['capacity']), $input['elapsed_seconds']);
                    $this->assertAmounts($expected['end'], $end, $fixture['id']);
                    break;

                case 'accrual-negative-rate':
                    $rates = ResourceRates::fromStrings($input['net_metal_rate_per_hour'], '10', '0');
                    $end = $calculator->accrue(
                        $this->amounts($input['start']),
                        $rates,
                        ResourceAmounts::fromStrings('10000', '10000', '10000'),
                        $input['elapsed_seconds'],
                    );
                    $this->assertAmounts($expected['end'], $end, $fixture['id']);
                    break;

                case 'production-cache':
                    $state = $this->planet(levels: $input['levels'], temperatureMax: $input['temperature_max']);
                    $production = $calculator->calculate($state, $settings);
                    $this->assertRational($expected['generated_energy'], $production->generatedEnergy, $fixture['id']);
                    $this->assertRational($expected['energy_demand'], $production->energyDemand, $fixture['id']);
                    if ($expected['production_factor'] === 'not-applicable-no-demand') {
                        self::assertNull($production->productionFactor, $fixture['id']);
                    } else {
                        $this->assertRational($expected['production_factor'], $production->productionFactor, $fixture['id']);
                    }
                    $this->assertAmounts($expected['gross_mine_per_hour'], new ResourceAmounts(
                        $production->grossMineRatesPerHour->get(Resource::Metal),
                        $production->grossMineRatesPerHour->get(Resource::Crystal),
                        $production->grossMineRatesPerHour->get(Resource::Deuterium),
                    ), $fixture['id']);
                    $this->assertAmounts($expected['total_per_hour'], new ResourceAmounts(
                        $production->totalRatesPerHour->get(Resource::Metal),
                        $production->totalRatesPerHour->get(Resource::Crystal),
                        $production->totalRatesPerHour->get(Resource::Deuterium),
                    ), $fixture['id']);
                    if (isset($expected['raw_generated_energy'])) {
                        $this->assertRational($expected['raw_generated_energy'], $production->rawGeneratedEnergy);
                    }
                    break;

                case 'construction-price-and-duration':
                    self::assertCount(count($input['buildings']), $expected['price_seconds'], $fixture['id']);
                    foreach ($input['buildings'] as $index => $row) {
                        $building = Building::fromLegacyId($row['id']);
                        self::assertNotNull($building, $fixture['id']);
                        $expectedRow = $expected['price_seconds'][$index];
                        self::assertSame($row['id'], $expectedRow['id'], $fixture['id']);
                        self::assertSame($row['target_level'], $expectedRow['level'], $fixture['id']);
                        $cost = $calculator->priceFor($building, $row['target_level']);
                        $this->assertAmounts($expectedRow['cost'], $cost, $fixture['id']);
                        self::assertSame($expectedRow['duration'], $calculator->buildDurationSeconds($building, $row['target_level'], $settings), $fixture['id']);
                    }
                    break;

                case 'affordability':
                    $stock = $this->amounts($input['stock']);
                    $cost = $this->amounts($input['cost']);
                    self::assertSame($expected['affordable'], $stock->canAfford($cost), $fixture['id']);
                    break;

                case 'construction-boundary':
                    $state = $this->planet($input['stock_before_enqueue'], timestamp: $input['start_time']);
                    $queued = $engine->enqueue($state, Building::fromLegacyId($input['building_id']), $input['target_level'], 'fixture-construction', $input['start_time'], $settings);
                    self::assertTrue($queued->isAccepted(), $fixture['id']);
                    $this->assertAmounts($input['stock_after_enqueue'], $queued->state->resources, $fixture['id']);
                    $before = $engine->advance($queued->state, $input['start_time'] + $input['duration_seconds'] - 1, $settings);
                    self::assertSame($expected['at_161']['level'], $before->state->levels->get(Building::MetalMine), $fixture['id']);
                    self::assertSame($expected['at_161']['fields_used'], $before->state->fieldsUsed, $fixture['id']);
                    self::assertSame($expected['at_161']['complete'], $before->state->pendingEntries === [], $fixture['id']);
                    $at = $engine->advance($queued->state, $input['start_time'] + $input['duration_seconds'], $settings);
                    self::assertSame($expected['at_162']['level'], $at->state->levels->get(Building::MetalMine), $fixture['id']);
                    self::assertSame($expected['at_162']['fields_used'], $at->state->fieldsUsed, $fixture['id']);
                    self::assertSame($expected['at_162']['complete'], $at->state->pendingEntries === [], $fixture['id']);
                    break;

                case 'construction-accrual-order':
                    $building = Building::fromLegacyId($input['building_id']);
                    $active = ConstructionEntry::active(
                        'fixture-order',
                        $building,
                        $input['target_level'],
                        $input['start_time'],
                        $input['start_time'],
                        $input['start_time'] + $input['duration_seconds'],
                    );
                    $state = new PlanetEconomyState(
                        $this->amounts($input['start']),
                        BuildingLevels::fromArray(['solar_plant' => $input['solar_plant_level']]),
                        $input['temperature_max'],
                        $input['fields_total'] ?? $settings->homeFields,
                        $input['fields_used'] ?? $input['solar_plant_level'],
                        $input['start_time'],
                        [$active],
                    );
                    $at = $engine->advance($state, $input['start_time'] + $input['duration_seconds'], $settings);
                    $this->assertAmounts($expected['at_completion_before_new_rates'], $at->state->resources, $fixture['id']);
                    self::assertSame($expected['level_after_completion'], $at->state->levels->get($building), $fixture['id']);
                    break;

                case 'accrual-boundary':
                    $state = $this->planet($input['stock'], timestamp: $input['last_update']);
                    $at = $engine->advance($state, $input['timestamp'], $settings);
                    self::assertSame($expected['elapsed_seconds'], $at->state->lastSettledAt - $state->lastSettledAt, $fixture['id']);
                    $this->assertAmounts($expected['stock_unchanged'], $at->state->resources, $fixture['id']);
                    break;

                default:
                    self::fail(sprintf('Fixture kind "%s" is not implemented (%s).', $fixture['kind'], $fixture['id']));
            }
        }

        self::assertSame([
            'accrual' => true,
            'storage-capacity' => true,
            'accrual-cap' => true,
            'accrual-negative-rate' => true,
            'production-cache' => true,
            'construction-price-and-duration' => true,
            'affordability' => true,
            'construction-boundary' => true,
            'construction-accrual-order' => true,
            'accrual-boundary' => true,
        ], $handled);
    }
}
