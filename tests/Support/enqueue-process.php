<?php

declare(strict_types=1);

use App\Application\EconomyApplicationService;
use App\Tests\Support\BarrierClock;
use App\Entity\User;
use App\Kernel;
use App\Repository\UserRepository;
use Symfony\Component\Clock\Clock;

if (getenv('APP_ENV') !== 'test') {
    fwrite(STDERR, "This helper is restricted to APP_ENV=test.\n");
    exit(2);
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__, 2).'/tests/bootstrap.php';

$args = array_slice($argv, 1);
if (count($args) !== 4) {
    fwrite(STDERR, "Expected owner id, building id, target level, command token.\n");
    exit(2);
}
[$ownerId, $buildingId, $targetLevel, $token] = $args;
if (preg_match('/\A[1-9][0-9]*\z/D', $ownerId) !== 1 || preg_match('/\A[0-9]+\z/D', $buildingId) !== 1
    || preg_match('/\A[0-9]+\z/D', $targetLevel) !== 1 || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
    fwrite(STDERR, "Invalid concurrent test arguments.\n");
    exit(2);
}

$kernel = new Kernel('test', false);
$kernel->boot();
try {
    Clock::set(new BarrierClock());
    $testContainer = $kernel->getContainer()->get('test.service_container');
    $entityManager = $testContainer->get(\Doctrine\ORM\EntityManagerInterface::class);
    $connection = $entityManager->getConnection();
    if ($connection->fetchOne('SELECT DATABASE()') !== 'db_test') {
        throw new RuntimeException('Concurrent helper refuses to operate outside db_test.');
    }
    if (getenv('ECONOMY_TEST_LOCK_TIMEOUT') === '1') {
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
    }
    $owner = $testContainer->get(UserRepository::class)->find((int) $ownerId);
    if (!$owner instanceof User) {
        throw new RuntimeException('Concurrent test owner was not found.');
    }
    $attemptMarker = getenv('ECONOMY_TEST_ATTEMPT_FILE');
    if (is_string($attemptMarker) && $attemptMarker !== ''
        && file_put_contents($attemptMarker, (string) getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Could not write enqueue attempt marker.');
    }
    $lockWaitTimeout = false;
    try {
        $result = $testContainer->get(EconomyApplicationService::class)->enqueueForOwner(
            $owner,
            (int) $buildingId,
            (int) $targetLevel,
            $token,
        );
    } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
        $lockWaitTimeout = true;
    }
    if ($lockWaitTimeout) {
        echo json_encode(['lock_wait_timeout' => true], JSON_THROW_ON_ERROR);
    } else {
        echo json_encode([
            'accepted' => $result->accepted,
            'replayed' => $result->replayed,
            'rejection' => $result->rejection?->code->value,
        ], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
} finally {
    $kernel->shutdown();
}
