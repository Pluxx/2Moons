<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\EconomyApplicationService;
use App\Application\IdempotencyConflict;
use App\Domain\Economy\RejectionCode;
use App\Entity\ConstructionEntry;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EconomyPersistenceTest extends DatabaseTestCase
{
    public function testConstructionPersistsCanonicalFractionsAndReplaysCompletedTokenWithoutAnotherCharge(): void
    {
        $clock = $this->setTestClock(1_700_000_000);
        $user = $this->registerUser();
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        $token = str_repeat('a', 32);

        $accepted = $economy->enqueueForOwner($user, 1, 1, $token);
        self::assertTrue($accepted->accepted);
        self::assertSame('410/1', $this->connection->fetchOne('SELECT metal_balance FROM planet'));
        self::assertSame('955/2', $this->connection->fetchOne('SELECT crystal_balance FROM planet'));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [$token]));

        $clock->sleep(162);
        $view = $this->overview($economy, $user);
        self::assertIsArray($view);
        self::assertSame(1_700_000_162, $view['server_now']);
        self::assertSame([], $view['queue']);
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [$token]));
        $beforeReplay = $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet');

        $replay = $economy->enqueueForOwner($user, 1, 1, $token);
        self::assertTrue($replay->accepted);
        self::assertTrue($replay->replayed);
        self::assertSame('completed', $replay->existingStatus);
        self::assertSame($beforeReplay, $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE command_token = ?', [$token]));

        try {
            $economy->enqueueForOwner($user, 2, 1, $token);
            self::fail('Reusing a token with a different payload was accepted.');
        } catch (IdempotencyConflict) {
            self::assertSame($beforeReplay, $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet'));
        }
    }

    public function testFailedActivationIsDurableAndItsTerminalTokenReplaysWithoutSettlement(): void
    {
        $clock = $this->setTestClock(1_700_010_000);
        $user = $this->registerUser();
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        $activeToken = str_repeat('b', 32);
        $waitingToken = str_repeat('c', 32);
        self::assertTrue($economy->enqueueForOwner($user, 1, 1, $activeToken)->accepted);
        self::assertTrue($economy->enqueueForOwner($user, 22, 1, $waitingToken)->accepted);

        $clock->sleep(162);
        $view = $this->overview($economy, $user);
        self::assertIsArray($view);
        self::assertSame([], $view['queue']);
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [$activeToken]));
        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [$waitingToken]));
        self::assertSame('insufficient_resources_at_activation', $this->connection->fetchOne('SELECT failure_reason FROM construction_entry WHERE command_token = ?', [$waitingToken]));
        $beforeReplay = $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet');

        $replay = $economy->enqueueForOwner($user, 22, 1, $waitingToken);
        self::assertTrue($replay->accepted);
        self::assertTrue($replay->replayed);
        self::assertSame('failed', $replay->existingStatus);
        self::assertSame($beforeReplay, $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, last_settled_at FROM planet'));
    }

    public function testQueueCapIsCommittedWithReservationsAndDomainRejectionsDoNotPartiallySettle(): void
    {
        $this->setTestClock(1_700_020_000);
        $user = $this->registerUser();
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        foreach ([
            [1, 'd'], [2, 'e'], [3, 'f'], [4, '1'], [22, '2'],
        ] as $index => [$buildingId, $hex]) {
            $token = str_repeat($hex, 32);
            $response = $economy->enqueueForOwner($user, $buildingId, 1, $token);
            self::assertTrue($response->accepted, 'Queue item '.($index + 1));
        }

        self::assertSame(5, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE status IN (\'active\', \'waiting\')'));
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT fields_used + (SELECT COUNT(*) FROM construction_entry WHERE planet_id = planet.id AND status IN (\'active\', \'waiting\')) FROM planet'));
        $before = $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, deuterium_balance, last_settled_at FROM planet');

        $rejected = $economy->enqueueForOwner($user, 23, 1, str_repeat('3', 32));
        self::assertFalse($rejected->accepted);
        self::assertSame(RejectionCode::QueueFull, $rejected->rejection->code);
        self::assertSame($before, $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, deuterium_balance, last_settled_at FROM planet'));
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry'));
    }

    public function testFailureDuringCompletionAndSuccessorActivationRollsBackPlanetAndBothQueueRows(): void
    {
        $clock = $this->setTestClock(1_700_030_000);
        $user = $this->registerUser();
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        $headToken = str_repeat('4', 32);
        $waitingToken = str_repeat('5', 32);
        self::assertTrue($economy->enqueueForOwner($user, 1, 1, $headToken)->accepted);
        self::assertTrue($economy->enqueueForOwner($user, 4, 1, $waitingToken)->accepted);
        $beforePlanet = $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, fields_used, last_settled_at FROM planet');
        $clock->sleep(162);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class {
            public function preUpdate(PreUpdateEventArgs $event): void
            {
                if ($event->getObject() instanceof ConstructionEntry) {
                    throw new \RuntimeException('Controlled queue-write failure during completion/activation.');
                }
            }
        };
        $eventManager = $entityManager->getEventManager();
        $eventManager->addEventListener(Events::preUpdate, $listener);
        try {
            $this->overview($economy, $user);
            self::fail('Expected controlled queue persistence failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Controlled queue-write failure during completion/activation.', $exception->getMessage());
        } finally {
            $eventManager->removeEventListener(Events::preUpdate, $listener);
        }

        self::assertSame($beforePlanet, $this->connection->fetchAssociative('SELECT metal_balance, crystal_balance, fields_used, last_settled_at FROM planet'));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [$headToken]));
        self::assertSame('waiting', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE command_token = ?', [$waitingToken]));
        self::assertNull($this->connection->fetchOne('SELECT started_at FROM construction_entry WHERE command_token = ?', [$waitingToken]));
    }

    public function testLateCatchupPersistsActualExecutionTimesThroughWorkerAndOverviewAndMatchesPartitionedSettlement(): void
    {
        $start = 1_700_100_000;
        $clock = $this->setTestClock($start);
        $bulkWorker = $this->registerUser('bulk-worker@example.test');
        $bulkOverview = $this->registerUser('bulk-overview@example.test');
        $partitioned = $this->registerUser('partitioned@example.test');
        $economy = self::getContainer()->get(EconomyApplicationService::class);
        $users = [$bulkWorker, $bulkOverview, $partitioned];
        $tokens = [];

        foreach ($users as $index => $user) {
            $mineToken = str_repeat(dechex(9 + $index), 32);
            $solarToken = str_repeat(dechex(12 + $index), 32);
            self::assertTrue($economy->enqueueForOwner($user, 1, 1, $mineToken)->accepted);
            self::assertTrue($economy->enqueueForOwner($user, 4, 1, $solarToken)->accepted);
            $tokens[] = [$mineToken, $solarToken];
        }

        $clock->sleep(162);
        self::assertIsArray($this->overview($economy, $partitioned));
        $clock->sleep(226);
        self::assertTrue($economy->settlePlanetById($this->planetIdFor($partitioned)));

        foreach ([$bulkWorker, $bulkOverview] as $index => $user) {
            if ($index === 0) {
                self::assertTrue($economy->settlePlanetById($this->planetIdFor($user)));
            } else {
                self::assertIsArray($this->overview($economy, $user));
            }
        }

        $expected = $this->persistedPlanetAndHistory($this->planetIdFor($bulkWorker));
        self::assertSame($expected, $this->persistedPlanetAndHistory($this->planetIdFor($bulkOverview)));
        self::assertSame($expected, $this->persistedPlanetAndHistory($this->planetIdFor($partitioned)));
        self::assertSame('26969/90', $expected['planet']['metal_balance']);
        self::assertSame('19511/45', $expected['planet']['crystal_balance']);
        self::assertSame('0/1', $expected['planet']['deuterium_balance']);
        self::assertSame('completed', $expected['history'][0]['status']);
        self::assertSame('completed', $expected['history'][1]['status']);
        self::assertSame((string) $start, (string) $expected['history'][0]['started_at']);
        self::assertSame((string) ($start + 162), (string) $expected['history'][0]['completes_at']);
        self::assertSame((string) ($start + 162), (string) $expected['history'][0]['resolved_at']);
        self::assertSame((string) ($start + 162), (string) $expected['history'][1]['started_at']);
        self::assertSame((string) ($start + 388), (string) $expected['history'][1]['completes_at']);
        self::assertSame((string) ($start + 388), (string) $expected['history'][1]['resolved_at']);
        self::assertSame(2, (int) $expected['planet']['fields_used']);
        self::assertSame(1, (int) $expected['planet']['metal_mine_level']);
        self::assertSame(1, (int) $expected['planet']['solar_plant_level']);
        self::assertSame((string) ($start + 388), (string) $expected['planet']['last_settled_at']);

        foreach ($users as $user) {
            self::assertTrue($economy->settlePlanetById($this->planetIdFor($user)));
        }
        foreach ($users as $index => $user) {
            [$mineToken, $solarToken] = $tokens[$index];
            self::assertTrue($economy->enqueueForOwner($user, 1, 1, $mineToken)->replayed);
            self::assertTrue($economy->enqueueForOwner($user, 4, 1, $solarToken)->replayed);
            self::assertSame($expected, $this->persistedPlanetAndHistory($this->planetIdFor($user)));
        }
    }

    private function overview(EconomyApplicationService $economy, User $user): ?array
    {
        $request = Request::create('/planet');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            return $economy->overviewFor($user);
        } finally {
            $stack->pop();
        }
    }

    private function planetIdFor(User $user): int
    {
        $id = $this->connection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$user->getId()]);
        self::assertNotFalse($id);

        return (int) $id;
    }

    /** @return array{planet: array<string, mixed>, history: list<array<string, mixed>>} */
    private function persistedPlanetAndHistory(int $planetId): array
    {
        $planet = $this->connection->fetchAssociative(
            'SELECT metal_balance, crystal_balance, deuterium_balance, metal_mine_level, crystal_mine_level, deuterium_synthesizer_level, solar_plant_level, metal_storage_level, crystal_storage_level, deuterium_storage_level, fields_used, last_settled_at FROM planet WHERE id = ?',
            [$planetId],
        );
        self::assertIsArray($planet);
        foreach (['metal_balance', 'crystal_balance', 'deuterium_balance'] as $key) {
            self::assertMatchesRegularExpression('/\A(?:0|-?[1-9][0-9]*)\/[1-9][0-9]*\z/D', (string) $planet[$key]);
        }
        $history = $this->connection->fetchAllAssociative(
            'SELECT building_id, target_level, status, started_at, completes_at, resolved_at FROM construction_entry WHERE planet_id = ? ORDER BY position ASC',
            [$planetId],
        );

        return ['planet' => $planet, 'history' => $history];
    }
}
