<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlayableEconomyTest extends WebTestCase
{
    private Connection $connection;
    private string $email;
    private string $password = 'a secure long phase-c password';
    private MockClock $clock;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->email = 'phase-c-'.bin2hex(random_bytes(8)).'@example.test';
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
        $this->clock = new MockClock(new \DateTimeImmutable('@1700000000'));
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
            $this->connection->executeStatement(
                'DELETE construction_entry FROM construction_entry INNER JOIN planet ON planet.id = construction_entry.planet_id INNER JOIN game_user ON game_user.id = planet.owner_id WHERE game_user.email = ?',
                [$this->email],
            );
            $this->connection->executeStatement(
                'DELETE planet FROM planet INNER JOIN game_user ON game_user.id = planet.owner_id WHERE game_user.email = ?',
                [$this->email],
            );
            $this->connection->delete('game_user', ['email' => $this->email]);
        }
        parent::tearDown();
        Clock::set(new NativeClock());
    }

    public function testRegisteredPlayerCompletesEveryBuildingThroughRenderedFormsAndKeepsStateAcrossLogin(): void
    {
        $this->registerAndLogin(checkRepeatedPasswordError: true);

        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        self::assertSame('Homeworld', trim($crawler->filter('#planet-title')->text()));
        self::assertStringContainsString('40°C', $crawler->filter('.planet-facts')->text());
        self::assertStringContainsString('0 / 163', preg_replace('/\s+/', ' ', $crawler->filter('.planet-facts')->text()));
        self::assertSame('500', trim($crawler->filter('article.resource-metal .resource-stock')->text()));
        self::assertSame('500', trim($crawler->filter('article.resource-crystal .resource-stock')->text()));
        self::assertSame('0', trim($crawler->filter('article.resource-deuterium .resource-stock')->text()));
        self::assertStringContainsString('20', $crawler->filter('article.resource-metal .resource-details')->text());
        self::assertStringContainsString('10', $crawler->filter('article.resource-crystal .resource-details')->text());
        self::assertStringContainsString('No mine demand', $crawler->filter('.power-panel')->text());
        self::assertSame('500/1', $this->planetValue('metal_balance'));
        self::assertSame('500/1', $this->planetValue('crystal_balance'));
        self::assertSame('0/1', $this->planetValue('deuterium_balance'));
        self::assertSame('40', (string) $this->planetValue('temperature_max'));
        self::assertSame(0, (int) $this->planetValue('fields_used'));

        $crawler = $this->submitBuildFromRenderedForm(1);
        $crawler = $this->submitBuildFromRenderedForm(4);
        self::assertCount(2, $crawler->filter('ol.queue-list > li'));
        self::assertStringContainsString('Metal mine', $crawler->filter('ol.queue-list > li')->eq(0)->text());
        self::assertStringContainsString('Waiting', $crawler->filter('ol.queue-list > li')->eq(1)->text());
        self::assertStringContainsString('resources charged when construction starts', strtolower($crawler->filter('ol.queue-list > li')->eq(1)->text()));
        self::assertStringContainsString('estimate', strtolower($crawler->filter('ol.queue-list > li')->eq(1)->filter('.approx-note')->text()));

        // Exercise the real bounded worker command against the test clock and both due heads.
        $this->clock->sleep(388);
        $workerOutput = $this->runWorkerCommand();
        self::assertStringContainsString('Settled 1 of 1 due planet candidate(s).', $workerOutput);
        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('ol.queue-list > li'));
        self::assertSame(1, (int) $this->planetValue('metal_mine_level'));
        self::assertSame(1, (int) $this->planetValue('solar_plant_level'));
        self::assertSame(2, (int) $this->planetValue('fields_used'));
        self::assertStringContainsString('completed', strtolower($crawler->filter('.recent-panel')->text()));

        // Complete the crystal mine, then solar level 2 using ordinary DOM-posted actions.
        $crawler = $this->submitBuildFromRenderedForm(2);
        $crawler = $this->settleQueueViaOverview($crawler);
        self::assertSame(1, (int) $this->planetValue('crystal_mine_level'));
        $crawler = $this->submitBuildFromRenderedForm(4, 2);
        $crawler = $this->settleQueueViaOverview($crawler);
        self::assertSame(2, (int) $this->planetValue('solar_plant_level'));

        // Offline accrual is clock-controlled, not a resource/funds override.
        $crawler = $this->waitForAccrual(7 * 24 * 60 * 60);
        self::assertGreaterThanOrEqual(337.5, (float) trim($crawler->filter('article.resource-metal .resource-stock')->text()));
        $crawler = $this->submitBuildFromRenderedForm(3);
        $crawler = $this->settleQueueViaOverview($crawler);
        self::assertSame(1, (int) $this->planetValue('deuterium_synthesizer_level'));
        self::assertSame(5, (int) $this->planetValue('fields_used'));

        $crawler = $this->waitForAccrual(7 * 24 * 60 * 60);
        self::assertStringContainsString('48.8', $crawler->filter('article.resource-metal .resource-details')->text());
        self::assertStringContainsString('29.2', $crawler->filter('article.resource-crystal .resource-details')->text());
        self::assertStringContainsString('11.52', $crawler->filter('article.resource-deuterium .resource-details')->text());
        self::assertStringContainsString('87.27%', $crawler->filter('.power-panel')->text());

        foreach ([22 => 6, 23 => 7, 24 => 8] as $storageId => $expectedFields) {
            $crawler = $this->submitBuildFromRenderedForm($storageId);
            $crawler = $this->settleQueueViaOverview($crawler);
            self::assertSame($expectedFields, (int) $this->planetValue('fields_used'));
            if ($storageId !== 24) {
                $crawler = $this->waitForAccrual(7 * 24 * 60 * 60);
            }
        }

        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->planetValue('metal_storage_level'));
        self::assertSame(1, (int) $this->planetValue('crystal_storage_level'));
        self::assertSame(1, (int) $this->planetValue('deuterium_storage_level'));
        self::assertSame(8, (int) $this->planetValue('fields_used'));
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            self::assertStringContainsString('20000', $crawler->filter('article.resource-'.$resource.' .resource-details')->text());
        }

        $beforeLogout = $this->persistedPlanetSnapshot();
        $logoutForm = $crawler->filter('form.logout-form')->form();
        $this->client->submit($logoutForm);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->client->request('GET', '/planet');
        self::assertResponseRedirects('/login');

        $this->login();
        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        self::assertSame('Homeworld', trim($crawler->filter('#planet-title')->text()));
        self::assertSame($beforeLogout, $this->persistedPlanetSnapshot());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet WHERE owner_id = (SELECT id FROM game_user WHERE email = ?)', [$this->email]));
    }

    public function testUnfundedWaitingStorageIsAcceptedThenFailsAndUnrelatedSolarStarts(): void
    {
        $this->registerAndLogin();
        $crawler = $this->submitBuildFromRenderedForm(1);
        $crawler = $this->submitBuildFromRenderedForm(22);
        self::assertCount(2, $crawler->filter('ol.queue-list > li'));
        self::assertCount(1, $crawler->filter('form.enqueue-form[action="/planet/build/22"]'));

        foreach ([4, 2, 3] as $buildingId) {
            $crawler = $this->submitBuildFromRenderedForm($buildingId);
        }
        self::assertCount(5, $crawler->filter('ol.queue-list > li'));
        self::assertStringContainsString('Metal storage', $crawler->filter('ol.queue-list > li')->eq(1)->text());

        $this->clock->sleep(162);
        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('ol.queue-list > li'));
        self::assertStringContainsString('Solar plant', $crawler->filter('ol.queue-list > li')->eq(0)->text());
        self::assertStringContainsString('In progress', $crawler->filter('ol.queue-list > li')->eq(0)->text());
        self::assertStringContainsString('Crystal mine', $crawler->filter('ol.queue-list > li')->eq(1)->text());
        self::assertStringContainsString('Deuterium synthesizer', $crawler->filter('ol.queue-list > li')->eq(2)->text());
        self::assertStringContainsString('Insufficient resources when construction reached the front of the queue.', $crawler->filter('.recent-panel')->text());

        $storage = $this->connection->fetchAssociative('SELECT status, started_at, completes_at, resolved_at, failure_reason FROM construction_entry WHERE building_id = 22');
        self::assertSame('failed', $storage['status']);
        self::assertNull($storage['started_at']);
        self::assertNull($storage['completes_at']);
        self::assertSame('1700000162', (string) $storage['resolved_at']);
        self::assertSame('insufficient_resources_at_activation', $storage['failure_reason']);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM construction_entry WHERE building_id = 4'));
    }

    private function registerAndLogin(bool $checkRepeatedPasswordError = false): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        if ($checkRepeatedPasswordError) {
            $form = $crawler->filter('form')->form();
            $form['registration[email]'] = $this->email;
            $form['registration[plainPassword][first]'] = $this->password;
            $form['registration[plainPassword][second]'] = 'a different password';
            $crawler = $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('password confirmation does not match', strtolower($crawler->filter('.password-group .field-errors')->text()));
        }

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration[email]'] = $this->email;
        $form['registration[plainPassword][first]'] = $this->password;
        $form['registration[plainPassword][second]'] = $this->password;
        $this->client->submit($form);
        self::assertResponseRedirects('/login');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user WHERE email = ?', [$this->email]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet WHERE owner_id = (SELECT id FROM game_user WHERE email = ?)', [$this->email]));
        self::assertSame('1700000000', (string) $this->planetValue('last_settled_at'));
        self::assertSame(40, (int) $this->planetValue('temperature_max'));
        $this->login();
    }

    private function login(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['_username'] = $this->email;
        $form['_password'] = $this->password;
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    private function submitBuildFromRenderedForm(int $buildingId, int $expectedTarget = 1): Crawler
    {
        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        $selector = sprintf('form.enqueue-form[action="/planet/build/%d"]', $buildingId);
        self::assertSame(1, $crawler->filter($selector)->count(), 'Building '.$buildingId.' must be admitted by the rendered form.');
        $form = $crawler->filter($selector)->form();
        self::assertSame((string) $expectedTarget, (string) $form['expected_target']->getValue());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', (string) $form['command_token']->getValue());
        self::assertNotSame('', (string) $form['_token']->getValue());
        foreach ($crawler->filter($selector.' input')->extract(['name']) as $name) {
            self::assertDoesNotMatchRegularExpression('/price|balance|resource|owner|planet/i', (string) $name);
        }

        $this->client->submit($form);
        self::assertResponseRedirects('/planet');

        return $this->client->followRedirect();
    }

    private function settleQueueViaOverview(Crawler $crawler): Crawler
    {
        $iterations = 0;
        while ($crawler->filter('ol.queue-list > li')->count() > 0) {
            self::assertLessThan(6, ++$iterations, 'Construction queue did not make bounded progress.');
            $active = $crawler->filter('ol.queue-list > li.queue-active time.queue-countdown');
            self::assertSame(1, $active->count());
            $due = (int) $active->attr('data-due');
            $now = $this->clock->now()->getTimestamp();
            if ($due > $now) {
                $this->clock->sleep($due - $now);
            }
            $crawler = $this->client->request('GET', '/planet');
            self::assertResponseIsSuccessful();
        }

        return $crawler;
    }

    private function waitForAccrual(int $seconds): Crawler
    {
        $this->clock->sleep($seconds);
        $crawler = $this->client->request('GET', '/planet');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function runWorkerCommand(): string
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:economy:process'));
        self::assertSame(0, $tester->execute(['--limit' => '10']));

        return $tester->getDisplay();
    }

    private function planetValue(string $column): mixed
    {
        self::assertMatchesRegularExpression('/\A[a-z_]+\z/D', $column);

        return $this->connection->fetchOne(
            sprintf('SELECT %s FROM planet WHERE owner_id = (SELECT id FROM game_user WHERE email = ?)', $column),
            [$this->email],
        );
    }

    private function persistedPlanetSnapshot(): array
    {
        $snapshot = $this->connection->fetchAssociative(
            'SELECT metal_balance, crystal_balance, deuterium_balance, metal_mine_level, crystal_mine_level, deuterium_synthesizer_level, solar_plant_level, metal_storage_level, crystal_storage_level, deuterium_storage_level, temperature_max, fields_used, last_settled_at FROM planet WHERE owner_id = (SELECT id FROM game_user WHERE email = ?)',
            [$this->email],
        );
        self::assertIsArray($snapshot);

        return $snapshot;
    }
}
