<?php

declare(strict_types=1);

namespace App\Domain\Economy;

enum Resource: string
{
    case Metal = 'metal';
    case Crystal = 'crystal';
    case Deuterium = 'deuterium';
}
