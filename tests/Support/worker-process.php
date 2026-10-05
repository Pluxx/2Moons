<?php

declare(strict_types=1);

use App\Application\EconomyApplicationService;
use App\Kernel;
use App\Repository\ConstructionEntryRepository;
use App\Tests\Support\BarrierClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;

if (getenv('APP_ENV') !== 'test') {
    fwrite(STDERR, "This helper is restricted to APP_ENV=test.\n");
    exit(2);
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__, 2).'/tests/bootstrap.php';

$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $clock = new BarrierClock();
    Clock::set($clock);
    $container = $kernel->getContainer()->get('test.service_container');
    $entityManager = $container->get(EntityManagerInterface::class);
    $connection = $entityManager->getConnection();
    if ($connection->fetchOne('SELECT DATABASE()') !== 'db_test') {
        throw new RuntimeException('Concurrent worker helper refuses to operate outside db_test.');
    }
    if (getenv('ECONOMY_TEST_LOCK_TIMEOUT') === '1') {
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    $now = (string) $clock->now()->getTimestamp();
    $candidates = $container->get(ConstructionEntryRepository::class)->findDuePlanetIds($now, 10);
    $selectedMarker = getenv('ECONOMY_TEST_SELECTED_FILE');
    if (is_string($selectedMarker) && $selectedMarker !== ''
        && file_put_contents($selectedMarker, json_encode($candidates, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not write worker candidate selection marker.');
    }

    $attemptMarker = getenv('ECONOMY_TEST_ATTEMPT_FILE');
    if (is_string($attemptMarker) && $attemptMarker !== ''
        && file_put_contents($attemptMarker, (string) getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Could not write worker settlement attempt marker.');
    }

    $settled = 0;
    $lockWaitTimeout = false;
    try {
        foreach ($candidates as $planetId) {
            if ($container->get(EconomyApplicationService::class)->settlePlanetById($planetId)) {
                ++$settled;
            }
        }
    } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
        $lockWaitTimeout = true;
    }

    echo json_encode([
        'selected' => $candidates,
        'settled' => $settled,
        'lock_wait_timeout' => $lockWaitTimeout,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
} finally {
    $kernel->shutdown();
}
