<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'research_entry')]
#[ORM\UniqueConstraint(name: 'uniq_research_owner_token', columns: ['owner_id', 'command_token'])]
#[ORM\UniqueConstraint(name: 'uniq_research_owner_position', columns: ['owner_id', 'position'])]
#[ORM\Index(name: 'idx_research_pending', columns: ['owner_id', 'status', 'position'])]
#[ORM\Index(name: 'idx_research_due', columns: ['status', 'completes_at'])]
class ResearchEntry
{
    #[ORM\Id, ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE', foreignKey: 'fk_research_owner')]
    private User $owner;
    #[ORM\ManyToOne(targetEntity: Planet::class)]
    #[ORM\JoinColumn(name: 'source_planet_id', nullable: false, onDelete: 'CASCADE', foreignKey: 'fk_research_source')]
    private Planet $sourcePlanet;
    #[ORM\Column(type: 'bigint')]
    private string $position;
    #[ORM\Column(name: 'command_token', length: 32)]
    private string $commandToken;
    #[ORM\Column(name: 'technology_id', type: 'smallint', options: ['unsigned' => true])]
    private int $technologyId;
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
    #[ORM\Column(name: 'cost_metal', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $costMetal;
    #[ORM\Column(name: 'cost_crystal', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $costCrystal;
    #[ORM\Column(name: 'cost_deuterium', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $costDeuterium;
    #[ORM\Column(name: 'failure_reason', length: 255, nullable: true)]
    private ?string $failureReason = null;
}
