<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fleet')]
#[ORM\UniqueConstraint(name: 'uniq_fleet_owner_token', columns: ['owner_id', 'command_token'])]
#[ORM\Index(name: 'idx_fleet_pending', columns: ['owner_id', 'status', 'arrives_at', 'returns_at'])]
#[ORM\Index(name: 'idx_fleet_recent', columns: ['owner_id', 'resolved_at'])]
class Fleet
{
    #[ORM\Id, ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE', foreignKey: 'fk_fleet_owner')]
    private User $owner;
    #[ORM\Column(name: 'command_token', length: 32)]
    private string $commandToken;
    #[ORM\Column(length: 10)]
    private string $mission;
    #[ORM\ManyToOne(targetEntity: Planet::class)]
    #[ORM\JoinColumn(name: 'source_planet_id', nullable: false, foreignKey: 'fk_fleet_source')]
    private Planet $sourcePlanet;
    #[ORM\ManyToOne(targetEntity: Planet::class)]
    #[ORM\JoinColumn(name: 'destination_planet_id', nullable: true, onDelete: 'SET NULL', foreignKey: 'fk_fleet_destination')]
    private ?Planet $destinationPlanet = null;
    #[ORM\ManyToOne(targetEntity: Planet::class)]
    #[ORM\JoinColumn(name: 'colony_planet_id', nullable: true, onDelete: 'SET NULL', foreignKey: 'fk_fleet_colony')]
    private ?Planet $colonyPlanet = null;
    #[ORM\Column(name: 'target_galaxy', type: 'smallint', options: ['unsigned' => true])]
    private int $targetGalaxy;
    #[ORM\Column(name: 'target_system', type: 'smallint', options: ['unsigned' => true])]
    private int $targetSystem;
    #[ORM\Column(name: 'target_position', type: 'smallint', options: ['unsigned' => true])]
    private int $targetPosition;
    #[ORM\Column(name: 'launch_small_cargo', type: 'bigint')]
    private string $launchSmallCargo;
    #[ORM\Column(name: 'launch_colony_ship', type: 'bigint')]
    private string $launchColonyShip;
    #[ORM\Column(name: 'small_cargo', type: 'bigint')]
    private string $smallCargo;
    #[ORM\Column(name: 'colony_ship', type: 'bigint')]
    private string $colonyShip;
    #[ORM\Column(name: 'launch_metal', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $launchMetal;
    #[ORM\Column(name: 'launch_crystal', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $launchCrystal;
    #[ORM\Column(name: 'launch_deuterium', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $launchDeuterium;
    #[ORM\Column(name: 'metal_cargo', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $metalCargo;
    #[ORM\Column(name: 'crystal_cargo', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $crystalCargo;
    #[ORM\Column(name: 'deuterium_cargo', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $deuteriumCargo;
    #[ORM\Column(type: 'bigint')]
    private string $fuel;
    #[ORM\Column(name: 'departed_at', type: 'bigint')]
    private string $departedAt;
    #[ORM\Column(name: 'arrives_at', type: 'bigint')]
    private string $arrivesAt;
    #[ORM\Column(name: 'returns_at', type: 'bigint')]
    private string $returnsAt;
    #[ORM\Column(name: 'speed_index', type: 'smallint', options: ['unsigned' => true])]
    private int $speedIndex;
    #[ORM\Column(name: 'calculation_payload', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $calculationPayload;
    #[ORM\Column(length: 10)]
    private string $status;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $outcome = null;
    #[ORM\Column(name: 'arrival_resolved_at', type: 'bigint', nullable: true)]
    private ?string $arrivalResolvedAt = null;
    #[ORM\Column(name: 'resolved_at', type: 'bigint', nullable: true)]
    private ?string $resolvedAt = null;
}
