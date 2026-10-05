<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException
{
}
