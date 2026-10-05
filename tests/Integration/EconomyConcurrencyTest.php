<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\EconomyApplicationService;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ProcessHandle;
use Symfony\Component\Clock\ClockInterface;

final class EconomyConcurrencyTest extends DatabaseTestCase
{
    public function testEnqueueEnqueueContentionWaitsOnTheOwnedPlanetLockThenRetriesAgainstFreshState(): void
    {
        $user = $this->registerUser('enqueue-race@example.test');
        $sync = $this->newSyncDirectory();
        $first = null;
        $second = null;
        try {
            $first = $this->startProcess($this->enqueueCommand($user, 1, 1, str_repeat('a', 32)), [
                'ECONOMY_TEST_BARRIER_DIR' => $sync,
                'ECONOMY_TEST_BARRIER_CALL' => '1',
                'ECONOMY_TEST_CLOCK_OBSERVED_FILE' => $sync.'/first-clock-observed',
                'ECONOMY_TEST_CLOCK_OBSERVED_CALL' => '1',
            ]);
            $this->waitForMarker($sync.'/first-clock-observed', $first);
            $this->waitForMarker($sync.'/clock-1.entered', $first);

            $second = $this->startProcess($this->enqueueCommand($user, 1, 1, str_repeat('b', 32)), [
                'ECONOMY_TEST_LOCK_TIMEOUT' => '1',
                'ECONOMY_TEST_ATTEMPT_FILE' => $sync.'/second-attempted',
                'ECONOMY_TEST_CLOCK_OBSERVED_FILE' => $sync.'/second-clock-observed',
                'ECONOMY_TEST_CLOCK_OBSERVED_CALL' => '1',
            ]);
            $this->waitForMarker($sync.'/second-attempted', $second);
            $secondResponse = $this->decodeJson($this->finishProcess($second));
            self::assertSame(['lock_wait_timeout' => true], $secondResponse);
            self::assertFileDoesNotExist($sync.'/second-clock-observed');

            file_put_contents($sync.'/release', 'release', LOCK_EX);
            $firstResponse = $this->decodeJson($this->finishProcess($first));
            self::assertTrue($firstResponse['accepted']);
            self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry'));
            self::assertSame('active', $this->connection->fetchOne('SELECT status FROM construction_entry'));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT fields_used FROM planet'));
            $afterFirstCommit = $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet');

            $retry = $this->runProcess($this->enqueueCommand($user, 1, 1, str_repeat('b', 32)));
            self::assertFalse($retry['accepted']);
            self::assertSame('stale_target', $retry['rejection']);
            self::assertSame($afterFirstCommit, $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry'));
        } finally {
            $this->releaseAndFinish($sync, $first);
            $this->releaseAndFinish($sync, $second);
            $this->removeSyncDirectory($sync);
        }
    }

    public function testWorkerWorkerBothSelectSameCandidateThenSerializeAndFreshRetryIsHarmless(): void
    {
        $user = $this->registerUser('worker-race@example.test');
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        self::assertTrue($economy->enqueueForOwner($user, 1, 1, str_repeat('c', 32))->accepted);
        $this->makeCurrentHeadDue();
        $planetId = $this->planetIdFor($user);
        $ownerId = (int) $user->getId();
        $ownerId = (int) $user->getId();
        $sync = $this->newSyncDirectory();
        $first = null;
        $second = null;
        try {
            $first = $this->startProcess($this->workerHelperCommand(), [
                'ECONOMY_TEST_BARRIER_DIR' => $sync,
                'ECONOMY_TEST_BARRIER_CALL' => '2',
                'ECONOMY_TEST_SELECTED_FILE' => $sync.'/first-selected',
                'ECONOMY_TEST_ATTEMPT_FILE' => $sync.'/first-attempted',
            ]);
            $this->waitForMarker($sync.'/first-selected', $first);
            self::assertSame([$ownerId], json_decode((string) file_get_contents($sync.'/first-selected'), true, flags: JSON_THROW_ON_ERROR));
            $this->waitForMarker($sync.'/clock-2.entered', $first);

            $second = $this->startProcess($this->workerHelperCommand(), [
                'ECONOMY_TEST_LOCK_TIMEOUT' => '1',
                'ECONOMY_TEST_SELECTED_FILE' => $sync.'/second-selected',
                'ECONOMY_TEST_ATTEMPT_FILE' => $sync.'/second-attempted',
                'ECONOMY_TEST_CLOCK_OBSERVED_FILE' => $sync.'/second-clock-observed',
                'ECONOMY_TEST_CLOCK_OBSERVED_CALL' => '2',
            ]);
            $this->waitForMarker($sync.'/second-selected', $second);
            self::assertSame([$ownerId], json_decode((string) file_get_contents($sync.'/second-selected'), true, flags: JSON_THROW_ON_ERROR));
            $this->waitForMarker($sync.'/second-attempted', $second);
            $secondResponse = $this->decodeJson($this->finishProcess($second));
            self::assertTrue($secondResponse['lock_wait_timeout']);
            self::assertFileDoesNotExist($sync.'/second-clock-observed');

            file_put_contents($sync.'/release', 'release', LOCK_EX);
            $firstResponse = $this->decodeJson($this->finishProcess($first));
            self::assertSame([$ownerId], $firstResponse['selected']);
            self::assertSame(1, $firstResponse['settled']);

            $retry = $this->runProcess($this->workerHelperCommand());
            self::assertSame([], $retry['selected']);
            self::assertSame(0, $retry['settled']);
            self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM construction_entry'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT metal_mine_level FROM planet'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT fields_used FROM planet'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE status = \'completed\''));
        } finally {
            $this->releaseAndFinish($sync, $first);
            $this->releaseAndFinish($sync, $second);
            $this->removeSyncDirectory($sync);
        }
    }

    public function testWorkerRequestContentionWaitsForWorkerThenEnqueuesFromFreshPostCompletionState(): void
    {
        $user = $this->registerUser('worker-request-race@example.test');
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        self::assertTrue($economy->enqueueForOwner($user, 1, 1, str_repeat('d', 32))->accepted);
        self::assertTrue($economy->enqueueForOwner($user, 4, 1, str_repeat('e', 32))->accepted);
        $this->makeCurrentHeadDue();
        $planetId = $this->planetIdFor($user);
        $sync = $this->newSyncDirectory();
        $worker = null;
        $request = null;
        try {
            $worker = $this->startProcess($this->workerHelperCommand(), [
                'ECONOMY_TEST_BARRIER_DIR' => $sync,
                'ECONOMY_TEST_BARRIER_CALL' => '2',
                'ECONOMY_TEST_SELECTED_FILE' => $sync.'/worker-selected',
                'ECONOMY_TEST_ATTEMPT_FILE' => $sync.'/worker-attempted',
            ]);
            $this->waitForMarker($sync.'/worker-selected', $worker);
            self::assertSame([$ownerId], json_decode((string) file_get_contents($sync.'/worker-selected'), true, flags: JSON_THROW_ON_ERROR));
            $this->waitForMarker($sync.'/clock-2.entered', $worker);

            $request = $this->startProcess($this->enqueueCommand($user, 2, 1, str_repeat('f', 32)), [
                'ECONOMY_TEST_LOCK_TIMEOUT' => '1',
                'ECONOMY_TEST_ATTEMPT_FILE' => $sync.'/request-attempted',
                'ECONOMY_TEST_CLOCK_OBSERVED_FILE' => $sync.'/request-clock-observed',
                'ECONOMY_TEST_CLOCK_OBSERVED_CALL' => '1',
            ]);
            $this->waitForMarker($sync.'/request-attempted', $request);
            $blockedRequest = $this->decodeJson($this->finishProcess($request));
            self::assertTrue($blockedRequest['lock_wait_timeout']);
            self::assertFileDoesNotExist($sync.'/request-clock-observed');

            file_put_contents($sync.'/release', 'release', LOCK_EX);
            $workerResponse = $this->decodeJson($this->finishProcess($worker));
            self::assertSame([$ownerId], $workerResponse['selected']);
            self::assertSame(1, $workerResponse['settled']);

            $retry = $this->runProcess($this->enqueueCommand($user, 2, 1, str_repeat('f', 32)));
            self::assertTrue($retry['accepted']);
            self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [str_repeat('d', 32)]));
            self::assertSame('active', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [str_repeat('e', 32)]));
            self::assertSame('waiting', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [str_repeat('f', 32)]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE status = \'active\''));
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE status IN (\'active\', \'waiting\')'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT metal_mine_level FROM planet'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT fields_used FROM planet'));
        } finally {
            $this->releaseAndFinish($sync, $worker);
            $this->releaseAndFinish($sync, $request);
            $this->removeSyncDirectory($sync);
        }
    }

    public function testActualBoundedWorkerCommandCompletesDueConstruction(): void
    {
        $user = $this->registerUser('worker-cli@example.test');
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        self::assertTrue($economy->enqueueForOwner($user, 1, 1, str_repeat('1', 32))->accepted);
        $this->makeCurrentHeadDue();

        $output = $this->runProcess([
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/console',
            'app:economy:process',
            '--limit=10',
        ]);

        self::assertStringContainsString('Settled 1 of 1 due account candidate(s).', $output['output']);
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM construction_entry'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT metal_mine_level FROM planet'));
    }

    private function makeCurrentHeadDue(): void
    {
        $now = self::getContainer()->get(ClockInterface::class)->now()->getTimestamp();
        $start = $now - 200;
        $completion = $now - 1;
        $this->connection->executeStatement('UPDATE planet SET last_settled_at = ?', [(string) $start]);
        $this->connection->executeStatement('UPDATE construction_entry SET enqueued_at = ?, started_at = ?, completes_at = ? WHERE status = \'active\'', [(string) $start, (string) $start, (string) $completion]);
        $this->connection->executeStatement('UPDATE construction_entry SET enqueued_at = ? WHERE status = \'waiting\'', [(string) $start]);
    }

    private function planetIdFor(User $user): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$user->getId()]);
    }

    private function enqueueCommand(User $user, int $buildingId, int $target, string $token): array
    {
        return [
            PHP_BINARY,
            dirname(__DIR__, 2).'/tests/Support/enqueue-process.php',
            (string) $user->getId(),
            (string) $buildingId,
            (string) $target,
            $token,
        ];
    }

    private function workerHelperCommand(): array
    {
        return [PHP_BINARY, dirname(__DIR__, 2).'/tests/Support/worker-process.php'];
    }

    private function newSyncDirectory(): string
    {
        $base = dirname(__DIR__, 2).'/var/test-sync';
        if (!is_dir($base) && !mkdir($base, 0770, true) && !is_dir($base)) {
            self::fail('Could not create ignored test synchronization root.');
        }
        $directory = $base.'/lock-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0770)) {
            self::fail('Could not create unique test synchronization directory.');
        }

        return $directory;
    }

    private function startProcess(array $command, array $environmentOverrides = []): ProcessHandle
    {
        $environment = array_merge($_ENV, ['APP_ENV' => 'test'], $environmentOverrides);
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new ProcessHandle($process, $pipes[1], $pipes[2]);
    }

    private function runProcess(array $command, array $environmentOverrides = []): array
    {
        $child = $this->startProcess($command, $environmentOverrides);
        $output = $this->finishProcess($child);

        if (str_starts_with(ltrim($output), '{')) {
            return ['output' => $output, ...$this->decodeJson($output)];
        }

        return ['output' => $output];
    }

    private function waitForMarker(string $path, ProcessHandle $child): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        while (!is_file($path)) {
            $this->drainProcessOutput($child);
            $status = proc_get_status($child->process);
            if (!$status['running']) {
                self::fail(sprintf('Process exited before synchronization marker %s (exit=%d, stdout=%s, stderr=%s).', $path, $status['exitcode'], $child->stdoutText, $child->stderr));
            }
            if (hrtime(true) >= $deadline) {
                self::fail(sprintf('Timed out waiting for synchronization marker %s.', $path));
            }
            usleep(10_000);
        }
    }

    private function finishProcess(ProcessHandle $child): string
    {
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            $this->drainProcessOutput($child);
            $status = proc_get_status($child->process);
            if (!$status['running']) {
                $child->exitCode = $status['exitcode'];
                break;
            }
            if (hrtime(true) >= $deadline) {
                proc_terminate($child->process, 9);
                self::fail('Timed out waiting for child process to finish.');
            }
            usleep(10_000);
        } while (true);

        $this->drainProcessOutput($child);
        fclose($child->stdout);
        fclose($child->stderrStream);
        proc_close($child->process);
        self::assertSame(0, $child->exitCode, $child->stderr);

        return trim($child->stdoutText);
    }

    private function drainProcessOutput(ProcessHandle $child): void
    {
        $stdout = stream_get_contents($child->stdout);
        $stderr = stream_get_contents($child->stderrStream);
        if (is_string($stdout)) {
            $child->stdoutText .= $stdout;
        }
        if (is_string($stderr)) {
            $child->stderr .= $stderr;
        }
    }

    private function decodeJson(string $output): array
    {
        $decoded = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function releaseAndFinish(string $directory, ?ProcessHandle $child): void
    {
        if ($child === null || !is_resource($child->process)) {
            return;
        }
        if (is_dir($directory)) {
            @file_put_contents($directory.'/release', 'release', LOCK_EX);
        }
        $status = proc_get_status($child->process);
        if ($status['running']) {
            try {
                $this->finishProcess($child);
            } catch (\Throwable) {
                proc_terminate($child->process, 9);
            }
        }
    }

    private function removeSyncDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \DirectoryIterator($directory) as $item) {
            if (!$item->isDot() && $item->isFile()) {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
