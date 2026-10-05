# Game identity and proposed first release

## Status

The eventual v1 game scope remains a proposal, not final product approval. The
product owner has separately approved the **first playable milestone** as a full
resource-economy slice: register, receive one planet, accumulate all three
resources, build the metal/crystal/deuterium mines, solar plant, and matching
storage upgrades, use the five-entry FIFO construction queue, and persist
construction and production. Every new home planet starts at maximum temperature
40°C as an intentional equal-start policy. This approval does not settle the
eventual v1 roster, later gameplay scope, or production limits.
The source-derived first-milestone contract is documented in
[ECONOMY_RULES.md](ECONOMY_RULES.md), with machine-readable cases in
[fixtures/economy.json](fixtures/economy.json); exact representation and rounding
are specified in [NUMERIC_POLICY.md](NUMERIC_POLICY.md). The rules preserve the
reviewed source balance except for explicitly recorded confirmed-bug and queue
consistency fixes, plus the approved common starting climate.

## Game identity

2Moons is a strategic space game about building an interstellar presence over
time. Players develop planets and an economy, make progression choices, and
eventually use fleets to interact with other destinations. The rebuild should
retain that sense of long-term planning, resource trade-offs, and a space-game
atmosphere, while treating the old implementation as a source of evidence rather
than a design or architecture to copy.

## Proposed first-release scope

The plan's proposed first release is a single universe with limited planet
ownership, a basic resource economy, construction and research, a deliberately
small ship roster, transport, attacks, battle reports, and minimal administration.
The exact catalogue, planet limits, and order of later features are pending
approval. Avoid broad legacy feature parity until the core loops are proven.

The approved first playable milestone is:

**register → receive one temperature-40 planet → accumulate metal, crystal, and
deuterium → build mines, solar power, and storage upgrades through the waiting
queue → complete construction → observe persisted energy-limited production and
storage behavior**.

Phase C is accepted for the implemented local loop. Focused acceptance passed
2 tests / 276 assertions, and the parent-reviewed full local suite passed
51 tests / 4,538 assertions against isolated `db_test`. Parent-reviewed live
Chromium checks covered registration, authentication, queue interaction, and
1440/390/320-pixel layouts. This is not a full WCAG audit, hosted-CI execution,
or production-readiness claim.

## Proposed exclusions

The plan proposes deferring alliances and group attacks, expeditions,
moons/destruction, premium currency, marketplaces, integrated chat, elaborate
rankings, extensive localization, and full legacy feature parity. These are
scope proposals, not permanent product decisions.

## Decisions and open questions

| Topic | Current position |
| --- | --- |
| Legacy location | Confirmed: the original application is preserved under `legacy/`, outside the `public/` document root. See [LEGACY.md](LEGACY.md). |
| Runtime | Confirmed target: PHP 8.5. This does not establish legacy compatibility. |
| Framework | Selected and installed: Symfony 7.4 LTS (`7.4.*`). Composer's PHP constraint is `^8.5`; the foundation was installed and smoke-checked on PHP 8.5.9. Recheck the official [Symfony roadmap](https://symfony.com/roadmap) and verify compatibility when changing framework/dependency versions. The roadmap lists maintenance through November 2028 and security fixes through November 2029; revalidate those dates when implementation begins. |
| Application shape | Proposed direction from the rebuild plan: one deployable monolith with server-rendered pages and selective JavaScript. Detailed boundaries and UI decisions remain open. |
| Database | MariaDB 11.8.9 is installed locally. After a read-only empty-database check, the additive migration created the three game tables and metadata in dev `db`; the exact disposable browser-QA account and its six queue rows were removed after a matching restart snapshot. Current game-data counts are zero; no other rows were deleted and no broad reset occurred. Test data belongs only to `db_test`; a production database/deployment target is not finalized. |
| First economy milestone | Approved, implemented, and accepted locally: registration/one 40°C planet, all seven economy buildings, exact persisted resources/production, and the five-entry queue. Phase C automated and parent-reviewed Chromium gates pass; full WCAG audit and production readiness are not claimed. See [ECONOMY_RULES.md](ECONOMY_RULES.md), [NUMERIC_POLICY.md](NUMERIC_POLICY.md), and [PLAYABLE_ECONOMY.md](PLAYABLE_ECONOMY.md). |
| Eventual v1 catalogues and limits | Open beyond the approved first-economy catalogue. Planet limits and later building/research/ship/defense rosters are not approved. |
| Legacy behavior | Decide per rule: preserve, intentionally change confirmed bugs, or leave unresolved. The first economy contract records those decisions; do not treat other legacy bugs as requirements. |
| Fleet and combat rules | Open: ships, travel, fuel, cargo, missions, randomness, rounding, loot, debris, and reports require explicit rules and approval. |

## Remaining approvals and scope boundaries

The first resource-economy milestone and its numeric/queue policies are approved,
implemented, and accepted locally. This is not approval of the eventual v1 game.
Product approval is still needed for eventual v1 breadth, planet limits, and
later catalogues. Account/planet/queue tables and one-homeworld-per-account
behavior exist locally, but no galaxy, universe settings/initializer,
administration, fleets, or combat are implemented. See [FOUNDATION.md](FOUNDATION.md),
[PLAYABLE_ECONOMY.md](PLAYABLE_ECONOMY.md), and [REBUILD_PLAN.md](REBUILD_PLAN.md).
