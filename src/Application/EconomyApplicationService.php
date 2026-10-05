<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Economy\EconomyRejection;
use App\Domain\Economy\RejectionCode;
use App\Entity\User;
use App\Repository\PlanetRepository;

/** Seven-building home-only compatibility facade; writes still use the account coordinator. */
final readonly class EconomyApplicationService
{
    public function __construct(private GameApplicationService $game, private PlanetRepository $planets) {}

    public function overviewFor(User $owner): ?array
    {
        if ($owner->getId()===null) return null;
        $home=$this->planets->homePlanetIdForOwner($owner->getId());
        if ($home===null) return null;
        return $this->game->overview($owner,$home);
    }

    public function enqueueForOwner(User $owner, int $buildingId, int $expectedTarget, string $commandToken): CommandResponse
    {
        if ($owner->getId()===null) return new CommandResponse(false);
        $home=$this->planets->homePlanetIdForOwner($owner->getId());
        if ($home===null) return new CommandResponse(false);
        $response=$this->game->enqueueConstruction($owner,$home,$buildingId,$expectedTarget,$commandToken);
        $code=$response->rejectionCode===null?null:RejectionCode::tryFrom($response->rejectionCode);
        return new CommandResponse($response->accepted,$response->replayed,$response->existingStatus,$code===null?null:new EconomyRejection($code));
    }

    /** Compatibility worker entry; the selected planet is resolved to its account before settling. */
    public function settlePlanetById(int $planetId): bool
    {
        $ownerId=$this->planets->ownerIdForPlanet($planetId);
        return $ownerId!==null && $this->game->settleAccountById($ownerId);
    }
}
