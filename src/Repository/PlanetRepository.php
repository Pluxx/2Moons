<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Brick\Math\BigInteger;

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

    public function homePlanetIdForOwner(int $ownerId): ?int
    {
        $value=$this->getEntityManager()->getConnection()->fetchOne('SELECT home_planet_id FROM game_user WHERE id=?',[$ownerId]);
        if ($value===false || $value===null) return null;
        $id=filter_var($value,FILTER_VALIDATE_INT);
        return is_int($id)&&$id>0?$id:null;
    }

    public function ownerIdForPlanet(int $planetId): ?int
    {
        $value=$this->getEntityManager()->getConnection()->fetchOne('SELECT owner_id FROM planet WHERE id=?',[$planetId]);
        if ($value===false || $value===null) return null;
        $id=filter_var($value,FILTER_VALIDATE_INT);
        return is_int($id)&&$id>0?$id:null;
    }

    /** @return list<int> Distinct owner candidates due through at least one event type. */
    public function findDueOwnerIds(string $timestamp, int $limit): array
    {
        $sql="SELECT owner_id FROM (
            SELECT p.owner_id AS owner_id FROM construction_entry e JOIN planet p ON p.id=e.planet_id WHERE e.status='active' AND e.completes_at<=?
            UNION SELECT owner_id FROM research_entry WHERE status='active' AND completes_at<=?
            UNION SELECT p.owner_id AS owner_id FROM shipyard_batch b JOIN planet p ON p.id=b.planet_id WHERE b.status='active' AND b.completes_at<=?
            UNION SELECT owner_id FROM fleet WHERE status='outbound' AND arrives_at<=?
            UNION SELECT owner_id FROM fleet WHERE status='returning' AND returns_at<=?
        ) due ORDER BY owner_id LIMIT ?";
        $values=$this->getEntityManager()->getConnection()->fetchFirstColumn($sql,[$timestamp,$timestamp,$timestamp,$timestamp,$timestamp,$limit]);
        $ids=[]; foreach($values as $value){$id=filter_var($value,FILTER_VALIDATE_INT);if(!is_int($id)||$id<1)throw new \UnexpectedValueException('Due owner query returned malformed ID.');$ids[]=$id;}
        return $ids;
    }
}
