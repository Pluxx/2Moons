<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ClockFoundationTest extends TestCase
{
    public function testMockClockAdvancesDeterministically(): void
    {
        $clock = new MockClock('2025-01-01 00:00:00 UTC');
        $start = $clock->now();

        $clock->sleep(7);

        self::assertSame($start->modify('+7 seconds')->format(DATE_ATOM), $clock->now()->format(DATE_ATOM));
    }
}
