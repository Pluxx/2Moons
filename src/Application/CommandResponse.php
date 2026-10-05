<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Economy\EconomyRejection;

final readonly class CommandResponse
{
    public function __construct(
        public bool $accepted,
        public bool $replayed = false,
        public ?string $existingStatus = null,
        public ?EconomyRejection $rejection = null,
    ) {
    }
}
