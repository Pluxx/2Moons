<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shipyard_batch')]
#[ORM\UniqueConstraint(name: 'uniq_batch_planet_token', columns: ['planet_id', 'command_token'])]
#[ORM\UniqueConstraint(name: 'uniq_batch_planet_position', columns: ['planet_id', 'position'])]
#[ORM\Index(name: 'idx_batch_pending', columns: ['planet_id', 'status', 'position'])]
#[ORM\Index(name: 'idx_batch_due', columns: ['status', 'completes_at'])]
class ShipyardBatch
{
    #[ORM\Id, ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;
    #[ORM\ManyToOne(targetEntity: Planet::class)]
    #[ORM\JoinColumn(name: 'planet_id', nullable: false, onDelete: 'CASCADE', foreignKey: 'fk_batch_planet')]
    private Planet $planet;
    #[ORM\Column(type: 'bigint')]
    private string $position;
    #[ORM\Column(name: 'command_token', length: 32)]
    private string $commandToken;
    #[ORM\Column(name: 'ship_id', type: 'smallint', options: ['unsigned' => true])]
    private int $shipId;
    #[ORM\Column(type: 'bigint')]
    private string $quantity;
    #[ORM\Column(type: 'bigint')]
    private string $produced;
    #[ORM\Column(name: 'cost_metal', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $costMetal;
    #[ORM\Column(name: 'cost_crystal', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $costCrystal;
    #[ORM\Column(name: 'cost_deuterium', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $costDeuterium;
    #[ORM\Column(length: 10)]
    private string $status;
    #[ORM\Column(name: 'enqueued_at', type: 'bigint')]
    private string $enqueuedAt;
    #[ORM\Column(name: 'started_at', type: 'bigint', nullable: true)]
    private ?string $startedAt = null;
    #[ORM\Column(name: 'unit_seconds', type: 'bigint', nullable: true)]
    private ?string $unitSeconds = null;
    #[ORM\Column(name: 'completes_at', type: 'bigint', nullable: true)]
    private ?string $completesAt = null;
    #[ORM\Column(name: 'resolved_at', type: 'bigint', nullable: true)]
    private ?string $resolvedAt = null;
}
