<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConstructionEntry;
use App\Entity\Planet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

final class ConstructionEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConstructionEntry::class);
    }

    /** @return list<ConstructionEntry> */
    public function findPendingForPlanet(Planet $planet): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.planet = :planet')
            ->andWhere('entry.status IN (:statuses)')
            ->setParameter('planet', $planet)
            ->setParameter('statuses', [ConstructionEntry::ACTIVE, ConstructionEntry::WAITING])
            ->orderBy('entry.position', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    public function findForPlanetToken(Planet $planet, string $token): ?ConstructionEntry
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.planet = :planet')
            ->andWhere('entry.commandToken = :token')
            ->setParameter('planet', $planet)
            ->setParameter('token', $token)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /** @return list<ConstructionEntry> */
    public function findRecentForPlanet(Planet $planet, int $limit = 10): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.planet = :planet')
            ->andWhere('entry.status IN (:statuses)')
            ->setParameter('planet', $planet)
            ->setParameter('statuses', [ConstructionEntry::COMPLETED, ConstructionEntry::FAILED])
            ->orderBy('entry.resolvedAt', 'DESC')
            ->addOrderBy('entry.position', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    public function maximumPosition(Planet $planet): ?string
    {
        $value = $this->createQueryBuilder('entry')
            ->select('MAX(entry.position)')
            ->andWhere('entry.planet = :planet')
            ->setParameter('planet', $planet)
            ->getQuery()
            ->getSingleScalarResult();

        return $value === null ? null : (string) $value;
    }

    /** @return list<int> */
    public function findDuePlanetIds(string $timestamp, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DISTINCT planet_id FROM construction_entry WHERE status = ? AND completes_at <= ? ORDER BY planet_id ASC LIMIT ?',
            [ConstructionEntry::ACTIVE, $timestamp, $limit],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER],
        )->fetchFirstColumn();

        $ids = [];
        foreach ($rows as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \UnexpectedValueException('Due queue query returned a non-integer planet id.');
            }
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($id) || $id < 1) {
                throw new \UnexpectedValueException('Due queue query returned an invalid planet id.');
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
