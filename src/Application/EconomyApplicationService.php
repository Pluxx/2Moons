<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Economy\Building;
use App\Domain\Economy\ConstructionOutcome;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\EconomyEngine;
use App\Domain\Economy\EconomyResult;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Entity\ConstructionEntry;
use App\Entity\Planet;
use App\Entity\User;
use App\Presentation\PlanetViewBuilder;
use App\Repository\ConstructionEntryRepository;
use App\Repository\PlanetRepository;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class EconomyApplicationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlanetRepository $planets,
        private ConstructionEntryRepository $entries,
        private EconomyEngine $engine,
        private EconomySettings $settings,
        private ClockInterface $clock,
        private PlanetViewBuilder $viewBuilder,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function overviewFor(User $owner): ?array
    {
        if ($owner->getId() === null) {
            return null;
        }

        return $this->entityManager->wrapInTransaction(function () use ($owner): ?array {
            $planet = $this->planets->lockForOwner($owner->getId());
            if ($planet === null) {
                return null;
            }

            $now = $this->clock->now()->getTimestamp();
            $pending = $this->entries->findPendingForPlanet($planet);
            $state = $planet->toDomainState($pending);
            $advance = $this->engine->advance($state, $now, $this->settings);
            if (!$advance->isAccepted()) {
                throw new EconomyDataException('Current server clock could not advance the owned planet.');
            }
            $this->persistTransition($planet, $pending, $advance);
            $this->entityManager->flush();

            return $this->viewBuilder->build(
                $planet,
                $advance->state,
                $this->entries->findPendingForPlanet($planet),
                $this->entries->findRecentForPlanet($planet),
                $now,
            );
        });
    }

    public function enqueueForOwner(User $owner, int $buildingId, int $expectedTarget, string $commandToken): CommandResponse
    {
        $building = Building::fromLegacyId($buildingId);
        if ($building === null || preg_match('/\A[a-f0-9]{32}\z/D', $commandToken) !== 1) {
            return new CommandResponse(false);
        }
        if ($owner->getId() === null) {
            return new CommandResponse(false);
        }

        return $this->entityManager->wrapInTransaction(function () use ($owner, $buildingId, $building, $expectedTarget, $commandToken): CommandResponse {
            $planet = $this->planets->lockForOwner($owner->getId());
            if ($planet === null) {
                return new CommandResponse(false);
            }

            $existing = $this->entries->findForPlanetToken($planet, $commandToken);
            if ($existing !== null) {
                if ($existing->getBuildingId() !== $buildingId || $existing->getTargetLevel() !== $expectedTarget) {
                    throw new IdempotencyConflict('Command token was already used for a different construction request.');
                }

                return new CommandResponse(true, true, $existing->getStatus());
            }

            $pending = $this->entries->findPendingForPlanet($planet);
            $now = $this->clock->now()->getTimestamp();
            $state = $planet->toDomainState($pending);
            $result = $this->engine->enqueue($state, $building, $expectedTarget, $commandToken, $now, $this->settings);
            if (!$result->isAccepted()) {
                return new CommandResponse(false, rejection: $result->rejection);
            }

            $this->persistTransition($planet, $pending, $result);

            return new CommandResponse(true);
        });
    }

    /** Settle a previously selected worker candidate under the planet lock. */
    public function settlePlanetById(int $planetId): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($planetId): bool {
            $planet = $this->planets->lockById($planetId);
            if ($planet === null) {
                return false;
            }

            $now = $this->clock->now()->getTimestamp();
            $pending = $this->entries->findPendingForPlanet($planet);
            $state = $planet->toDomainState($pending);
            $result = $this->engine->advance($state, $now, $this->settings);
            if (!$result->isAccepted()) {
                throw new EconomyDataException('Worker clock could not advance a locked planet.');
            }
            $this->persistTransition($planet, $pending, $result);

            return true;
        });
    }

    private function persistTransition(Planet $planet, array $beforePending, EconomyResult $result): void
    {
        $planet->apply($result->state);
        $byToken = [];
        foreach ($beforePending as $entry) {
            $byToken[$entry->getCommandToken()] = $entry;
        }

        foreach ($result->outcomes as $outcome) {
            $entry = $byToken[$outcome->commandToken] ?? null;
            if (!$entry instanceof ConstructionEntry) {
                throw new EconomyDataException('Domain outcome has no matching persisted pending row.');
            }
            $entry->resolve($outcome);
        }

        $nextPosition = null;
        foreach ($result->state->pendingEntries as $domainEntry) {
            $entry = $byToken[$domainEntry->commandToken] ?? null;
            if ($entry instanceof ConstructionEntry) {
                $entry->setPendingEntry($domainEntry);
                continue;
            }

            $nextPosition ??= $this->nextPosition($planet);
            $entry = new ConstructionEntry($planet, $nextPosition, $domainEntry);
            $this->entityManager->persist($entry);
            $byToken[$domainEntry->commandToken] = $entry;
        }
    }

    private function nextPosition(Planet $planet): string
    {
        $maximum = $this->entries->maximumPosition($planet);
        if ($maximum === null) {
            return '1';
        }
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $maximum) !== 1) {
            throw new EconomyDataException('Persisted construction position is malformed.');
        }

        $next = BigInteger::of($maximum)->plus(1);
        if ($next->compareTo(PHP_INT_MAX) > 0) {
            throw new EconomyDataException('Construction position exceeded supported BIGINT range.');
        }

        return (string) $next;
    }

}
