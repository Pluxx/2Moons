<?php

declare(strict_types=1);

namespace App\Domain\Economy;

enum RejectionCode: string
{
    case ClockRegression = 'clock_regression';
    case LevelLimit = 'level_limit';
    case QueueFull = 'queue_full';
    case FieldsFull = 'fields_full';
    case StaleTarget = 'stale_target';
    case Unaffordable = 'unaffordable';
    case TimestampRange = 'timestamp_range';
    case DuplicatePendingToken = 'duplicate_pending_token';
    case InvalidCommandToken = 'invalid_command_token';
}
