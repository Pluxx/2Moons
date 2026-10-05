<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class QuoteResult
{
    private function __construct(public ?ConstructionQuote $quote, public ?EconomyRejection $rejection)
    {
    }

    public static function quoted(ConstructionQuote $quote): self
    {
        return new self($quote, null);
    }

    public static function rejected(RejectionCode $code, array $context = []): self
    {
        return new self(null, new EconomyRejection($code, $context));
    }

    public function isQuoted(): bool
    {
        return $this->quote !== null;
    }
}
