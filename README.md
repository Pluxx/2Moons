# 2Moons4ever

A leftover token rebuild project, with ChatGPT Sol and Luna 6 ;)

This repository contains a PHP 8.5 / Symfony 7.4 LTS rebuild. The implemented
playable-economy slice includes accounts, one owned 40°C homeworld per account,
exact-rational resource accounting, seven building types, and a persisted
five-entry construction queue. The local economy milestone passed automated
acceptance and final browser/parent review. This is not a production-ready game.
The original application is preserved under
[`legacy/`](legacy/) outside the new public document root.

Use [DDEV](docs/DDEV.md) for local development, [`docs/FOUNDATION.md`](docs/FOUNDATION.md)
for safe setup and isolated database testing, and
[`docs/PLAYABLE_ECONOMY.md`](docs/PLAYABLE_ECONOMY.md) for implemented behavior
and limitations. For the preservation boundary and original revision, see
[`docs/LEGACY.md`](docs/LEGACY.md).

## Quick start

```sh
git clone <repository-url> 2moons
cd 2moons
ddev start
ddev composer install --no-interaction
ddev exec php bin/console doctrine:migrations:status
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev launch  # https://2moons.ddev.site
```

The migration is additive and creates the local account, planet, queue, and
Doctrine migration-metadata tables; it does not import legacy data or reset a
database. This quick start is for local DDEV only; inspect the selected local
database before migrating, and never run it against production. On the already
initialized development database, the migration is current and will not be
reapplied. Open the stable URL to register or log in. For isolated `db_test` setup,
read [FOUNDATION.md](docs/FOUNDATION.md); never point tests at the development or
production database. A bounded queue worker can be run with
`ddev exec php bin/console app:economy:process --limit=25`; `--limit` accepts
1–100 and defaults to 25. The planet overview also settles that planet when
visited; no always-on broker is required for this milestone.

Original project credits and historical information remain in
[`legacy/README.md`](legacy/README.md). The original project license is retained
at the repository root in [`LICENSE`](LICENSE), with an unchanged copy in
`legacy/LICENSE`. Preserve those notices when reusing project material. Artwork,
bundled libraries, and individual library notices still require a separate
rights review; this relocation does not grant rights beyond their applicable
licenses.
