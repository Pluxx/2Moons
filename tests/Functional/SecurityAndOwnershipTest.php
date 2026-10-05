<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\RegistrationService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityAndOwnershipTest extends WebTestCase
{
    private Connection $connection;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
        $this->clearGameRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            self::assertSame('db_test', $this->connection->fetchOne('SELECT DATABASE()'));
            $this->clearGameRows();
        }
        parent::tearDown();
    }

    public function testRegistrationFormRequiresCsrfMatchingPasswordsAndValidEmail(): void
    {
        $client = $this->client;
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['registration[email]'] = 'new@example.test';
        $form['registration[plainPassword][first]'] = 'a secure long password';
        $form['registration[plainPassword][second]'] = 'a secure long password';
        $form['registration[_token]'] = 'invalid';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user'));

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration[email]'] = ' NEW@EXAMPLE.TEST ';
        $form['registration[plainPassword][first]'] = 'a secure long password';
        $form['registration[plainPassword][second]'] = 'a secure long password';
        $client->submit($form);
        self::assertResponseRedirects('/login');
        self::assertSame('new@example.test', $this->connection->fetchOne('SELECT email FROM game_user'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet'));
    }

    public function testLoginRequiresCsrfAndValidCredentialsAndLogoutIsPostOnlyCsrfProtected(): void
    {
        $user = new User();
        $user->setEmail('login@example.test');
        static::getContainer()->get(RegistrationService::class)->register($user, 'a secure long password');
        $client = $this->client;

        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['_username'] = 'login@example.test';
        $form['_password'] = 'a secure long password';
        $form['_csrf_token'] = 'invalid';
        $client->submit($form);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->filter('form')->form();
        $form['_username'] = 'login@example.test';
        $form['_password'] = 'wrong password';
        $client->submit($form);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->filter('form')->form();
        $form['_username'] = 'login@example.test';
        $form['_password'] = 'a secure long password';
        $client->submit($form);
        self::assertResponseRedirects();

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);
        self::assertNotNull($client->getRequest()->getSession()->get('_security_main'));

        $crawler = $client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('form.logout-form input[name="_csrf_token"]')->attr('value');
        self::assertIsString($csrf);
        $client->request('POST', '/logout', ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/logout', ['_csrf_token' => $csrf]);
        self::assertResponseRedirects();
        $client->request('GET', '/planet');
        self::assertResponseRedirects('/login');
    }

    public function testUnauthenticatedMutationIsBlockedAndOwnerCannotBeSelectedInPayload(): void
    {
        $ownerA = new User();
        $ownerA->setEmail('owner-a@example.test');
        static::getContainer()->get(RegistrationService::class)->register($ownerA, 'a secure long password');
        $ownerB = new User();
        $ownerB->setEmail('owner-b@example.test');
        static::getContainer()->get(RegistrationService::class)->register($ownerB, 'a secure long password');
        $client = $this->client;

        $client->request('POST', '/planet/build/1', ['expected_target' => '1', 'command_token' => str_repeat('a', 32), '_token' => 'invalid']);
        self::assertResponseRedirects('/login');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry'));

        $client->loginUser($ownerA);
        $crawler = $client->request('GET', '/planet');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/planet/build/1"]')->form();
        $csrf = $form['_token']->getValue();
        $client->request('POST', '/planet/build/1', [
            'expected_target' => '1',
            'command_token' => str_repeat('b', 32),
            '_token' => $csrf,
            'owner_id' => (string) $ownerB->getId(),
            'planet_id' => '999999',
            'metal_cost' => '0',
        ]);
        self::assertResponseRedirects('/planet');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry'));
        $ownerAPlanetId = $this->connection->fetchOne('SELECT id FROM planet WHERE owner_id = ?', [$ownerA->getId()]);
        self::assertSame((string) $ownerAPlanetId, (string) $this->connection->fetchOne('SELECT planet_id FROM construction_entry'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT fields_used FROM planet WHERE owner_id = ?', [$ownerA->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT fields_used FROM planet WHERE owner_id = ?', [$ownerB->getId()]));
    }

    private function clearGameRows(): void
    {
        $this->connection->executeStatement('DELETE FROM construction_entry');
        $this->connection->executeStatement('DELETE FROM planet');
        $this->connection->executeStatement('DELETE FROM game_user');
    }
}
