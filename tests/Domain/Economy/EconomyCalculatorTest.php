<?php

declare(strict_types=1);

namespace App\Tests\Domain\Economy;

use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomyCalculator;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\EconomyEngine;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\RationalCodec;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use App\Domain\Economy\ResourceRates;
use App\Domain\Economy\RejectionCode;
use Brick\Math\BigRational;

final class EconomyCalculatorTest extends EconomyTestCase
{
    public function testCanonicalBalancesAreStrictReducedAndZeroHasAnExplicitDenominator(): void
    {
        self::assertSame('0/1', RationalCodec::canonical(BigRational::zero()));
        self::assertSame('1/2', RationalCodec::canonical(BigRational::of('0.5')));
        self::assertSame('17/1', RationalCodec::canonical(BigRational::of('17')));

        foreach (['-1/2', '1/0', '0/3', '2/4', '02/1', '1/-2', '1.5', '1', ' 1/2'] as $invalid) {
            try {
                RationalCodec::parseCanonicalBalance($invalid);
                self::fail(sprintf('Noncanonical or invalid balance "%s" was accepted.', $invalid));
            } catch (EconomyDataException) {
                self::assertTrue(true);
            }
        }

        $balances = ResourceAmounts::fromCanonical(['metal' => '1/3', 'crystal' => '0/1', 'deuterium' => '5/2']);
        self::assertSame(['metal' => '1/3', 'crystal' => '0/1', 'deuterium' => '5/2'], $balances->toCanonicalArray());
    }

    public function testOneHourAndSerializedSecondBySecondSettlementAreEquivalent(): void
    {
        $settings = $this->settings();
        $engine = new EconomyEngine();
        $whole = $engine->advance(PlanetEconomyState::newHome(0, $settings), 3600, $settings)->state;
        $partitioned = PlanetEconomyState::newHome(0, $settings);

        for ($second = 1; $second <= 3600; ++$second) {
            $advanced = $engine->advance($partitioned, $second, $settings);
            self::assertTrue($advanced->isAccepted());
            $partitioned = PlanetEconomyState::fromArray($advanced->state->toArray());
        }

        self::assertSame($whole->toArray(), $partitioned->toArray());
        self::assertSame('520/1', $partitioned->resources->toCanonicalArray()['metal']);
        self::assertSame('510/1', $partitioned->resources->toCanonicalArray()['crystal']);
    }

    public function testRepeatingProductionRatesRemainEquivalentAcrossSerializedPartitions(): void
    {
        $settings = $this->settings();
        $engine = new EconomyEngine();
        $levels = [
            'metal_mine' => 2,
            'crystal_mine' => 1,
            'deuterium_synthesizer' => 1,
            'solar_plant' => 1,
        ];
        $state = $this->planet(
            ['metal' => '1000', 'crystal' => '1000', 'deuterium' => '1000'],
            $levels,
            timestamp: 0,
        );
        $snapshot = $engine->calculate($state, $settings);
        $this->assertRational('1346/31', $snapshot->totalRatesPerHour->get(Resource::Metal));
        $this->assertRational('530/31', $snapshot->totalRatesPerHour->get(Resource::Crystal));
        $this->assertRational('132/31', $snapshot->totalRatesPerHour->get(Resource::Deuterium));

        $whole = $engine->advance($state, 3600, $settings)->state;
        foreach ([7, 31, 229, 997, 1800, 2777, 3600] as $timestamp) {
            $result = $engine->advance($state, $timestamp, $settings);
            self::assertTrue($result->isAccepted());
            $state = PlanetEconomyState::fromArray($result->state->toArray());
        }

        self::assertSame($whole->toArray(), $state->toArray());
    }

    public function testPartitioningAroundCapacityUpgradeDoesNotRestoreDiscardedProduction(): void
    {
        $calculator = new EconomyCalculator();
        $settings = $this->settings();
        $stock = $this->amounts(['metal' => '9990', 'crystal' => '0', 'deuterium' => '0']);
        $rates = ResourceRates::fromStrings('20', '0', '0');
        $levelZero = BuildingLevels::fromArray([]);
        $levelOneStorage = BuildingLevels::fromArray(['metal_storage' => 1]);
        $capacity0 = $calculator->capacities($levelZero, $settings);
        $capacity1 = $calculator->capacities($levelOneStorage, $settings);

        $wholeFirstHour = $calculator->accrue($stock, $rates, $capacity0, 3600);
        self::assertSame('10000/1', $wholeFirstHour->toCanonicalArray()['metal']);
        $wholeSecondHour = $calculator->accrue($wholeFirstHour, $rates, $capacity1, 3600);

        $partitioned = $stock;
        for ($second = 0; $second < 3600; ++$second) {
            $partitioned = $calculator->accrue($partitioned, $rates, $capacity0, 1);
        }
        self::assertSame($wholeFirstHour->toCanonicalArray(), $partitioned->toCanonicalArray());
        for ($second = 0; $second < 3600; ++$second) {
            $partitioned = $calculator->accrue($partitioned, $rates, $capacity1, 1);
        }

        self::assertSame($wholeSecondHour->toCanonicalArray(), $partitioned->toCanonicalArray());
        self::assertSame('10020/1', $partitioned->toCanonicalArray()['metal']);
    }

    public function testStorageFullProductionDoesNotAccumulateAsHiddenCredit(): void
    {
        $calculator = new EconomyCalculator();
        $settings = $this->settings();
        $rates = ResourceRates::fromStrings('20', '0', '0');
        $stock = $this->amounts(['metal' => '9990']);
        $atCap = $calculator->accrue($stock, $rates, $calculator->capacities(BuildingLevels::fromArray([]), $settings), 3600);
        self::assertSame('10000/1', $atCap->toCanonicalArray()['metal']);

        $afterCapacityUpgrade = $calculator->accrue(
            $atCap,
            ResourceRates::zero(),
            $calculator->capacities(BuildingLevels::fromArray(['metal_storage' => 1]), $settings),
            0,
        );
        self::assertSame('10000/1', $afterCapacityUpgrade->toCanonicalArray()['metal']);
    }

    public function testFractionalEnergyDemandAndTemperatureAreExactAndExplicit(): void
    {
        $calculator = new EconomyCalculator();
        $settings = $this->settings();
        $level2 = $this->planet(levels: ['metal_mine' => 2], temperatureMax: 40);
        $production = $calculator->calculate($level2, $settings);
        $this->assertRational('24.2', $production->energyDemand);

        $atForty = $calculator->calculate($this->planet(levels: ['deuterium_synthesizer' => 1], temperatureMax: 40), $settings);
        $atThirty = $calculator->calculate($this->planet(levels: ['deuterium_synthesizer' => 1], temperatureMax: 30), $settings);
        $this->assertRational('13.2', $atForty->grossMineRatesPerHour->get(Resource::Deuterium));
        $this->assertRational('13.42', $atThirty->grossMineRatesPerHour->get(Resource::Deuterium));

        self::assertSame(40, PlanetEconomyState::newHome(0, $settings)->temperatureMax);
    }

    public function testHighLevelPriceRemainsExactAndUnrepresentableTimestampQuoteRejects(): void
    {
        $calculator = new EconomyCalculator();
        $engine = new EconomyEngine($calculator);
        $settings = $this->settings();
        $price = $calculator->priceFor(Building::MetalMine, 255);
        $expected = BigRational::of(60)->multipliedBy(BigRational::ofFraction(3, 2)->power(255));
        $this->assertRational(RationalCodec::canonical($expected), BigRational::of($price->toCanonicalArray()['metal']));

        $maxLevelPlanet = $this->planet(levels: ['metal_mine' => 254], fieldsTotal: 300);
        $quote = $engine->quote($maxLevelPlanet, Building::MetalMine, $settings);
        self::assertFalse($quote->isQuoted());
        self::assertSame(RejectionCode::TimestampRange, $quote->rejection->code);

        $nearOverflow = $this->planet(timestamp: PHP_INT_MAX - 10);
        $overflowQuote = $engine->quote($nearOverflow, Building::MetalMine, $settings);
        self::assertSame(RejectionCode::TimestampRange, $overflowQuote->rejection->code);
    }

    public function testHomeStateRoundTripsQueueAndExactBalanceWithoutLosingCanonicalForm(): void
    {
        $settings = $this->settings();
        $state = PlanetEconomyState::newHome(1200, $settings)->evolve(
            resources: ResourceAmounts::fromStrings('1/3', '0', '10/7'),
        );
        $copy = PlanetEconomyState::fromArray($state->toArray());

        self::assertSame($state->toArray(), $copy->toArray());
        self::assertSame('1/3', $copy->resources->toCanonicalArray()['metal']);
        self::assertSame('0/1', $copy->resources->toCanonicalArray()['crystal']);
        self::assertSame('10/7', $copy->resources->toCanonicalArray()['deuterium']);
    }
}
