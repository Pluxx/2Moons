# Playable economy milestone

## Status

Phase A domain and Phase B persistence/security are accepted. Phase C automated
acceptance and reviewed Chromium interaction/responsive checks pass; parent
accepted Gate C for the local playable-economy milestone. This is not a
production-ready game, a full WCAG audit, or a fully initialized universe. The
reviewed rule and exact-number contracts are
[ECONOMY_RULES.md](ECONOMY_RULES.md) and [NUMERIC_POLICY.md](NUMERIC_POLICY.md).

## Implemented loop

- Registration creates an account and exactly one owned Homeworld at maximum
  temperature 40°C, with 500 metal, 500 crystal, 0 deuterium, zero occupied
  fields, all building levels 0, and the reviewed home field total.
- Accounts use normalized unique email identifiers, framework password hashing,
  CSRF-protected forms, and a login rate limit. Login, logout, and authenticated
  planet overview are implemented.
- The seven building types are metal mine, crystal mine, deuterium synthesizer,
  solar plant, and the metal/crystal/deuterium storage buildings. Formula,
  energy, storage, cost, and duration policy remains in the linked contract.
- Resource accounting is exact rational arithmetic. The migration stores three
  canonical numerator/denominator balance strings as `LONGTEXT`; no public
  floating-point arithmetic authorizes costs, production, or balances.
- Construction uses a FIFO of at most five pending entries including the active
  head. The active head is charged when it starts; waiting entries are unpaid
  and reserve fields, not resources. A waiting entry may be queued while
  currently unaffordable. At scheduled activation, it is charged if affordable;
  otherwise it fails then without waiting for later funds. Dependent queued
  entries of the same building fail; unrelated entries continue at that same
  timestamp. Terminal outcomes are persisted. Cancellation, demolition, and
  reordering are not implemented.
- The planet overview settles that planet when visited. The optional
  `app:economy:process --limit=25` command processes a bounded batch of due
  planets (`--limit` range 1–100; default 25). Catch-up uses scheduled gameplay
  times, not later worker wall-clock time; no always-on broker is required.

## HTTP and persistence

`/` redirects to login or the authenticated planet overview. `/login`,
`/register`, `/logout`, and `/planet` implement the account flow; build forms
POST to `/planet/build/{buildingId}` with expected target, a per-action command
token, and CSRF token. Prices, resources, timestamps, and ownership are
server-owned. `/health` is public JSON liveness, not a gameplay or readiness
claim.

The additive migration `Version20261005000000` creates only `game_user`,
`planet`, `construction_entry`, and Doctrine migration metadata. It was applied
to local `db` only after a read-only preflight verified that database was empty;
the schema then passed migration/schema validation. A disposable browser-QA
account and its six construction rows were removed only after a matching
snapshot across a real DDEV restart and exact affected-row checks. Current
game-data counts are zero; all four tables remain initialized. No other rows
were deleted and no broad reset occurred. Do not reset this database. It is not
a fully initialized galaxy/universe. Automated tests use only `db_test` through
the restricted `test_runner` account; migration and tests verify the selected
database.

## Acceptance evidence and remaining limits

The actual Symfony HTTP acceptance tests use BrowserKit and a test-only
MockClock. They cover successful registration/login, all seven building types,
energy-limited production, storage and fields, waiting admission and activation
failure, late worker catch-up, and logout/login persistence.

- Focused Phase C acceptance: **2 tests / 276 assertions passed**.
- Parent-verified final full local suite: **51 tests / 4,538 assertions passed** against
  `db_test`; migration status, schema validation, and listed local lint checks
  passed.
- Direct local HTTP checks included root redirect, login/register, health JSON,
  static assets, and 404s for legacy paths, `.env`, `/tests/`, and `/.slim/`.
- Parent-reviewed live Chromium checks covered registration, authentication,
  queue interactions, and 1440/390/320-pixel layouts. Gate C is accepted for the
  local playable economy. Hosted CI was not run; there was no full WCAG audit,
  and these results do not claim production readiness.

There is no galaxy/universe selection or full universe initializer, admin area,
email verification/account recovery, fleet gameplay, or combat. Production
deployment, secrets, abuse controls, monitoring, backups, and full accessibility
certification still require their own gates. See
[FOUNDATION.md](FOUNDATION.md) for safe setup and
[REBUILD_PLAN.md](REBUILD_PLAN.md) for milestone status.
