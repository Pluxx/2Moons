<?php

declare(strict_types=1);

namespace App\Domain\Economy;

enum OutcomeStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
