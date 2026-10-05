<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

if (getenv('APP_ENV') !== 'dev') {
    fwrite(STDERR, "Refusing dev database preflight unless APP_ENV=dev.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'dev';
(new Dotenv())->bootEnv($root.'/.env');

$kernel = new Kernel('dev', false);
$kernel->boot();
try {
    $connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
    $database = $connection->fetchOne('SELECT DATABASE()');
    if ($database !== 'db') {
        fwrite(STDERR, "Refusing migration: the configured Doctrine database is not exactly db.\n");
        exit(3);
    }

    $tables = $connection->createSchemaManager()->listTableNames();
    sort($tables, SORT_STRING);
    printf("Configured Doctrine database: %s\n", $database);
    printf("Existing tables: %s\n", $tables === [] ? '(none)' : implode(', ', $tables));

    if (($argv[1] ?? null) === '--verify-initialized') {
        $expectedTables = ['construction_entry', 'doctrine_migration_versions', 'game_user', 'planet'];
        if ($tables !== $expectedTables) {
            fwrite(STDERR, "Initialized dev schema does not match the expected additive game tables.\n");
            exit(4);
        }
        $counts = [
            'users' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_user'),
            'planets' => (int) $connection->fetchOne('SELECT COUNT(*) FROM planet'),
            'constructions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM construction_entry'),
        ];
        printf("Initialized schema; existing game data counts: %s\n", json_encode($counts, JSON_THROW_ON_ERROR));
        fwrite(STDOUT, "Read-only initialized-schema verification passed.\n");
    } else {
        $allowed = $tables === [] || $tables === ['doctrine_migration_versions'];
        if (!$allowed) {
            fwrite(STDERR, "Refusing migration: database is not empty except for optional Doctrine migration metadata.\n");
            exit(4);
        }
        fwrite(STDOUT, "Read-only preflight passed; no schema or data was changed.\n");
    }
} finally {
    $kernel->shutdown();
}
