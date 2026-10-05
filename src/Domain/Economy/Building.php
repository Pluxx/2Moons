<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigRational;

enum Building: string
{
    case MetalMine = 'metal_mine';
    case CrystalMine = 'crystal_mine';
    case DeuteriumSynthesizer = 'deuterium_synthesizer';
    case SolarPlant = 'solar_plant';
    case MetalStorage = 'metal_storage';
    case CrystalStorage = 'crystal_storage';
    case DeuteriumStorage = 'deuterium_storage';
    case RoboticsFactory = 'robotics_factory';
    case Shipyard = 'shipyard';
    case Laboratory = 'laboratory';

    public function legacyId(): int
    {
        return match ($this) {
            self::MetalMine => 1,
            self::CrystalMine => 2,
            self::DeuteriumSynthesizer => 3,
            self::SolarPlant => 4,
            self::MetalStorage => 22,
            self::CrystalStorage => 23,
            self::DeuteriumStorage => 24,
            self::RoboticsFactory => 14,
            self::Shipyard => 21,
            self::Laboratory => 31,
        };
    }

    public static function fromLegacyId(int $id): ?self
    {
        foreach (self::cases() as $building) {
            if ($building->legacyId() === $id) {
                return $building;
            }
        }

        return null;
    }

    public function maximumLevel(): int
    {
        return 255;
    }

    public function isMine(): bool
    {
        return match ($this) {
            self::MetalMine, self::CrystalMine, self::DeuteriumSynthesizer => true,
            default => false,
        };
    }

    public function costFactor(): BigRational
    {
        return match ($this) {
            self::MetalMine, self::CrystalMine, self::DeuteriumSynthesizer, self::SolarPlant => BigRational::ofFraction(3, 2),
            self::MetalStorage, self::CrystalStorage, self::DeuteriumStorage,
            self::RoboticsFactory, self::Shipyard, self::Laboratory => BigRational::of(2),
        };
    }

    public function baseCost(): ResourceAmounts
    {
        return match ($this) {
            self::MetalMine => ResourceAmounts::fromStrings('60', '15', '0'),
            self::CrystalMine => ResourceAmounts::fromStrings('48', '24', '0'),
            self::DeuteriumSynthesizer => ResourceAmounts::fromStrings('225', '75', '0'),
            self::SolarPlant => ResourceAmounts::fromStrings('75', '30', '0'),
            self::MetalStorage => ResourceAmounts::fromStrings('2000', '0', '0'),
            self::CrystalStorage => ResourceAmounts::fromStrings('2000', '1000', '0'),
            self::DeuteriumStorage => ResourceAmounts::fromStrings('2000', '2000', '0'),
            self::RoboticsFactory => ResourceAmounts::fromStrings('400', '120', '200'),
            self::Shipyard => ResourceAmounts::fromStrings('400', '200', '100'),
            self::Laboratory => ResourceAmounts::fromStrings('200', '400', '200'),
        };
    }

    public function storageResource(): ?Resource
    {
        return match ($this) {
            self::MetalStorage => Resource::Metal,
            self::CrystalStorage => Resource::Crystal,
            self::DeuteriumStorage => Resource::Deuterium,
            default => null,
        };
    }
}
