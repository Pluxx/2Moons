<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigRational;

final readonly class ResourceAmounts
{
    public function __construct(
        private BigRational $metal,
        private BigRational $crystal,
        private BigRational $deuterium,
    ) {
        foreach ([$metal, $crystal, $deuterium] as $amount) {
            if ($amount->isNegative()) {
                throw new EconomyDataException('Resource balances and costs cannot be negative.');
            }
        }
    }

    public static function zero(): self
    {
        return self::fromStrings('0', '0', '0');
    }

    public static function fromStrings(string $metal, string $crystal, string $deuterium): self
    {
        return new self(BigRational::of($metal), BigRational::of($crystal), BigRational::of($deuterium));
    }

    public static function fromCanonical(array $values): self
    {
        self::assertResourceKeys($values);

        return new self(
            RationalCodec::parseCanonicalBalance($values[Resource::Metal->value]),
            RationalCodec::parseCanonicalBalance($values[Resource::Crystal->value]),
            RationalCodec::parseCanonicalBalance($values[Resource::Deuterium->value]),
        );
    }

    public function get(Resource $resource): BigRational
    {
        return match ($resource) {
            Resource::Metal => $this->metal,
            Resource::Crystal => $this->crystal,
            Resource::Deuterium => $this->deuterium,
        };
    }

    public function canAfford(self $cost): bool
    {
        foreach (Resource::cases() as $resource) {
            if ($this->get($resource)->compareTo($cost->get($resource)) < 0) {
                return false;
            }
        }

        return true;
    }

    public function minus(self $cost): self
    {
        if (!$this->canAfford($cost)) {
            throw new EconomyDataException('Cannot subtract unaffordable resource costs.');
        }

        return new self(
            $this->metal->minus($cost->metal),
            $this->crystal->minus($cost->crystal),
            $this->deuterium->minus($cost->deuterium),
        );
    }

    public function with(Resource $resource, BigRational $amount): self
    {
        return match ($resource) {
            Resource::Metal => new self($amount, $this->crystal, $this->deuterium),
            Resource::Crystal => new self($this->metal, $amount, $this->deuterium),
            Resource::Deuterium => new self($this->metal, $this->crystal, $amount),
        };
    }

    /** @return array<string, string> Canonical LONGTEXT-compatible balances. */
    public function toCanonicalArray(): array
    {
        return [
            Resource::Metal->value => RationalCodec::canonical($this->metal),
            Resource::Crystal->value => RationalCodec::canonical($this->crystal),
            Resource::Deuterium->value => RationalCodec::canonical($this->deuterium),
        ];
    }

    public function equals(self $other): bool
    {
        foreach (Resource::cases() as $resource) {
            if ($this->get($resource)->compareTo($other->get($resource)) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function assertResourceKeys(array $values): void
    {
        $expected = array_map(static fn (Resource $resource): string => $resource->value, Resource::cases());
        $actual = array_keys($values);
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new EconomyDataException('Resource amounts must contain exactly metal, crystal, and deuterium.');
        }
        foreach ($expected as $key) {
            if (!is_string($values[$key])) {
                throw new EconomyDataException('Canonical resource balances must be strings.');
            }
        }
    }
}
