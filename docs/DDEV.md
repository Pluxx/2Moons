# Local development with DDEV

The PHP 8.5 / Symfony 7.4 application runs in DDEV with Apache-FPM and MariaDB
11.8. From the repository root:

```sh
ddev start
ddev check-php
ddev stop
```

The stable project URL is <https://2moons.ddev.site>; no ephemeral port is needed
for the documented URL. The document root is `public/`, served through Symfony.
`/` redirects to
login or the authenticated planet page; `/login` and `/register` serve the
account forms, and `/health` is public JSON liveness. These routes implement the
first economy slice, not the eventual full game. The legacy application is
outside the public root and is not a served site; tested legacy URLs return 404.
See [LEGACY.md](LEGACY.md) and [PLAYABLE_ECONOMY.md](PLAYABLE_ECONOMY.md).

`ddev check-php` can be rerun at any time. It checks PHP 8.5, the required
`pdo`, `pdo_mysql`, `mysqli`, `gd`, `mbstring`, `curl`, `openssl`, `json`, and
`session` extensions, and GD's JPEG and FreeType support. PHP modules are supplied
by DDEV's web image. If a required module or GD feature is missing after a DDEV
image change, add the corresponding PHP 8.5 package to `.ddev/config.yaml` using
`webimage_extra_packages` (for example, `php${DDEV_PHP_VERSION}-gd`) and rebuild
or restart; keep `ddev check-php` as the verification step. Optional `intl`,
`zip`, `xml`, and `bcmath` modules are not checked as requirements.

The local MariaDB service (11.8.9) is reachable from the web container at host
`db`, port `3306`, database `db`, username `db`, password `db`. These local-only
values are also exposed as `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`.
Do not reuse them outside this local environment. The development database was
verified empty before the additive migration created `game_user`, `planet`,
`construction_entry`, and Doctrine migration metadata. A disposable browser-QA
account and its six queue rows were removed only after a matching snapshot across
a real DDEV restart. Current counts are zero users, planets, and constructions;
all four schema/metadata tables remain initialized. No other rows were deleted,
and no broad reset occurred. It is not a fully initialized galaxy or universe.
Test integration checks use separate `db_test` with the restricted `test_runner`
account. A production database/deployment target is not finalized. See
[FOUNDATION.md](FOUNDATION.md) for safe migration and test-isolation details.

Symfony 7.4.20 and locked dependencies are installed; the local runtime is PHP
8.5.9 with MariaDB 11.8.9. Phase C automated acceptance passed 2 focused tests /
276 assertions; the parent-reviewed full suite passed 51 tests / 4,538 assertions
against `db_test`. Migration/schema checks and direct HTTP checks passed. Real
Chromium checks reviewed registration, authentication, queue interactions, and
1440/390/320-pixel layouts with no horizontal overflow. The local playable-economy gate is accepted. Hosted
CI was not run, and this is not production readiness, a full WCAG audit, or
legacy PHP 8.5 compatibility evidence.

An optional bounded worker invocation is:

```sh
ddev exec php bin/console app:economy:process --limit=25
```

The limit is 1–100 (default 25). Visiting a planet also settles its queue; there
is no always-on broker requirement for this milestone. For a clean local clone,
follow the additive migration quick start in [README.md](../README.md). Never
run destructive database resets to initialize the game.
