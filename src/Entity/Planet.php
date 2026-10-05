<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\ResourceAmounts;
use App\Repository\PlanetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Brick\Math\BigInteger;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanetRepository::class)]
#[ORM\Table(name: 'planet')]
#[ORM\UniqueConstraint(name: 'UNIQ_68136AA57E3C61F9', columns: ['owner_id'])]
class Planet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'planet', targetEntity: User::class)]
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

    public function __construct(User $owner, PlanetEconomyState $state)
    {
        $this->owner = $owner;
        $owner->setPlanet($this);
        $this->constructionEntries = new ArrayCollection();
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
        $this->temperatureMax = $state->temperatureMax;
        $this->fieldsTotal = $state->fieldsTotal;
        $this->fieldsUsed = $state->fieldsUsed;
        $this->lastSettledAt = (string) $state->lastSettledAt;
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
