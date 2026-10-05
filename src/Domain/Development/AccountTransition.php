<?php

declare(strict_types=1);

namespace App\Domain\Development;

final readonly class AccountTransition
{
    /** @param list<array<string,mixed>> $outcomes */
    private function __construct(public AccountState $state, public array $outcomes, public ?string $rejection, public array $context = [])
    {
    }

    public static function accepted(AccountState $state, array $outcomes = []): self { return new self($state, $outcomes, null); }
    public static function rejected(AccountState $state, string $code, array $context = []): self
    {
        return new self($state, [], $code, $context);
    }
    public function isAccepted(): bool { return $this->rejection === null; }
}
