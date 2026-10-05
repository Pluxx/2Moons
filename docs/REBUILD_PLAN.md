# 2Moons modern rebuild plan

## Goal and boundaries

Build a fresh PHP 8.5 browser game that preserves 2Moons' strategic pacing,
resource economy, progression, fleet gameplay, and space-game identity.
Use the original implementation as a gameplay reference, not an architectural
template. There are no existing players or databases to migrate.

## Current status

Phase 0 preservation is complete: the original revision is tagged and the legacy
tree is isolated under `legacy/`; see [LEGACY.md](LEGACY.md). The first playable
economy scope and its source-derived formulas, exact-rational accounting,
temperature-40 equal-start policy, and five-entry queue are approved. Phase 1's
broader v1 breadth, planet limits, and later-game rules remain open.
[GAME_SCOPE.md](GAME_SCOPE.md) records that distinction.

The Symfony/PHP foundation, account/authentication, additive game schema,
one-homeworld persistence, economy domain, rendered planet interface, and bounded
worker are implemented locally. Phase A/B are accepted and Gate C is accepted for
the local playable economy. The full suite passed 51 tests / 4,538 assertions;
parent-reviewed Chromium checks covered 1440/390/320-pixel layouts. The additive
migration followed a read-only empty-`db` check. A disposable browser-QA account
and its six construction rows were removed after matching restart snapshots;
current game-data counts are zero. No other rows were deleted and no broad reset
occurred. No full galaxy/universe is initialized, and this is not production
readiness. See [FOUNDATION.md](FOUNDATION.md) and
[PLAYABLE_ECONOMY.md](PLAYABLE_ECONOMY.md).

This document is a plan, not authorization to implement every feature. Symfony
7.4.20 is installed and pinned to `7.4.*`, with PHP `^8.5`; local runtime checks
used PHP 8.5.9. Doctrine ORM/DBAL is installed with MariaDB 11.8.9 in DDEV. This
does not finalize a production database/deployment target. No legacy runtime
compatibility or full-gameplay parity has been proven.

## Working principles

- One deployable application, with HTTP pages and a CLI event worker.
- Server-rendered UI with selective JavaScript unless a concrete need says otherwise.
- Modernize workflows and security; do not merely reskin the old templates.
- Keep gameplay rules separate from HTTP, persistence, and presentation.
- Build playable vertical slices instead of entire disconnected subsystems.
- Record intentional balance changes and fixes rather than silently changing rules.
- No microservices, generic plugin framework, event sourcing, or migration bridge for v1.

## Suggested ownership

- **Product owner:** approve scope, game identity, and intentional rule changes.
- **Backend lane:** implement rules, persistence, authentication, and event processing.
- **Designer lane:** own navigation, visual direction, interactions, and responsive UI.
- **Validation owner:** review evidence and gate each milestone; initially the coordinating agent.

These are responsibility labels, not staffing requirements. Assign concrete
owners before implementation. Writers work in non-overlapping file scopes.

## Phase 0 — Preserve the original

1. Record the original revision and create a clearly named Git tag before relocation.
2. Inventory tracked files and any untracked configuration or assets before moving anything.
3. Preserve the complete original application tree under `/legacy`.
4. Keep legacy outside the new `public/` document root, autoloading, and production deployment.
5. Document the isolated reference environment and its working directory, base URL,
   database initialization, and entry points. Never expose an obsolete runtime publicly.
6. Preserve copyright notices and audit reused artwork/dependency licenses separately.

**Status: complete.** The annotated tag `2moons-legacy-baseline` identifies
revision `b45cbc11d0a99e25058ab3ff1a63076cb7b93a72`. A pre/post hash, mode, and
symlink inventory matched the relocated tree. Preservation validation matched
1,737/1,737 tracked files and all 1,868/1,868 inventory records with zero
discrepancies; see [LEGACY.md](LEGACY.md). DDEV serves `public/`; legacy URLs
return 404. No legacy PHP was executed and no source-runtime parity claim is made.

**Gate:** validation owner checks traceability, completeness, deployment exclusion,
and relocation assumptions. If the legacy app cannot run safely, document that
limitation and use source-derived fixtures rather than claiming runtime parity.

## Phase 1 — Define the game contract

Write and approve the game-identity brief and eventual first release's exact
features. The proposal is in [GAME_SCOPE.md](GAME_SCOPE.md); eventual v1 breadth
is not yet product-approved. The first playable economy milestone is accepted;
the next colonization/owned-transport milestone is approved as a rules contract,
with independent source-derived fixtures in [TRANSPORT_RULES.md](TRANSPORT_RULES.md)
and [fixtures/transport.json](fixtures/transport.json).
Extract versioned rule sheets covering:

- Resource identifiers, production, storage, costs, and universe speed modifiers.
- Buildings, research, shipyard queues, prerequisites, and completion timing.
- Ship statistics, travel, fuel, cargo, missions, and return behavior.
- Combat, randomness, rounding, loot, debris, and report outcomes.

For each rule, record its legacy source, examples, and a decision:
**preserve**, **change intentionally**, or **unresolved**. Do not treat every
legacy bug as a requirement. Keep game settings and catalogue data explicit.

**Status/gate:** first-economy scope and its source-derived rule, queue, and
numeric policies are accepted through Phase C. Colonization/transport rules are
approved; that milestone's Phase A pure-domain implementation is underway, with
Phase B and C not started. Exact rational persistence is documented in
[NUMERIC_POLICY.md](NUMERIC_POLICY.md). Phase 1 as a whole remains open for
eventual v1 scope and combat rules. Transport fixtures are independently
calculated; no isolated legacy runtime was used.

## Phase 2 — Establish a small modern foundation

Symfony 7.4.20 is installed (`7.4.*`) with PHP `^8.5`; dependencies were
installed and smoke-checked on PHP 8.5.9. Doctrine ORM/DBAL and migration support
are present. DDEV provides MariaDB 11.8.9 locally, while the production database
target is not finalized. Prefer established authentication, validation,
migrations, and testing facilities over building infrastructure from scratch.

Deliver:

- Reproducible local setup, Composer dependencies/lockfile, and PHP 8.5 CI.
- Fresh schema, migrations, seedable universe configuration, and test database setup.
- Registration/login with modern password hashing, sessions, CSRF protection,
  authorization, input validation, and basic request throttling.
- Clear rule/persistence/HTTP boundaries, a CLI entry point, and structured logging.
- Controllable clock and random-draw inputs for deterministic gameplay tests.

**Gate:** backend demonstrates a request, transaction, migration, CLI command,
and test on PHP 8.5. Validation owner confirms dependency support and clean setup.
Do not introduce Redis or a broker unless measured requirements justify them.

**Status:** the runtime/dependency, local HTTP/health, schema/migration, account,
authentication, transaction, CLI worker, and isolated-database foundation for
this economy slice are implemented and validated locally; Phase B is accepted.
The additive migration created the three game tables and metadata after the
read-only empty-`db` preflight. No full galaxy/universe settings or initializer,
administrative area, operational release setup, or complete universe data
exists. Hosted CI YAML was parsed, but no hosted run is claimed. The account and
homeworld slice is not a fully initialized game world or release environment.

## Phase 3 — First playable planet loop (scope expanded)

Implemented scope: **register → receive one planet at the common 40°C starting
maximum → accumulate metal, crystal, and deuterium → construct mines, solar power,
and storage upgrades through the five-entry FIFO queue → observe persisted,
energy-limited production and storage behavior**. Phase A and B are accepted.
Phase C automated and parent-reviewed browser acceptance passes; the local
playable-economy gate is accepted. Browser checks covered 1440/390/320-pixel
layouts; this is not a full WCAG audit.
This does not approve the eventual v1 roster or later research, fleet, combat,
or release scope.

The approved catalogue is the metal mine, crystal mine, deuterium synthesizer,
solar plant, and three resource storage buildings. Persist exact planet balances,
levels, queue transitions, construction and timestamps. The queue holds at most
five pending entries including its active head; waiters reserve fields but are
unpaid until activation, and scheduled-time shortfalls fail without waiting for
later income. Use the documented dependent same-building failure correction.
The approved server-rendered planet interface and queue explanations are
implemented and covered by the reviewed Chromium checks. Keep UI changes within
the designer-owned direction. Economy transaction and event semantics are
implemented; their accepted scope does not imply fleet or combat readiness.

**Automated evidence:** the focused browser acceptance passed **2 tests / 276
assertions**; the full local suite passed **51 tests / 4,538 assertions** against
isolated `db_test`. It covers all seven building types, queue outcomes, elapsed
production, energy/storage, reload/login persistence, and late worker catch-up.
Gate C is accepted for the local playable economy. Hosted CI was not run; these
results do not establish production readiness or acceptance of later phases.

## Phase 4 — Research, shipyard, colonization, and transport (Phase A underway; not playable)

The approved rules and independent numeric fixtures are in
[TRANSPORT_RULES.md](TRANSPORT_RULES.md) and
[fixtures/transport.json](fixtures/transport.json). Phase A pure-domain work is
underway. The old seven-building HTTP surface remains in place as a compatibility
bridge. Phase B persistence/schema and Phase C playable acceptance have not
started; no universe, research, shipyard, colony, fleet, or new HTTP route is
claimed implemented. Once those phases are approved and complete, the intended
loop is **build ship → select a destination → load cargo → launch → arrive →
return**.

Implement durable scheduled events and the worker using transport before combat.

**Event contract:**

- Apply events at their scheduled gameplay time, not merely worker execution time.
- Define ordering for simultaneous events and interactions with player requests.
- Catch up overdue events in the correct order, including follow-up events.
- Commit state changes, completion markers, and subsequent events atomically where possible.
- Prevent duplicate effects with constraints, transactions, and deliberate locking.
- Make retries harmless and abandoned worker claims recoverable, if claims are used.
- Test interruption before commit, after commit, and competing worker execution.

**Gate:** validation owner verifies arrival/return timing, cargo and ship accounting,
late processing, duplicate delivery, concurrent workers, and crash recovery.
Demonstrate one committed gameplay effect despite repeated attempts; do not claim
exactly-once execution merely because a queue exists.

## Phase 5 — Minimum combat loop (not implemented)

Add a small combat ship/defense roster, essential target information, attacks,
agreed loot/debris rules, and readable battle reports. Provide a controlled target
setup for testing and early playtesting; expand planetary mechanics only as needed.

**Gate:** tests use controlled random draws, not just seeds, and verify resulting
database state as well as reports. Cover empty defenses, losses, cargo limits,
rounding, arrival-time production, simultaneous arrivals, and returns.
Product owner approves intentional differences from the legacy game.

## Phase 6 — Fresh-universe release (not implemented)

Deliver minimal administration, universe initialization, operational logs,
worker health monitoring, deployment instructions, and backup/restore procedures.
Complete responsive/accessibility checks, security review, and gameplay playtesting.
Agree an initial player/event load target before measuring performance.

**Gate:** validation owner witnesses clean installation, backup restoration,
worker-downtime recovery, security checks, and representative load tests.
Product owner signs off on gameplay readiness. Release to a small test group
before broader launch.

## First release scope

Proposed essentials: one universe, limited planet ownership, basic resources,
construction/research, a small ship roster, transport, attacks, reports, and
minimal administration. Exact catalogue and planet limits require approval.

Defer alliances, ACS/group attacks, expeditions, moons/destruction, premium
currency, marketplaces, integrated chat, elaborate rankings, extensive
localization, and full legacy feature parity. Revisit them after the core loop
has been played and its event model is proven.

## Dependencies and parallel work

```text
Preserve → Rules/scope → Foundation → Planet → Transport/events → Combat → Release
                           |           |            |              |
UI/design:             direction → planet UI checked → fleet UI → battle UI → polish
Validation:            fixtures → rule tests → concurrency/recovery → release evidence
Operations:            CI/setup ─────────────→ worker health ───────→ restore/deploy
```

Rules research and design for the next slice can run alongside implementation
of the current slice. Combat depends on reliable economy and fleet timing;
do not build it in isolation against unapproved assumptions.

## Legacy reference map

| Area | Starting points |
| --- | --- |
| Catalogue and universe settings | `legacy/includes/vars.php`, `legacy/install/install.sql`, `legacy/includes/classes/Config.class.php` |
| Economy and construction queues | `legacy/includes/classes/class.PlanetRessUpdate.php`, `legacy/includes/classes/class.BuildFunctions.php` |
| Buildings/research/shipyard flows | `legacy/includes/pages/game/ShowBuildingsPage.class.php`, `ShowResearchPage.class.php`, `ShowShipyardPage.class.php` in that directory |
| Fleet scheduling and dispatch | `legacy/includes/FleetHandler.php`, `legacy/includes/classes/class.FleetFunctions.php`, `legacy/includes/classes/class.FlyingFleetHandler.php` |
| Missions and combat | `legacy/includes/classes/missions/`, especially `functions/calculateAttack.php` and `functions/GenerateReport.php` |
| Authentication/session reference | `legacy/includes/pages/login/ShowLoginPage.class.php`, `legacy/includes/classes/Session.class.php` |
| Presentation and content | `legacy/styles/templates/`, `legacy/styles/resource/`, `legacy/language/` |
| Existing checks and tooling | `legacy/tests/run.js`, `legacy/.travis.yml`, `legacy/docker-compose.yml`, `legacy/composer.json` |
| Licensing and credits | Root `LICENSE`, `legacy/README.md`, `legacy/licenses/`, individual library notices |

Paths above describe the current tree under `legacy/`. Entry points derive roots
from their own locations and legacy HTTP/include paths have additional
assumptions: relocation must be checked, not presumed safe. Existing checks are
insufficient to establish gameplay parity or PHP 8.5 support.
Legacy login uses MD5 comparisons; preserve the authentication workflow, not that implementation.

## Verification evidence to retain

For each milestone keep rule fixtures, repeatable test commands, observed state,
and unresolved limitations. Backend supplies executable evidence; designer checks
interaction/accessibility behavior; validation owner accepts or rejects the gate.

Use pure rule tests for formulas, database integration tests for transactions and
event state, and a small set of browser tests for the actual playable loops.
Legacy outputs are evidence of old behavior, not automatically the desired result.
Never promote a source-only comparison into a claim of full runtime equivalence.

## Immediate next steps

1. Record the accepted Phase C evidence in [PLAYABLE_ECONOMY.md](PLAYABLE_ECONOMY.md); do not imply a full WCAG audit or hosted-CI run.
2. Decide and implement galaxy/universe settings and initialization, administration, and deployment/operations only as separately approved; the account-owned homeworld is not a full initialized universe.
3. Keep eventual v1 breadth and production database selection open. Research/fleet, combat, and later release phases remain unimplemented; do not claim production readiness.

No delivery dates are assigned until the catalogue, scope, and available effort
are agreed. Completion is measured by playable milestones and evidence, not file count.
