<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum Mission: string
{
    case Transport = 'transport';
    case Colonize = 'colonize';
}
