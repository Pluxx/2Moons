<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Economy\Building;
use App\Domain\Economy\ConstructionEntry as DomainConstructionEntry;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\ConstructionOutcome;
use App\Domain\Economy\OutcomeStatus as DomainOutcomeStatus;
use App\Repository\ConstructionEntryRepository;
use Brick\Math\BigInteger;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConstructionEntryRepository::class)]
#[ORM\Table(name: 'construction_entry')]
#[ORM\UniqueConstraint(name: 'uniq_construction_planet_position', columns: ['planet_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_construction_planet_token', columns: ['planet_id', 'command_token'])]
#[ORM\Index(name: 'idx_construction_pending', columns: ['planet_id', 'status', 'position'])]
#[ORM\Index(name: 'idx_construction_due', columns: ['status', 'completes_at'])]
class ConstructionEntry
{
    public const WAITING = 'waiting';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Planet::class, inversedBy: 'constructionEntries')]
    #[ORM\JoinColumn(name: 'planet_id', nullable: false, onDelete: 'CASCADE')]
    private Planet $planet;

    #[ORM\Column(type: 'bigint')]
    private string $position;

    #[ORM\Column(name: 'command_token', length: 32)]
    private string $commandToken;

    #[ORM\Column(name: 'building_id', type: 'smallint', options: ['unsigned' => true])]
    private int $buildingId;

    #[ORM\Column(name: 'target_level', type: 'smallint', options: ['unsigned' => true])]
    private int $targetLevel;

    #[ORM\Column(length: 10)]
    private string $status;

    #[ORM\Column(name: 'enqueued_at', type: 'bigint')]
    private string $enqueuedAt;

    #[ORM\Column(name: 'started_at', type: 'bigint', nullable: true)]
    private ?string $startedAt = null;

    #[ORM\Column(name: 'completes_at', type: 'bigint', nullable: true)]
    private ?string $completesAt = null;

    #[ORM\Column(name: 'resolved_at', type: 'bigint', nullable: true)]
    private ?string $resolvedAt = null;

    #[ORM\Column(name: 'failure_reason', length: 255, nullable: true)]
    private ?string $failureReason = null;

    public function __construct(Planet $planet, string $position, DomainConstructionEntry $entry)
    {
        $this->planet = $planet;
        $this->position = $position;
        $this->commandToken = $entry->commandToken;
        $this->buildingId = $entry->building->legacyId();
        $this->targetLevel = $entry->targetLevel;
        $this->enqueuedAt = (string) $entry->enqueuedAt;
        $this->setPendingEntry($entry);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function getCommandToken(): string
    {
        return $this->commandToken;
    }

    public function getBuildingId(): int
    {
        return $this->buildingId;
    }

    public function getTargetLevel(): int
    {
        return $this->targetLevel;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getCompletesAt(): ?string
    {
        return $this->completesAt;
    }

    public function getEnqueuedAt(): string
    {
        return $this->enqueuedAt;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function setPosition(string $position): void
    {
        $this->position = $position;
    }

    public function setPendingEntry(DomainConstructionEntry $entry): void
    {
        $this->status = $entry->isActive() ? self::ACTIVE : self::WAITING;
        $this->startedAt = $entry->startedAt === null ? null : (string) $entry->startedAt;
        $this->completesAt = $entry->completesAt === null ? null : (string) $entry->completesAt;
        $this->resolvedAt = null;
        $this->failureReason = null;
    }

    public function resolve(ConstructionOutcome $outcome): void
    {
        if ($outcome->status === DomainOutcomeStatus::Completed) {
            if ($outcome->startedAt === null || $outcome->completesAt === null || $outcome->completesAt !== $outcome->resolvedAt) {
                throw new EconomyDataException('Completed construction outcome is missing its scheduled execution interval.');
            }
            $this->status = self::COMPLETED;
            $this->startedAt = (string) $outcome->startedAt;
            $this->completesAt = (string) $outcome->completesAt;
        } else {
            if ($outcome->startedAt !== null || $outcome->completesAt !== null || $outcome->failureReason === null) {
                throw new EconomyDataException('Failed construction outcome contains invalid execution metadata.');
            }
            $this->status = self::FAILED;
            $this->startedAt = null;
            $this->completesAt = null;
        }
        $this->resolvedAt = (string) $outcome->resolvedAt;
        $this->failureReason = $outcome->failureReason;
    }

    public function toDomainEntry(): DomainConstructionEntry
    {
        $building = Building::fromLegacyId($this->buildingId);
        if ($building === null) {
            throw new EconomyDataException('Persisted construction references an unknown building.');
        }

        $enqueuedAt = self::bigintToNativeInt($this->enqueuedAt);
        if ($this->status === self::WAITING && $this->startedAt === null && $this->completesAt === null && $this->resolvedAt === null) {
            return DomainConstructionEntry::waiting($this->commandToken, $building, $this->targetLevel, $enqueuedAt);
        }
        if ($this->status === self::ACTIVE && $this->startedAt !== null && $this->completesAt !== null && $this->resolvedAt === null) {
            return DomainConstructionEntry::active(
                $this->commandToken,
                $building,
                $this->targetLevel,
                $enqueuedAt,
                self::bigintToNativeInt($this->startedAt),
                self::bigintToNativeInt($this->completesAt),
            );
        }

        throw new EconomyDataException('Persisted pending construction status/timestamps are inconsistent.');
    }

    public static function bigintToNativeInt(string $value): int
    {
        if (preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new EconomyDataException('Persisted construction timestamp is not a canonical signed integer.');
        }
        $integer = BigInteger::of($value);
        if ($integer->compareTo(PHP_INT_MIN) < 0 || $integer->compareTo(PHP_INT_MAX) > 0) {
            throw new EconomyDataException('Persisted construction timestamp exceeds native integer range.');
        }

        return $integer->toInt();
    }
}
