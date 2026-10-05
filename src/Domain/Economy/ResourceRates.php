<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Brick\Math\BigRational;

final readonly class ResourceRates
{
    public function __construct(
        private BigRational $metal,
        private BigRational $crystal,
        private BigRational $deuterium,
    ) {
    }

    public static function zero(): self
    {
        return new self(BigRational::zero(), BigRational::zero(), BigRational::zero());
    }

    public static function fromStrings(string $metal, string $crystal, string $deuterium): self
    {
        return new self(BigRational::of($metal), BigRational::of($crystal), BigRational::of($deuterium));
    }

    public function get(Resource $resource): BigRational
    {
        return match ($resource) {
            Resource::Metal => $this->metal,
            Resource::Crystal => $this->crystal,
            Resource::Deuterium => $this->deuterium,
        };
    }

    public function plus(self $other): self
    {
        return new self(
            $this->metal->plus($other->metal),
            $this->crystal->plus($other->crystal),
            $this->deuterium->plus($other->deuterium),
        );
    }

    public function multipliedBy(BigRational $factor): self
    {
        return new self(
            $this->metal->multipliedBy($factor),
            $this->crystal->multipliedBy($factor),
            $this->deuterium->multipliedBy($factor),
        );
    }

    /** @return array<string, string> */
    public function toCanonicalArray(): array
    {
        return [
            Resource::Metal->value => RationalCodec::canonical($this->metal),
            Resource::Crystal->value => RationalCodec::canonical($this->crystal),
            Resource::Deuterium->value => RationalCodec::canonical($this->deuterium),
        ];
    }
}
