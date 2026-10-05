<?php

declare(strict_types=1);

namespace App\Tests\Domain\Development;

use App\Domain\Development\AccountEngine;
use App\Domain\Development\AccountPlanet;
use App\Domain\Development\AccountState;
use App\Domain\Development\ArrivalInputs;
use App\Domain\Development\ColonyRules;
use App\Domain\Development\Coordinates;
use App\Domain\Development\FleetCalculator;
use App\Domain\Development\Mission;
use App\Domain\Development\ResearchCalculator;
use App\Domain\Development\ResearchEntry;
use App\Domain\Development\Ship;
use App\Domain\Development\ShipyardBatch;
use App\Domain\Development\ShipyardCalculator;
use App\Domain\Development\Technology;
use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomyCalculator;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use Brick\Math\BigRational;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportFixtureAcceptanceTest extends TestCase
{
    private const KINDS = [
        'facility-base-prices-level-1' => 'price',
        'technology-target-costs' => 'price',
        'ship-linear-batch-price' => 'price',
        'position-expedition-thresholds' => 'colonization-position-requirement',
        'owned-planet-cap' => 'planet-cap',
        'position-climate-field-bands' => 'colony-position-bands',
        'hierarchical-distance-no-wrap' => 'distance',
        'same-coordinate-is-distance-five-but-not-dispatchable' => 'same-coordinate-dispatch',
        'cargo-speed-index-10-distance-1005' => 'flight',
        'independent-return-rounding-distance-1020' => 'flight',
        'speed-selector-index-not-displayed-percent' => 'flight',
        'mixed-small-cargo-and-colony-ship' => 'flight',
        'cargo-plus-fuel-capacity-boundary' => 'capacity-fit',
        'small-cargo-drive-switch-keeps-base-speed' => 'small-cargo-stats',
        'construction-and-facility-times' => 'construction-time',
        'spy-research-times-valid-prerequisites' => 'research-time',
        'expedition-level-one-research-time' => 'research-time',
        'ship-unit-times-at-shipyard-four' => 'ship-unit-time',
        'ship-batch-partial-materialization' => 'ship-batch-progress',
        'half-up-ties-are-explicit-math' => 'synthetic-rounding',
        'flight-arrival-timestamp-overflow-rejects-before-payment' => 'technical-rejection',
        'strict-transport-payload-boundary' => 'transport-admission',
        'colonization-admission-and-arrival-policy' => 'colonization-policy',
    ];

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function fixtureCases(): iterable
    {
        $path = dirname(__DIR__, 3).'/docs/fixtures/transport.json';
        if (!is_file($path)) { throw new \RuntimeException('Required independent transport fixture is missing.'); }
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $document['fixtures'] ?? null;
        if (!is_array($rows) || count($rows) !== 23) { throw new \RuntimeException('Expected exactly 23 transport fixtures.'); }
        $seen = [];
        $kinds = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['kind'] ?? null)) {
                throw new \RuntimeException('Malformed independent transport fixture row.');
            }
            if (isset($seen[$row['id']])) { throw new \RuntimeException('Duplicate independent fixture ID '.$row['id']); }
            $seen[$row['id']] = true;
            $kinds[] = $row['kind'];
            yield $row['id'] => [$row['id'], $row];
        }
        if (count(array_unique($kinds)) !== 17) { throw new \RuntimeException('Expected exactly 17 independent fixture kinds.'); }
    }

    #[DataProvider('fixtureCases')]
    public function testEveryIndependentFixtureRunsThroughDomainRules(string $id, array $row): void
    {
        self::assertArrayHasKey($id, self::KINDS, 'Unknown fixture ID must not be silently skipped.');
        self::assertSame(self::KINDS[$id], $row['kind'], 'Every fixture kind must have an explicit adapter.');
        self::assertArrayHasKey('input', $row);
        self::assertArrayHasKey('expected', $row);

        match ($id) {
            'facility-base-prices-level-1' => $this->facilityPrices($row),
            'technology-target-costs' => $this->technologyPrices($row),
            'ship-linear-batch-price' => $this->shipPrices($row),
            'position-expedition-thresholds' => $this->positionRequirements($row),
            'owned-planet-cap' => $this->planetCaps($row),
            'position-climate-field-bands' => $this->climateBands($row),
            'hierarchical-distance-no-wrap' => $this->distances($row),
            'same-coordinate-is-distance-five-but-not-dispatchable' => $this->sameCoordinate($row),
            'cargo-speed-index-10-distance-1005',
            'independent-return-rounding-distance-1020',
            'speed-selector-index-not-displayed-percent',
            'mixed-small-cargo-and-colony-ship' => $this->flight($id, $row),
            'cargo-plus-fuel-capacity-boundary' => $this->capacity($row),
            'small-cargo-drive-switch-keeps-base-speed' => $this->smallCargoStats($row),
            'construction-and-facility-times' => $this->constructionTimes($row),
            'spy-research-times-valid-prerequisites' => $this->spyResearchTimes($row),
            'expedition-level-one-research-time' => $this->expeditionTime($row),
            'ship-unit-times-at-shipyard-four' => $this->shipTimes($row),
            'ship-batch-partial-materialization' => $this->batchProgress($row),
            'half-up-ties-are-explicit-math' => $this->syntheticRounding($row),
            'flight-arrival-timestamp-overflow-rejects-before-payment' => $this->timestampOverflow($row),
            'strict-transport-payload-boundary' => $this->strictTransport($row),
            'colonization-admission-and-arrival-policy' => $this->colonizationPolicy($row),
            default => self::fail('No domain adapter for fixture '.$id.' ('.$row['kind'].').'),
        };
    }

    private function facilityPrices(array $row): void
    {
        foreach ($row['input']['targets'] as $i => $target) {
            self::assertSame($row['expected']['costs'][$i]['id'], $target['id']);
            $this->assertAmounts($row['expected']['costs'][$i], (new EconomyCalculator())->priceFor(Building::fromLegacyId($target['id']), $target['level']));
        }
    }

    private function technologyPrices(array $row): void
    {
        foreach ($row['input']['targets'] as $i => $target) {
            $technology = $this->technology($target['id']);
            $expected = $row['expected']['costs'][$i];
            self::assertSame($target['id'], $expected['id']);
            $this->assertAmounts($expected, (new ResearchCalculator())->priceFor($technology, $target['level']));
        }
    }

    private function shipPrices(array $row): void
    {
        foreach ($row['input']['units'] as $i => $unit) {
            $expected = $row['expected']['costs'][$i];
            self::assertSame($unit['id'], $expected['id']);
            self::assertSame($unit['count'], $expected['id'] === 202 ? 3 : 2);
            $this->assertAmounts($expected, (new ShipyardCalculator())->priceFor($this->ship($unit['id']), $unit['count']));
        }
    }

    private function positionRequirements(array $row): void
    {
        $rules = new ColonyRules();
        foreach ($row['input']['positions'] as $i => $position) {
            self::assertSame($row['expected']['levels'][$i], $rules->requiredExpeditionLevel($position));
        }
    }

    private function planetCaps(array $row): void
    {
        $rules = new ColonyRules();
        foreach ($row['input']['expeditionLevels'] as $i => $level) {
            self::assertSame($row['expected']['planetCountsIncludingHome'][$i], $rules->maximumOwnedPlanets($level));
        }
    }

    private function climateBands(array $row): void
    {
        $rules = new ColonyRules();
        foreach ($row['input']['positions'] as $i => $position) {
            $band = $row['expected']['bands'][$i];
            [$minT, $maxT, $minF, $maxF] = $rules->climateBand($position);
            self::assertSame($band['maxTemperature'], [$minT, $maxT]);
            self::assertSame($band['fields'], [$minF, $maxF]);
            self::assertTrue($rules->validateDraw($position, $minT, $minF));
            self::assertTrue($rules->validateDraw($position, $maxT, $maxF));
            self::assertFalse($rules->validateDraw($position, $minT - 1, $minF));
            self::assertFalse($rules->validateDraw($position, $minT, $maxF + 1));
        }
        self::assertSame('sampled maximumTemperature - 40; no second draw', $row['expected']['minimumTemperatureRule']);
    }

    private function distances(array $row): void
    {
        foreach ($row['input']['routes'] as $i => $route) {
            $from = new Coordinates(...$route['from']);
            $to = new Coordinates(...$route['to']);
            self::assertSame($row['expected']['distances'][$i], $from->distanceTo($to));
        }
    }

    private function sameCoordinate(array $row): void
    {
        $from = new Coordinates(...$row['input']['source']);
        $to = new Coordinates(...$row['input']['target']);
        self::assertSame($row['expected']['distance'], $from->distanceTo($to));
        try {
            (new FleetCalculator())->calculate($from, $to, ['small_cargo' => 1], $this->zeroResearch(), 10);
            self::fail('Same-coordinate dispatch must be rejected.');
        } catch (\App\Domain\Economy\EconomyDataException) {
            self::assertSame('1:1:3', $from->key());
        }
        self::assertSame('reject-source-is-target', $row['expected']['dispatch']);
    }

    private function flight(string $id, array $row): void
    {
        $input = $row['input'];
        self::assertTrue(BigRational::ofFraction($input['fleetFactor']['numerator'], $input['fleetFactor']['denominator'])->isEqualTo(1));
        $ships = [];
        $research = $this->zeroResearch();
        $slowest = null;
        foreach ($input['ships'] as $shipInput) {
            $ship = $this->ship($shipInput['id']);
            $ships[$ship->value] = $shipInput['count'];
            $engineName = $ship === Ship::SmallCargo
                ? (($shipInput['impulseLevel'] ?? 0) >= 5 ? 'impulse' : 'combustion')
                : 'impulse';
            self::assertSame($shipInput['engine'], $engineName);
            $research['combustion'] = max($research['combustion'], $shipInput['combustionLevel'] ?? 0);
            $research['impulse'] = max($research['impulse'], $shipInput['impulseLevel'] ?? 0);
            self::assertSame($shipInput['baseSpeed'], $ship->baseSpeed());
            if (isset($shipInput['capacity'])) { self::assertSame($shipInput['capacity'], $ship->capacity()); }
            self::assertSame($shipInput['consumption'], $ship->consumption($research['combustion'], $research['impulse']));
            $speed = $ship->speed($research['combustion'], $research['impulse']);
            $slowest = $slowest === null ? $speed : ($speed->compareTo($slowest) < 0 ? $speed : $slowest);
        }
        $expected = $row['expected'];
        if (isset($expected['slowestSpeed'])) { self::assertSame($expected['slowestSpeed'], $slowest->toInt()); }
        $dist = $input['distance'];
        $delta = intdiv($dist - 1000, 5);
        $from = new Coordinates(1, 1, 3);
        $to = new Coordinates(1, 1, 3 + $delta);
        self::assertSame($dist, $from->distanceTo($to));
        $calculator = new FleetCalculator();
        $indices = $input['speedIndexes'] ?? [$input['speedIndex']];
        foreach ($indices as $i => $speedIndex) {
            $actual = $calculator->calculate($from, $to, $ships, $research, $speedIndex);
            $departure = $input['departure'];
            $arrival = $expected['arrival'] ?? $expected['durationRounded'];
            $return = $expected['return'] ?? $expected['returnOffset'];
            $arrival = is_array($arrival) ? $arrival[$i] : $arrival;
            $return = is_array($return) ? $return[$i] : $return;
            $fuel = is_array($expected['fuel']) ? $expected['fuel'][$i] : $expected['fuel'];
            self::assertSame($arrival - $departure, $actual['arrival_offset'], $id.' arrival speed='.$speedIndex);
            self::assertSame($return - $departure, $actual['return_offset'], $id.' return speed='.$speedIndex);
            self::assertSame($fuel, $actual['fuel'], $id.' fuel speed='.$speedIndex);
            $expectedCapacity = $expected['capacity'] ?? array_sum(array_map(fn (array $shipRow): int => $this->ship($shipRow['id'])->capacity() * $shipRow['count'], $input['ships']));
            self::assertSame($expectedCapacity, $actual['capacity']);
        }
    }

    private function capacity(array $row): void
    {
        $input = $row['input'];
        $source = $this->transportState($input['ships'][0]['count']);
        $engine = new AccountEngine(EconomySettings::defaults());
        $from = $source->planets['1']->coordinates;
        $target = $source->planets['2']->coordinates;
        $actualFits = [];
        foreach ($input['cargoAmounts'] as $amount) {
            $result = $engine->dispatch($source, '1', Mission::Transport, $target, '2',
                ['small_cargo' => $input['ships'][0]['count'], 'colony_ship' => 0],
                ['metal' => (string) $amount, 'crystal' => '0', 'deuterium' => '0'], 10, 'capacity-'.$amount, 0);
            $actualFits[] = $result->isAccepted();
            if ($amount + $input['fuel'] > $input['ships'][0]['capacity']) {
                self::assertSame('fleet_capacity_exceeded', $result->rejection);
            } else {
                self::assertTrue($result->isAccepted());
                self::assertSame($input['fuel'], $result->state->fleets[0]->fuel);
            }
        }
        self::assertSame($row['expected']['fits'], $actualFits);
    }

    private function smallCargoStats(array $row): void
    {
        $input = $row['input'];
        $expected = $row['expected'];
        $speeds = [];
        $consumptions = [];
        $engines = [];
        foreach ($input['impulseLevels'] as $impulse) {
            $combustion = 2;
            $engines[] = $impulse >= 5 ? 'impulse' : 'combustion';
            $speeds[] = Ship::SmallCargo->baseSpeed();
            $consumptions[] = Ship::SmallCargo->consumption($combustion, $impulse);
        }
        self::assertSame($expected['baseSpeed'], $speeds);
        self::assertSame($expected['engine'], $engines);
        self::assertSame($expected['consumption'], $consumptions);
        self::assertTrue($expected['unusedSpeed2']);
        self::assertSame(6000, Ship::SmallCargo->speed(2, 4)->toInt());
        self::assertSame(10000, Ship::SmallCargo->speed(0, 5)->toInt());
    }

    private function constructionTimes(array $row): void
    {
        self::assertSame(0, EconomySettings::defaults()->gameSpeed->compareTo($row['input']['gameSpeed']));
        foreach ($row['input']['targets'] as $i => $target) {
            self::assertContains($target['robotics'], $row['input']['roboticsLevels']);
            self::assertSame($row['expected']['seconds'][$i], (new EconomyCalculator())->buildDurationSeconds(
                Building::fromLegacyId($target['id']), $target['level'], EconomySettings::defaults(), $target['robotics']));
        }
    }

    private function spyResearchTimes(array $row): void
    {
        self::assertSame(0, EconomySettings::defaults()->gameSpeed->compareTo($row['input']['gameSpeed']));
        $calculator = new ResearchCalculator();
        foreach ($row['input']['targets'] as $i => $target) {
            $cost = $calculator->priceFor(Technology::Spy, $target['level']);
            self::assertSame($target['metal'], $cost->get(Resource::Metal)->toScale(0)->toInt());
            self::assertSame($target['crystal'], $cost->get(Resource::Crystal)->toScale(0)->toInt());
            self::assertSame($row['expected']['seconds'][$i], $calculator->durationSeconds($cost, $row['input']['laboratory']));
        }
        self::assertTrue($row['expected']['admissionPrerequisiteValid']);
        self::assertTrue($calculator->meetsPrerequisites(Technology::Spy, $this->researchWith(['spy' => 0]), $row['input']['laboratory']));
    }

    private function expeditionTime(array $row): void
    {
        self::assertSame(0, EconomySettings::defaults()->gameSpeed->compareTo($row['input']['gameSpeed']));
        $target = $row['input']['target'];
        $calculator = new ResearchCalculator();
        $cost = $calculator->priceFor(Technology::Expedition, $target['level']);
        self::assertSame($target['metal'], $cost->get(Resource::Metal)->toScale(0)->toInt());
        self::assertSame($target['crystal'], $cost->get(Resource::Crystal)->toScale(0)->toInt());
        self::assertSame($row['expected']['seconds'], $calculator->durationSeconds($cost, $row['input']['laboratory']));
    }

    private function shipTimes(array $row): void
    {
        self::assertSame(0, EconomySettings::defaults()->gameSpeed->compareTo($row['input']['gameSpeed']));
        $calculator = new ShipyardCalculator();
        foreach ($row['input']['ships'] as $i => $shipInput) {
            $ship = $this->ship($shipInput['id']);
            [$metal, $crystal] = $ship->baseCost();
            self::assertSame($shipInput['metal'], $metal);
            self::assertSame($shipInput['crystal'], $crystal);
            self::assertSame($row['expected']['seconds'][$i], $calculator->unitDurationSeconds($ship, 1, $row['input']['shipyard']));
        }
    }

    private function batchProgress(array $row): void
    {
        $input = $row['input'];
        $expected = $row['expected'];
        $completion = $input['unitSeconds'] * $input['quantity'];
        $batch = new ShipyardBatch('fixture-batch', Ship::SmallCargo, $input['quantity'], ResourceAmounts::zero(), 0,
            0, $input['unitSeconds'], $input['alreadyProduced'], $completion, null, 'active');
        $planet = new AccountPlanet('1', new Coordinates(1, 1, 3), 0,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'),
                BuildingLevels::fromArray(['shipyard' => 4]), 40, 163, 4, $input['unitSeconds']),
            ['small_cargo' => $input['alreadyProduced'], 'colony_ship' => 0], [$batch]);
        $state = AccountState::withResearch('fixture-owner', $input['unitSeconds'], ['1' => $planet]);
        $actual = (new AccountEngine(EconomySettings::defaults()))->advance($state, $input['elapsedSeconds']);
        self::assertTrue($actual->isAccepted(), json_encode([$actual->rejection, $actual->context], JSON_THROW_ON_ERROR));
        $result = $actual->state->planets['1'];
        $totalProduced = $result->ships[Ship::SmallCargo->value];
        self::assertSame($expected['newlyProduced'], $totalProduced - $input['alreadyProduced']);
        self::assertSame($expected['totalProduced'], $totalProduced);
        self::assertSame($expected['remaining'], $result->batches[0]->quantity - $result->batches[0]->produced);
    }

    private function syntheticRounding(array $row): void
    {
        $calculator = new FleetCalculator();
        $method = new \ReflectionMethod(FleetCalculator::class, 'certifiedHalfUp');
        foreach ($row['input']['values'] as $i => $value) {
            $exact = BigRational::ofFraction($value['numerator'], $value['denominator']);
            self::assertSame($row['expected']['halfUp'][$i], $method->invoke($calculator, [$exact, $exact]));
        }
    }

    private function timestampOverflow(array $row): void
    {
        $input = $row['input'];
        $departure = \Brick\Math\BigInteger::of($input['departure']);
        self::assertTrue($departure->isPositive());
        self::assertLessThanOrEqual(0, $departure->compareTo(PHP_INT_MAX));
        $departureInt = $departure->toInt();
        $fleetInput = $input['ships'][0];
        $research = ['spy' => 0, 'energy' => 0, 'combustion' => $fleetInput['combustionLevel'], 'impulse' => 0, 'expedition' => 0];
        $quoted = (new FleetCalculator())->calculate(new Coordinates(1, 1, 3), new Coordinates(1, 1, 4),
            ['small_cargo' => $fleetInput['count'], 'colony_ship' => 0], $research, $input['speedIndex']);
        self::assertSame($row['expected']['arrivalOffset'], $quoted['arrival_offset']);
        self::assertSame($row['expected']['returnOffset'], $quoted['return_offset']);
        $state = $this->transportState($fleetInput['count'], $departureInt);
        $before = $state->toArray();
        $result = (new AccountEngine(EconomySettings::defaults()))->dispatch($state, '1', Mission::Transport,
            $state->planets['2']->coordinates, '2', ['small_cargo' => $fleetInput['count'], 'colony_ship' => 0],
            ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'], $input['speedIndex'], 'overflow-flight', $departureInt);
        self::assertFalse($result->isAccepted());
        self::assertSame('timestamp_range', $result->rejection);
        self::assertSame($row['expected']['payment'], 'none');
        self::assertSame($row['expected']['persistedFleet'], 'none');
        self::assertSame([], $result->outcomes);
        self::assertSame($before, $result->state->toArray());
    }

    private function strictTransport(array $row): void
    {
        $engine = new AccountEngine(EconomySettings::defaults());
        foreach ($row['input']['cargo'] as $value) {
            $state = $this->transportState(1);
            $result = $engine->dispatch($state, '1', Mission::Transport, $state->planets['2']->coordinates, '2',
                ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => $value, 'crystal' => '0', 'deuterium' => '0'],
                10, 'strict-'.$value, 0);
            $expectation = $row['expected']['transport'][$value];
            if ($expectation === 'accept-if-funded-and-capacity-fits') {
                self::assertTrue($result->isAccepted());
                continue;
            }
            self::assertFalse($result->isAccepted());
            $expectedCode = $value === '0' ? 'invalid_mission_payload' : 'invalid_cargo_amount';
            self::assertSame($expectedCode, $result->rejection);
        }
        foreach ($row['input']['destinationOwnership'] as $ownership) {
            $state = $this->transportState(1);
            $destination = $ownership === 'owner' ? '2' : 'foreign-planet-reference';
            $result = $engine->dispatch($state, '1', Mission::Transport, $state->planets['2']->coordinates, $destination,
                ['small_cargo' => 1, 'colony_ship' => 0], ['metal' => '1', 'crystal' => '0', 'deuterium' => '0'],
                10, 'ownership-'.$ownership, 0);
            if ($ownership === 'owner') { self::assertTrue($result->isAccepted()); }
            else { self::assertSame('transport_destination_not_owned', $result->rejection); }
        }
        self::assertSame('reject', $row['expected']['foreign']);
    }

    private function colonizationPolicy(array $row): void
    {
        $input = $row['input'];
        $rules = new ColonyRules();
        $research = ['spy' => 3, 'energy' => 1, 'combustion' => 2, 'impulse' => 3, 'expedition' => $input['expeditionLevelAtLaunch']];
        $levels = BuildingLevels::fromArray(['laboratory' => 3]);
        $resources = ResourceAmounts::fromStrings('1000000', '1000000', '1000000');
        $sourceCoordinates = new Coordinates(1, 1, 3);
        $target = new Coordinates(1, 1, $input['destinationPosition']);
        $arrival = (new FleetCalculator())->calculate($sourceCoordinates, $target,
            ['small_cargo' => 0, 'colony_ship' => $input['colonyShips']], $research, 10)['arrival_offset'];
        $expeditionCost = (new ResearchCalculator())->priceFor(Technology::Expedition, $input['expeditionLevelAtArrival']);
        $sourceEconomy = new PlanetEconomyState($resources->minus($expeditionCost), $levels, 40, 163, 3, 0);
        $planets = ['1' => new AccountPlanet('1', $sourceCoordinates, 0, $sourceEconomy,
            ['small_cargo' => 0, 'colony_ship' => $input['colonyShips']])];
        for ($i = 5; count($planets) < $input['ownedPlanetsBeforeArrival']; ++$i) {
            $ref = (string) (count($planets) + 1);
            $planets[$ref] = new AccountPlanet($ref, new Coordinates(1, 1, $i), 0,
                new PlanetEconomyState(ResourceAmounts::zero(), BuildingLevels::fromArray([]), 40, 163, 0, 0));
        }
        $entry = new ResearchEntry('fixture-expedition', Technology::Expedition, $input['expeditionLevelAtArrival'], '1', 0,
            0, $arrival, $expeditionCost, 'active');
        $state = new AccountState('fixture-colonizer', 0, $research, [$entry], $planets);
        $expectedBand = $rules->climateBand($target->position);
        $draws = ['fixture-colony' => ['temperature_max' => intdiv($expectedBand[0] + $expectedBand[1], 2),
            'fields_total' => intdiv($expectedBand[2] + $expectedBand[3], 2)]];
        $inputs = new ArrivalInputs([], $draws);
        $engine = new AccountEngine(EconomySettings::defaults());
        $dispatch = $engine->dispatch($state, '1', Mission::Colonize, $target, null,
            ['small_cargo' => 0, 'colony_ship' => $input['colonyShips']],
            ['metal' => (string) $input['cargo'], 'crystal' => '0', 'deuterium' => '0'], 10, 'fixture-colony', 0, $inputs);
        self::assertTrue($dispatch->isAccepted());
        self::assertSame($input['expeditionLevelAtLaunch'], $dispatch->state->research[Technology::Expedition->value]);
        self::assertSame($row['expected']['launch'], 'allowed-with-eligibility-warning');
        self::assertSame('colony_eligibility_not_met_at_dispatch', $dispatch->outcomes[0]['code']);
        self::assertArrayNotHasKey('colony:fixture-colony', $dispatch->state->planets);
        $fleet = $dispatch->state->fleets[0];
        self::assertSame($arrival, $fleet->arrivesAt);
        $resolved = $engine->advance($dispatch->state, $fleet->arrivesAt, $inputs);
        self::assertTrue($resolved->isAccepted(), json_encode([$resolved->rejection, $resolved->context], JSON_THROW_ON_ERROR));
        self::assertSame($input['expeditionLevelAtArrival'], $resolved->state->research[Technology::Expedition->value]);
        self::assertSame($input['ownedPlanetsBeforeArrival'] + 1, count($resolved->state->planets));
        self::assertArrayHasKey('colony:fixture-colony', $resolved->state->planets);
        $colony = $resolved->state->planets['colony:fixture-colony'];
        self::assertSame($fleet->arrivesAt, $colony->bornAt);
        self::assertSame($fleet->arrivesAt, $colony->economy->lastSettledAt);
        self::assertSame($row['expected']['newPlanetResources']['metal'], $colony->economy->resources->get(Resource::Metal)->toInt());
        self::assertSame($row['expected']['newPlanetResources']['crystal'], $colony->economy->resources->get(Resource::Crystal)->toInt());
        self::assertSame($row['expected']['newPlanetResources']['deuterium'], $colony->economy->resources->get(Resource::Deuterium)->toInt());
        self::assertSame('complete', $resolved->state->fleets[0]->status);
        self::assertSame(0, $resolved->state->fleets[0]->ships[Ship::ColonyShip->value]);
        self::assertSame('colonized', $resolved->state->fleets[0]->outcome);
        self::assertSame('scheduled-arrival', $row['expected']['birth']);
    }

    private function transportState(int $cargoShips, int $now = 0): AccountState
    {
        $homeLevels = BuildingLevels::fromArray([]);
        $source = new AccountPlanet('1', new Coordinates(1, 1, 3), $now,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $homeLevels, 40, 163, 0, $now),
            ['small_cargo' => $cargoShips, 'colony_ship' => 0]);
        $destination = new AccountPlanet('2', new Coordinates(1, 1, 4), $now,
            new PlanetEconomyState(ResourceAmounts::fromStrings('100000', '100000', '100000'), $homeLevels, 40, 163, 0, $now));
        $research = $this->zeroResearch();
        $research['combustion'] = 2;
        return new AccountState('fixture-owner', $now, $research, [], ['1' => $source, '2' => $destination]);
    }

    private function assertAmounts(array $expected, ResourceAmounts $actual): void
    {
        foreach ([[Resource::Metal, 'metal'], [Resource::Crystal, 'crystal'], [Resource::Deuterium, 'deuterium']] as [$resource, $key]) {
            self::assertSame(0, $actual->get($resource)->compareTo($expected[$key]), $key.' independent fixture value');
        }
    }

    private function technology(int $legacyId): Technology
    {
        foreach (Technology::cases() as $technology) { if ($technology->legacyId() === $legacyId) return $technology; }
        self::fail('Unknown technology fixture ID '.$legacyId);
    }

    private function ship(int $legacyId): Ship
    {
        foreach (Ship::cases() as $ship) { if ($ship->legacyId() === $legacyId) return $ship; }
        self::fail('Unknown ship fixture ID '.$legacyId);
    }

    private function zeroResearch(): array
    {
        return ['spy' => 0, 'energy' => 0, 'combustion' => 0, 'impulse' => 0, 'expedition' => 0];
    }

    private function researchWith(array $overrides): array { return array_replace($this->zeroResearch(), $overrides); }
}
