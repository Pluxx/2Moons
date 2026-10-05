<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class ProcessHandle
{
    public string $stdoutText = '';
    public string $stderr = '';
    public ?int $exitCode = null;

    /** @param resource $process @param resource $stdout @param resource $stderrStream */
    public function __construct(
        public mixed $process,
        public mixed $stdout,
        public mixed $stderrStream,
    ) {
    }
}
