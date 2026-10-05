<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/** Test-environment clock which can pause only at an explicitly selected call. */
final class BarrierClock implements ClockInterface
{
    private NativeClock $clock;
    private int $calls = 0;

    public function __construct()
    {
        $this->clock = new NativeClock();
    }

    public function now(): \DateTimeImmutable
    {
        ++$this->calls;
        $this->waitAtConfiguredBarrier();

        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        $this->clock->sleep($seconds);
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        $clone = clone $this;
        $clone->clock = $this->clock->withTimeZone($timezone);

        return $clone;
    }

    private function waitAtConfiguredBarrier(): void
    {
        $directory = getenv('ECONOMY_TEST_BARRIER_DIR');
        $barrierCall = getenv('ECONOMY_TEST_BARRIER_CALL');
        $observedFile = getenv('ECONOMY_TEST_CLOCK_OBSERVED_FILE');
        $observedCall = getenv('ECONOMY_TEST_CLOCK_OBSERVED_CALL');
        if (is_string($observedFile) && $observedFile !== '' && is_string($observedCall)
            && ctype_digit($observedCall) && (int) $observedCall === $this->calls
            && file_put_contents($observedFile, (string) getmypid(), LOCK_EX) === false) {
            throw new \RuntimeException('Could not write observed test clock call marker.');
        }
        if (!is_string($directory) || $directory === '' || !is_string($barrierCall)
            || !ctype_digit($barrierCall) || (int) $barrierCall !== $this->calls) {
            return;
        }

        if (!is_dir($directory)) {
            throw new \RuntimeException('Configured test synchronization directory is missing.');
        }

        $entered = $directory.'/clock-'.$this->calls.'.entered';
        if (file_put_contents($entered, (string) getmypid(), LOCK_EX) === false) {
            throw new \RuntimeException('Could not report test clock barrier entry.');
        }

        $release = $directory.'/release';
        $deadline = hrtime(true) + 15_000_000_000;
        while (!is_file($release)) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for test synchronization release.');
            }
            usleep(10_000);
        }
    }
}
