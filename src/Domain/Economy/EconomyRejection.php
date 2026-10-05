<?php

declare(strict_types=1);

namespace App\Domain\Economy;

final readonly class EconomyRejection
{
    public function __construct(public RejectionCode $code, public array $context = [])
    {
    }
}
