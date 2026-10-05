# Foundation and local verification

The rebuild uses PHP `^8.5` (locally verified at 8.5.9), Symfony `7.4.*`
(locally installed 7.4.20), Doctrine ORM/DBAL, MariaDB 11.8 (locally verified
11.8.9), and Brick Math exact rationals. Phase A and B are accepted; Phase C is
accepted for the local playable-economy milestone. Real Chromium interaction and
1440/390/320-pixel checks were parent-reviewed. This is not a production-ready
game, a full WCAG audit, or a fully initialized universe.

## Local environment and secrets

Use DDEV from the project root. The committed `.env` contains only local
development defaults; its `APP_SECRET` is deliberately unsafe. Deployments must
provide a random secret and their own database URL through the runtime
environment. Never trust arbitrary forwarded headers or configure unrestricted
trusted proxies. Session cookies use HttpOnly, SameSite=Lax, and `secure: auto`.

## Isolated database setup

The regular DDEV database `db` is the development database. Its schema was
verified empty before the additive first-game migration; it now contains
`game_user`, `planet`, `construction_entry`, and `doctrine_migration_versions`.
After a real DDEV restart, the snapshot matched; the exact disposable browser-QA
account was then removed with its six construction rows after exact ownership and
affected-row checks. Current counts are zero users, planets, and constructions;
the four tables remain initialized. No other rows were deleted and no broad
reset was performed. Do not reset or drop this database. This is an account/
planet/economy schema, not a seeded galaxy or fully configured universe. Tests use only
`db_test` through the `test_runner` account; PHPUnit and integration tests assert
the selected database before touching application rows. Provision the isolated
test database once:

```sh
ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'test_runner'@'%' IDENTIFIED BY 'test_runner'; GRANT ALL PRIVILEGES ON db_test.* TO 'test_runner'@'%'"
```

Inspect a target database before running migrations. The additive migration
creates `game_user`, `planet`, `construction_entry`, and the Doctrine migration
metadata table; it does not import legacy data or reset a database. On a fresh
local clone, start DDEV and install locked dependencies, then run the migration
against the local development database. Check migration status first. Do not
run destructive schema/database resets. To initialize the isolated test
database and run checks:

```sh
ddev exec env APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
ddev exec env APP_ENV=test php bin/console doctrine:migrations:status
ddev exec env APP_ENV=test php bin/console doctrine:schema:validate
ddev exec env APP_ENV=test composer test
ddev exec env APP_ENV=test php bin/console app:health
```

The `.env.test` URL starts from database name `db`; Doctrine's test suffix
selects `db_test`. Production/dev `.env` database configuration is not used by
the test migration or test suite. Do not repoint test configuration to `db` or
production. Tests clean only their own rows from the three game tables and never
drop databases or tables.

The initial local migration was applied only after a read-only preflight verified
`db` was empty. Migration and schema validation passed. On the prepared database,
migration status reports the current migration; do not rerun the empty-database
preflight or delete unrelated data. The worker was also verified to return zero
due candidates against the now-empty game tables.

## Additional checks

```sh
ddev composer validate --strict
ddev composer install --no-interaction
ddev composer check-platform-reqs
ddev composer audit
ddev exec php bin/console lint:container
ddev exec php bin/console lint:yaml config
ddev exec php bin/console lint:twig templates
ddev exec php bin/console app:economy:process --help
ddev exec php bin/phpunit -c phpunit.dist.xml
```

CI configuration provisions `db_test`, applies the migration with `APP_ENV=test`,
checks migration status, and runs the suite against MariaDB. The hosted CI YAML
was parsed but no hosted run is claimed. Locally, migration status and
`doctrine:schema:validate` passed; `/health` remains public JSON liveness and
does not expose game data.

## Security and milestone limits

Registration normalizes unique email identifiers, hashes passwords with the
framework auto hasher, and creates one temperature-40 homeworld transactionally.
Login, registration, logout, and construction forms are CSRF-protected; login is
rate-limited. Enqueue payloads carry only a building ID, expected target, random
command token, and CSRF token. Owner identity and all prices, rates, durations,
resources, and timestamps are server-owned.

The economy application locks the owning planet row before loading queue state,
samples the clock after the lock, persists balances and terminal queue outcomes
in the same transaction, and checks durable command-token history before
settlement. The worker uses the same settlement application path under the same
planet lock. The domain remains independent of Symfony and Doctrine.

Email verification, password reset, cancellation, reordering, demolition,
universe/galaxy settings and initialization, administration, fleets, and combat
are not part of this milestone. Focused acceptance passed **2 tests / 276
assertions**; the parent-verified full suite passed **51 tests / 4,538 assertions**
against isolated `db_test`. Phase C is accepted for the local playable economy.
Real Chromium interaction/responsive spot checks were reviewed at 1440, 390, and
320 pixels; this is not a full WCAG audit. The product is not production-ready;
review deployment secrets, mail/account recovery, abuse controls, monitoring,
backup/restore, and later game systems before release. The original code remains
under `legacy/` and outside the public root.
