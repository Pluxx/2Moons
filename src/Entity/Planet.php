<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Development\Coordinates;
use App\Domain\Economy\ResourceAmounts;
use App\Repository\PlanetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Brick\Math\BigInteger;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanetRepository::class)]
#[ORM\Table(name: 'planet')]
#[ORM\Index(name: 'idx_planet_owner', columns: ['owner_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_planet_coordinate', columns: ['universe_id', 'galaxy', 'system', 'position'])]
class Planet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'planets')]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 80)]
    private string $name = 'Homeworld';

    #[ORM\Column(name: 'metal_balance', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $metalBalance;

    #[ORM\Column(name: 'crystal_balance', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $crystalBalance;

    #[ORM\Column(name: 'deuterium_balance', type: 'text', columnDefinition: 'LONGTEXT NOT NULL')]
    private string $deuteriumBalance;

    #[ORM\Column(name: 'metal_mine_level', type: 'smallint', options: ['unsigned' => true])]
    private int $metalMineLevel;

    #[ORM\Column(name: 'crystal_mine_level', type: 'smallint', options: ['unsigned' => true])]
    private int $crystalMineLevel;

    #[ORM\Column(name: 'deuterium_synthesizer_level', type: 'smallint', options: ['unsigned' => true])]
    private int $deuteriumSynthesizerLevel;

    #[ORM\Column(name: 'solar_plant_level', type: 'smallint', options: ['unsigned' => true])]
    private int $solarPlantLevel;

    #[ORM\Column(name: 'metal_storage_level', type: 'smallint', options: ['unsigned' => true])]
    private int $metalStorageLevel;

    #[ORM\Column(name: 'crystal_storage_level', type: 'smallint', options: ['unsigned' => true])]
    private int $crystalStorageLevel;

    #[ORM\Column(name: 'deuterium_storage_level', type: 'smallint', options: ['unsigned' => true])]
    private int $deuteriumStorageLevel;

    #[ORM\Column(name: 'robotics_factory_level', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $roboticsFactoryLevel = 0;
    #[ORM\Column(name: 'shipyard_level', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $shipyardLevel = 0;
    #[ORM\Column(name: 'laboratory_level', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $laboratoryLevel = 0;
    #[ORM\Column(name: 'small_cargo_count', type: 'bigint', options: ['default' => 0])]
    private string $smallCargoCount = '0';
    #[ORM\Column(name: 'colony_ship_count', type: 'bigint', options: ['default' => 0])]
    private string $colonyShipCount = '0';
    #[ORM\Column(name: 'universe_id', type: 'smallint', options: ['unsigned' => true, 'default' => 1])]
    private int $universeId = 1;
    #[ORM\Column(name: 'galaxy', type: 'smallint', options: ['unsigned' => true, 'default' => 1])]
    private int $galaxy = 1;
    #[ORM\Column(name: 'system', type: 'smallint', options: ['unsigned' => true, 'default' => 1])]
    private int $system = 1;
    #[ORM\Column(name: 'position', type: 'smallint', options: ['unsigned' => true, 'default' => 3])]
    private int $position = 3;
    #[ORM\Column(name: 'born_at', type: 'bigint', options: ['default' => 0])]
    private string $bornAt = '0';

    #[ORM\Column(name: 'temperature_max', type: 'smallint')]
    private int $temperatureMax;

    #[ORM\Column(name: 'fields_total', type: 'smallint', options: ['unsigned' => true])]
    private int $fieldsTotal;

    #[ORM\Column(name: 'fields_used', type: 'smallint', options: ['unsigned' => true])]
    private int $fieldsUsed;

    #[ORM\Column(name: 'last_settled_at', type: 'bigint')]
    private string $lastSettledAt;

    /** @var Collection<int, ConstructionEntry> */
    #[ORM\OneToMany(mappedBy: 'planet', targetEntity: ConstructionEntry::class)]
    private Collection $constructionEntries;

    public function __construct(User $owner, PlanetEconomyState $state, ?Coordinates $coordinates = null, ?int $bornAt = null, string $name = 'Homeworld')
    {
        $this->owner = $owner;
        $owner->setPlanet($this);
        $this->constructionEntries = new ArrayCollection();
        $coordinates ??= new Coordinates(1, 1, 3);
        $this->galaxy = $coordinates->galaxy;
        $this->system = $coordinates->system;
        $this->position = $coordinates->position;
        $this->bornAt = (string) ($bornAt ?? $state->lastSettledAt);
        $this->name = $name;
        $this->apply($state);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCoordinates(): Coordinates { return new Coordinates($this->galaxy, $this->system, $this->position); }
    public function getBornAt(): string { return $this->bornAt; }
    public function getUniverseId(): int { return $this->universeId; }
    /** @return array<string,int> */
    public function shipInventory(): array
    {
        return ['small_cargo' => self::bigintToNativeInt($this->smallCargoCount), 'colony_ship' => self::bigintToNativeInt($this->colonyShipCount)];
    }
    public function setCoordinates(Coordinates $coordinates): void
    {
        $this->galaxy = $coordinates->galaxy; $this->system = $coordinates->system; $this->position = $coordinates->position;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTemperatureMax(): int
    {
        return $this->temperatureMax;
    }

    public function getFieldsTotal(): int
    {
        return $this->fieldsTotal;
    }

    public function getFieldsUsed(): int
    {
        return $this->fieldsUsed;
    }

    public function getLastSettledAt(): string
    {
        return $this->lastSettledAt;
    }

    public function apply(PlanetEconomyState $state): void
    {
        $resources = $state->resources->toCanonicalArray();
        $levels = $state->levels->toArray();
        $this->metalBalance = $resources['metal'];
        $this->crystalBalance = $resources['crystal'];
        $this->deuteriumBalance = $resources['deuterium'];
        $this->metalMineLevel = $levels[Building::MetalMine->value];
        $this->crystalMineLevel = $levels[Building::CrystalMine->value];
        $this->deuteriumSynthesizerLevel = $levels[Building::DeuteriumSynthesizer->value];
        $this->solarPlantLevel = $levels[Building::SolarPlant->value];
        $this->metalStorageLevel = $levels[Building::MetalStorage->value];
        $this->crystalStorageLevel = $levels[Building::CrystalStorage->value];
        $this->deuteriumStorageLevel = $levels[Building::DeuteriumStorage->value];
        $this->roboticsFactoryLevel = $levels[Building::RoboticsFactory->value];
        $this->shipyardLevel = $levels[Building::Shipyard->value];
        $this->laboratoryLevel = $levels[Building::Laboratory->value];
        $this->temperatureMax = $state->temperatureMax;
        $this->fieldsTotal = $state->fieldsTotal;
        $this->fieldsUsed = $state->fieldsUsed;
        $this->lastSettledAt = (string) $state->lastSettledAt;
    }

    public function setDomainState(PlanetEconomyState $state, array $ships): void
    {
        $this->apply($state);
        $this->smallCargoCount = (string) $ships['small_cargo'];
        $this->colonyShipCount = (string) $ships['colony_ship'];
    }

    /** @param list<ConstructionEntry> $entries */
    public function toDomainState(array $entries): PlanetEconomyState
    {
        return PlanetEconomyState::fromArray([
            'resources' => [
                'metal' => $this->metalBalance,
                'crystal' => $this->crystalBalance,
                'deuterium' => $this->deuteriumBalance,
            ],
            'levels' => [
                Building::MetalMine->value => $this->metalMineLevel,
                Building::CrystalMine->value => $this->crystalMineLevel,
                Building::DeuteriumSynthesizer->value => $this->deuteriumSynthesizerLevel,
                Building::SolarPlant->value => $this->solarPlantLevel,
                Building::MetalStorage->value => $this->metalStorageLevel,
                Building::CrystalStorage->value => $this->crystalStorageLevel,
                Building::DeuteriumStorage->value => $this->deuteriumStorageLevel,
                Building::RoboticsFactory->value => $this->roboticsFactoryLevel,
                Building::Shipyard->value => $this->shipyardLevel,
                Building::Laboratory->value => $this->laboratoryLevel,
            ],
            'temperature_max' => $this->temperatureMax,
            'fields_total' => $this->fieldsTotal,
            'fields_used' => $this->fieldsUsed,
            'last_settled_at' => self::bigintToNativeInt($this->lastSettledAt),
            'pending' => array_map(static fn (ConstructionEntry $entry): array => $entry->toDomainEntry()->toArray(), $entries),
        ]);
    }

    private static function bigintToNativeInt(string $value): int
    {
        if (preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new EconomyDataException('Persisted epoch seconds are not a canonical signed integer.');
        }

        $integer = BigInteger::of($value);
        if ($integer->compareTo(PHP_INT_MIN) < 0 || $integer->compareTo(PHP_INT_MAX) > 0) {
            throw new EconomyDataException('Persisted epoch seconds exceed the supported native integer range.');
        }

        return $integer->toInt();
    }
}
