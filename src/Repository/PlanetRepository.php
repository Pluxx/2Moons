<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

final class PlanetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planet::class);
    }

    public function lockForOwner(int $ownerId): ?Planet
    {
        return $this->createQueryBuilder('planet')
            ->andWhere('IDENTITY(planet.owner) = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function lockById(int $planetId): ?Planet
    {
        return $this->createQueryBuilder('planet')
            ->andWhere('planet.id = :id')
            ->setParameter('id', $planetId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}
