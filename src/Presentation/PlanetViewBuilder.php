<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\Economy\Building;
use App\Domain\Economy\ConstructionEntry as DomainConstructionEntry;
use App\Domain\Economy\EconomyCalculator;
use App\Domain\Economy\EconomyEngine;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\OutcomeStatus;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\ProductionSnapshot;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use App\Entity\ConstructionEntry;
use App\Entity\Planet;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class PlanetViewBuilder
{
    public function __construct(
        private EconomyCalculator $calculator,
        private EconomyEngine $engine,
        private EconomySettings $settings,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /** @param list<ConstructionEntry> $pending @param list<ConstructionEntry> $recent */
    public function build(Planet $planet, PlanetEconomyState $state, array $pending, array $recent, int $serverNow): array
    {
        $production = $this->calculator->calculate($state, $this->settings);
        $mode = $state->pendingEntries === [] ? 'start' : 'queue';
        $buildings = [];

        foreach (Building::cases() as $building) {
            $quote = $this->engine->quote($state, $building, $this->settings);
            $target = $state->levels->get($building) + $this->pendingCount($state, $building) + 1;
            $cost = $target <= $building->maximumLevel()
                ? $this->calculator->priceFor($building, $target)
                : ResourceAmounts::zero();
            $duration = $target <= $building->maximumLevel()
                ? $this->calculator->buildDurationSeconds($building, $target, $this->settings)
                : null;

            $reason = null;
            if (!$quote->isQuoted()) {
                $reason = $this->quoteRejectionMessage($quote->rejection->code->value);
            } elseif (!$quote->quote->queueSlotAvailable) {
                $reason = 'The construction queue is full.';
            } elseif (!$quote->quote->fieldAvailable) {
                $reason = 'No planet fields are available for another queued building.';
            } elseif ($mode === 'start' && !$quote->quote->affordableNow) {
                $reason = 'There are not enough resources to start this construction.';
            }

            $costRows = [];
            foreach (Resource::cases() as $resource) {
                [$amount, $approximate] = self::format($cost->get($resource));
                $costRows[] = [
                    'key' => $resource->value,
                    'label' => self::resourceLabel($resource),
                    'amount' => $amount,
                    'approximate' => $approximate,
                ];
            }

            $buildings[] = [
                'id' => $building->legacyId(),
                'key' => $building->value,
                'label' => self::buildingLabel($building),
                'level' => $state->levels->get($building),
                'target_level' => $target,
                'costs' => $costRows,
                'duration_seconds' => $duration,
                'enabled' => $reason === null,
                'reason' => $reason,
                'mode' => $mode,
                'csrf_token' => $this->csrfTokenManager->getToken('build_'.$building->legacyId())->getValue(),
                'command_token' => bin2hex(random_bytes(16)),
            ];
        }

        return [
            'server_now' => $serverNow,
            'planet' => [
                'name' => $planet->getName(),
                'temperature_max' => $state->temperatureMax,
                'fields_used' => $state->fieldsUsed,
                'fields_total' => $state->fieldsTotal,
                'fields_reserved' => count($state->pendingEntries),
            ],
            'resources' => $this->resourceRows($state->resources, $production),
            'energy' => $this->energyView($production),
            'buildings' => $buildings,
            'queue' => $this->queueRows($pending),
            'recent' => $this->recentRows($recent),
        ];
    }

    private function resourceRows(ResourceAmounts $balances, ProductionSnapshot $production): array
    {
        $rows = [];
        foreach (Resource::cases() as $resource) {
            [$stock, $stockApproximate] = self::format($balances->get($resource));
            [$rate, $rateApproximate] = self::format($production->totalRatesPerHour->get($resource));
            [$capacity] = self::format($production->capacities->get($resource));
            $rows[] = [
                'key' => $resource->value,
                'label' => self::resourceLabel($resource),
                'stock' => $stock,
                'rate' => $rate,
                'capacity' => $capacity,
                'stock_approximate' => $stockApproximate,
                'rate_approximate' => $rateApproximate,
            ];
        }

        return $rows;
    }

    private function energyView(ProductionSnapshot $production): array
    {
        [$generated] = self::format($production->generatedEnergy);
        [$demand, $demandApproximate] = self::format($production->energyDemand);
        $factor = $production->productionFactor;

        return [
            'generated' => $generated,
            'demand' => $demand,
            'demand_approximate' => $demandApproximate,
            'factor' => $factor === null
                ? '—'
                : (string) $factor->multipliedBy(100)->toScale(2, RoundingMode::HalfUp).'%',
            'constrained' => $factor !== null && $factor->compareTo(1) < 0,
            'no_demand' => $factor === null,
        ];
    }

    /** @param list<ConstructionEntry> $pending */
    private function queueRows(array $pending): array
    {
        $rows = [];
        $estimate = null;
        foreach ($pending as $entity) {
            $domain = $entity->toDomainEntry();
            if ($domain->isActive()) {
                $estimate = $domain->completesAt;
                $rows[] = [
                    'building_label' => self::buildingLabel($domain->building),
                    'target_level' => $domain->targetLevel,
                    'status' => 'active',
                    'started_at' => $domain->startedAt,
                    'completes_at' => $domain->completesAt,
                    'estimated_completes_at' => null,
                ];
                continue;
            }

            $duration = $this->calculator->buildDurationSeconds($domain->building, $domain->targetLevel, $this->settings);
            $estimate = $duration === null || $estimate === null
                ? null
                : $this->calculator->checkedTimestampAdd($estimate, $duration);
            $rows[] = [
                'building_label' => self::buildingLabel($domain->building),
                'target_level' => $domain->targetLevel,
                'status' => 'waiting',
                'started_at' => null,
                'completes_at' => null,
                'estimated_completes_at' => $estimate,
            ];
        }

        return $rows;
    }

    /** @param list<ConstructionEntry> $entries */
    private function recentRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $building = Building::fromLegacyId($entry->getBuildingId());
            if ($building === null || $entry->getResolvedAt() === null) {
                continue;
            }
            $reason = match ($entry->getFailureReason()) {
                'insufficient_resources_at_activation' => 'Insufficient resources when construction reached the front of the queue.',
                'dependent_upgrade_failed' => 'A previous upgrade of this building failed.',
                default => null,
            };
            $rows[] = [
                'building_label' => self::buildingLabel($building),
                'target_level' => $entry->getTargetLevel(),
                'status' => $entry->getStatus(),
                'reason' => $reason,
                'resolved_at' => ConstructionEntry::bigintToNativeInt($entry->getResolvedAt()),
            ];
        }

        return $rows;
    }

    private function pendingCount(PlanetEconomyState $state, Building $building): int
    {
        $count = 0;
        foreach ($state->pendingEntries as $entry) {
            if ($entry->building === $building) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return array{string, bool} */
    private static function format(BigRational $value): array
    {
        $decimal = (string) $value->toScale(6, RoundingMode::HalfUp);
        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }
        if ($decimal === '-0') {
            $decimal = '0';
        }

        return [$decimal, $value->compareTo(BigRational::of($decimal)) !== 0];
    }

    private static function resourceLabel(Resource $resource): string
    {
        return match ($resource) {
            Resource::Metal => 'Metal',
            Resource::Crystal => 'Crystal',
            Resource::Deuterium => 'Deuterium',
        };
    }

    private static function buildingLabel(Building $building): string
    {
        return match ($building) {
            Building::MetalMine => 'Metal mine',
            Building::CrystalMine => 'Crystal mine',
            Building::DeuteriumSynthesizer => 'Deuterium synthesizer',
            Building::SolarPlant => 'Solar plant',
            Building::MetalStorage => 'Metal storage',
            Building::CrystalStorage => 'Crystal storage',
            Building::DeuteriumStorage => 'Deuterium storage',
        };
    }

    private function quoteRejectionMessage(string $code): string
    {
        return match ($code) {
            'level_limit' => 'This building has reached its maximum level.',
            'timestamp_range' => 'This construction exceeds the supported time range.',
            default => 'This construction is not currently available.',
        };
    }
}
