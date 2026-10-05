# Economy rules — contract v1

This is the source-derived contract for the approved first playable economy
milestone, not a claim that the legacy game was executed or that this rebuild
already implements it. The inputs in the fixture file are explicit test cases;
they are not all universal legacy planet defaults. The rebuild intentionally
sets every new home planet's maximum temperature to 40°C as an equal-start
policy; this is not a claim that legacy planets universally had that climate.
The first-milestone catalogue/rule policy is approved, but the eventual game's
full roster is not. Exact numeric representation is specified in
[NUMERIC_POLICY.md](NUMERIC_POLICY.md).

## Decision labels

| Area | Decision | Contract |
| --- | --- | --- |
| Initial resources and rates | Preserve | A new planet starts with 500 metal, 500 crystal, 0 deuterium; basic income is 20/10/0 per hour before the resource-speed multiplier. |
| Mine and solar formulas | Preserve | Use the source formulas and energy allocation below, including whole-unit rounding of generated energy. |
| Storage formula | Preserve | Each resource has a 10,000 base capacity at storage level 0; storage buildings increase only their matching capacity. |
| Cost curve | Preserve | Price is the catalogue base cost multiplied by `factor ^ target_level`, not `factor ^ (target_level - 1)`. Fractional amounts remain meaningful. |
| Build-time curve | Preserve | Building seconds are `floor((metal_cost + crystal_cost) / (game_speed * (1 + robotics_level)) * 0.5 ^ nanite_level * 3600)`, bounded below by minimum build time. |
| Fractional affordability | Change intentionally (confirmed bug) | Compare exact balances to exact costs. Do not floor a resource balance first; a balance of 22.5 pays a 22.5 cost. |
| Completion accrual | Change intentionally (confirmed ordering bug) | Accrue using the old building/production state up to the scheduled completion timestamp; apply the completion and derived-cache change at that timestamp; use new rates only after it. Processing an elapsed interval in pieces must produce the same result under the chosen persistence precision policy. |
| Resource precision/carry | Approved technical policy | Use canonical exact rational values for authoritative balances, rates, prices, and formula intermediates; no save/load quantization. This specifies rebuild representation, not a claim about legacy database precision. See [NUMERIC_POLICY.md](NUMERIC_POLICY.md). |

The numeric fixtures inherit the source's default settings unless overridden:
`game_speed=2500`; resource, storage, and energy multipliers 1; maximum
overflow 1; minimum build time 1 second; 163 home-planet total fields; zero
initial building levels; and 100% operating percentages. There are no resource,
energy, or build-time bonuses and vacation mode is off in these calculations.
The rebuild's equal-start planet policy sets `temp_max=40`; source-derived
production fixtures also state temperature explicitly, and do not assert a
universal legacy planet temperature. Omitted stock/capacity values
inherit the fixture's stated starting stock or the computed level-zero storage
capacity. No prerequisites are seeded for the seven building IDs in fixtures.

## Rates, energy, and storage

For a planet with metal mine level `m`, crystal mine `c`, deuterium synthesizer
`d`, and solar plant `s`, and operating fractions `p_m`, `p_c`, `p_d`, `p_s`
(each percentage divided by 100), the legacy production grid gives, before
energy allocation and global resource speed:

- Metal mine: `30 * m * 1.1^m * p_m` metal/hour; mine energy demand `10 * m * 1.1^m * p_m`.
- Crystal mine: `20 * c * 1.1^c * p_c` crystal/hour; mine energy demand `10 * c * 1.1^c * p_c`.
- Deuterium synthesizer: `10 * d * 1.1^d * (1.28 - 0.002 * temp_max) * p_d` deuterium/hour; energy demand `30 * d * 1.1^d * p_d`.
- Solar plant: `20 * s * 1.1^s * p_s` generated energy. After bonuses and energy-speed multiplier, total generated energy is rounded to the nearest integer by the source cache calculation. Energy is a simultaneous production budget, not an hourly stock of energy.

The energy production factor is `min(1, rounded_generated_energy / total_mine_energy_demand)` when demand is nonzero. It scales positive mine production, not basic income. Positive mine income is then multiplied by the resource speed (and applicable bonuses); the default resource speed is 1. With no energy demand, the source cache sets the mine per-hour additions to zero. Basic incomes are still applied separately by elapsed-resource accrual.

The level prefactors mean every level-0 mine and solar plant produces/demands
zero. The synthesizer temperature input matters: `temp_max=40` is used by
production fixtures as an explicit example, not claimed as every planet's
default. With all three mines at level 1, 100% operating percentages, and
`temp_max=40`, gross mine rates are 33, 22, and 13.2 units/hour; demand is 11,
11, and 33 energy (55 total). Solar level 1 supplies 22 energy, so the factor
is 0.4 and resulting total rates including basic income are 33.2 metal, 18.8
crystal, and 5.28 deuterium per hour. Solar level 2 has raw output 48.4,
rounded to 48; with all mines at level 1, the factor is 48/55 and resulting
total rates are 48.8, 29.2, and 11.52 per hour. A level-1 metal mine and
level-1 solar plant alone have generated energy 22, demand 11, and total rates
53/10/0 per hour. With all buildings at level 0, generated energy/demand are
0/0 and total resource rates are the basic incomes 20/10/0 per hour.

At storage level `L`, the storage formula evaluates to
`floor(2.5 * 1.8331954764^L) * 5000`: levels 0, 1, and 2 therefore hold 10,000,
20,000, and 40,000 of the corresponding resource. The SQL planet-column
initial `*_max` value of 100,000 is a placeholder; it is not capacity from the
storage formula. Storage multiplier, storage bonuses, and maximum overflow apply as
universe/player modifiers. For this milestone their defaults are 1.

Elapsed positive production stops at capacity. If stock is already above
capacity, further positive production adds nothing and does not reduce the
surplus. Negative production reduces stock but never below zero. Resource
accrual is proportional to elapsed seconds and includes basic income.

## Construction and source map

Construction cost uses the target level as exponent. Build duration uses the
metal-plus-crystal price, game speed, robotics and nanite levels, then floors
to whole seconds and applies the minimum. The fixture examples use robotics and
nanite level 0, game speed 2500, and minimum 1 second. For these buildings the
seeded catalogue has no prerequisites.

### Waiting construction queue (approved first-milestone policy)

The milestone includes a FIFO queue of at most five pending entries, including
the active head. The active head is paid immediately. Waiting entries are unpaid
and reserve fields only; their resources are neither charged nor escrowed at
enqueue, and a valid waiting entry may be admitted even if its cost exceeds
current stock. Their intended target levels include preceding entries of the
same building; the server computes and validates targets. A completed entry
converts its field reservation into an occupied field, while a failed entry
releases its reservation. Prices and durations are recomputed at activation
using the immutable milestone settings; waiting completion timestamps are
estimates, not authoritative deadlines.

At a scheduled activation time, compare resources available at that gameplay
time. If insufficient, mark the entry failed without payment; do not wait for
future income. Release its field reservation and continue to the next eligible
entry at that same timestamp. A failed upgrade also fails later queued entries
of that same building as dependent, avoiding target/actual-level mismatch; other
building entries remain eligible. These failure/dependency rules are intentional
queue-consistency decisions, not claims of exact legacy parity. No cancel,
demolish, or reorder controls are in this milestone. Late processing settles
events at scheduled times, not worker wall-clock time. See [NUMERIC_POLICY.md](NUMERIC_POLICY.md)
for exact amounts, representability guards, and serialization.

Future persistence must retain terminal completion/failure outcomes. Future
enqueue requests require an unpredictable per-action replay token/idempotency key
and CSRF protection; these are implementation requirements, not features that
exist today.

The approved completion correction deliberately differs from legacy ordering:
the legacy queue handler increments the building level before calling resource
update at the completion time. That can apply the new rate retroactively over
the interval. The contract instead settles old rates through completion and
starts the new rates at the event boundary. Example: with no mines and a
level-1 solar plant, stocks 410/477.5/0, and a 162-second first-level metal
mine build, source-order legacy accrual yields 412.385 metal (the new mine's
33/hour production plus 20/hour basic income); corrected old-state accrual
yields 410.9. Crystal is 477.95 in both. These are
source-derived arithmetic comparisons, not observed legacy runtime outputs.

Relevant source locations (all under `legacy/`):

- `install/install.sql` lines 947–959: building catalogue costs, factors,
  production/storage expressions; lines 160–177, 212, 258, 267–269, 284:
  universe defaults and seed data.
- `includes/classes/class.BuildFunctions.php` lines 47–60, 65–107, 129–179:
  affordability, target-level price exponent, and build-time calculation.
- `includes/classes/PlayerUtil.class.php` lines 223–305: planet temperature,
  home fields, starting resources, and creation values.
- `includes/classes/class.PlanetRessUpdate.php` lines 119–182, 249–303,
  378–419: elapsed accrual and caps, production/energy/storage cache, and
  construction completion ordering.
- `includes/pages/game/ShowBuildingsPage.class.php` lines 156–178: construction
  affordability, payment, duration, and queue insertion.
- `includes/pages/game/ShowBuildingsPage.class.php` lines 136–151, 159–204 and
  `includes/classes/class.PlanetRessUpdate.php` lines 439–498: queue limit/field
  reservation, immediate head payment, unpaid waiting entries, and scheduled
  activation checks. The approved dependent-entry failure policy intentionally
  corrects a same-building target consistency defect.

The fixture `source_status` is `source-derived` throughout. No legacy runtime
was initialized or used to generate these expected values. The fixture
`legacy_expected` fields are limited to arithmetic consequences of inspected
legacy code where the approved corrected behavior differs; they are not runtime
parity claims.
