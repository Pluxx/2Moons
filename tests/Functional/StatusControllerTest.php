<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StatusControllerTest extends WebTestCase
{
    public function testRootRedirectsAnonymousVisitorToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testHealthRouteAndLegacyPathsAreIsolated(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        $health = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $health['status']);

        foreach (['/legacy/index.php', '/legacy/includes/config.php'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
    }
}
