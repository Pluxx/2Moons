<?php

declare(strict_types=1);

namespace App\Application;

final readonly class GameCommandResponse
{
    public function __construct(
        public bool $accepted,
        public bool $replayed = false,
        public ?string $existingStatus = null,
        public ?string $rejectionCode = null,
        public array $context = [],
    ) {
    }
}
