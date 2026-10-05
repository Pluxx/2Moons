# Colonization and transport rules

## Status and source boundary

This is the approved source-derived rules contract for the next colonization/
transport milestone. It is not evidence that these rules are implemented or
playable. Phase A pure-domain work is underway; persistence/schema work (Phase B)
and playable acceptance (Phase C) have not started. The existing seven-building
HTTP experience remains the compatibility surface until Phase B.

Machine-readable independent examples are in [fixtures/transport.json](fixtures/transport.json).
Values below are frozen from the accepted architecture and inspected legacy
source; legacy PHP was not executed. The fixture writer derived expected
arithmetic independently using exact rational/integer operations and certified
integer-square-root bounds, not the new production calculators. For
`sqrt(n/d)`, bounds at scale `S` use `r=isqrt(floor(n*S²/d))`, with lower `r/S`
and upper `(r+1)/S` unless exact; rational interval operations are refined at
16, 32, 64, 128, then 256 decimal places. Only when both bounds imply the same
HalfUp integer is an expected flight value accepted. This is not a runtime
parity claim.

## Catalogue and price rules

The original seven economy buildings and their prices/formulas remain governed
by [ECONOMY_RULES.md](ECONOMY_RULES.md); their existing fixture values are not
copied here. Add three buildings, five account technologies, and two ships:

| ID | Item | Base M/C/D | Prerequisites (completed state) |
|---:|---|---:|---|
| 14 | Robotics Factory | 400/120/200 | — |
| 21 | Shipyard | 400/200/100 | Robotics 2 |
| 31 | Laboratory | 200/400/200 | — |
| 106 | Spy Technology | 200/1000/200 | Laboratory 3 |
| 113 | Energy Technology | 0/800/400 | Laboratory 1 |
| 115 | Combustion Technology | 400/0/600 | Laboratory 1, Energy 1 |
| 117 | Impulse Technology | 2000/4000/600 | Laboratory 2, Energy 1 |
| 124 | Expedition Technology | 4000/8000/4000 | Laboratory 3, Spy 3, Impulse 3 |
| 202 | Small Cargo Ship, per unit | 2000/2000/0 | Shipyard 2, Combustion 2 |
| 208 | Colony Ship, per unit | 10000/20000/10000 | Shipyard 4, Impulse 3 |

Building and research target costs are `base × factor^target`: factor 2 except
Expedition Technology (124), factor 7/4. Ship costs multiply linearly by unit
count. Max level remains 255. No Computer Technology is added: one active fleet
per account, return journey included. University, Nanites, officers, and
unsupported bonuses remain zero. Energy Technology only unlocks prerequisites;
it adds no solar bonus. Keep the name **Expedition Technology**, not
“Astrophysics.” All ten building levels occupy fields; waiting construction
entries reserve fields.

Examples assert the three facility costs at level 1; an implementation adapter
may use the price formula for other targets. Test duration with frozen activation
inputs: building time is `max(1,floor((M+C)/(2500×(1+Robotics))×3600))`;
research time is `max(1,floor((M+C)/(1000×(1+Laboratory))×3600))`; ship unit time
is `max(1,floor((M+C)/(2500×(1+Shipyard))×3600))`. Relevant fixed witnesses:
Robotics L1 with Robotics 0 = 1497s; Robotics L2 with Robotics 1 = 1497s;
Shipyard L1 with Robotics 2 = 576s; Laboratory L1/L2/L3 with Robotics 2 =
576/1152/2304s. The Spy calculator witnesses at Laboratory 3 are
2160/4320/8640s for targets 1/2/3; these use the completed Laboratory 3 required
for Spy Technology admission.
The accepted witness for Expedition target 1 at Laboratory 3 is 18900s.
Shipyard 4 yields Small Cargo unit time 1152s and Colony Ship unit time 8640s.
These times are snapshotted when work activates.

## Admission and queues

- Construction: FIFO capacity five including paid active head. Waiting entries
  are unpaid; prerequisites use completed levels. Robotics changes only a newly
  activated timer. Waiting estimates may simulate earlier success but are not
  quotes. A failed target and dependent successors are removed; unrelated work
  can continue at that boundary.
- Research: one account-wide FIFO, capacity two including active head. Each
  entry names its funding/laboratory planet; active work pays on admission,
  waiting work on activation. Targets are completed level + same-tech pending
  count + 1. Admission requires completed prerequisites. A failed activation
  removes same-technology successors; unrelated entries continue.
- Laboratory busy rules are deliberately not a global mutex. A Laboratory
  upgrade cannot be admitted while account research is active. Research cannot
  be admitted when a Laboratory upgrade is pending on its source planet. An
  already-admitted upgrade on another planet may overlap later research.
- Shipyard: per-planet FIFO of ten batches, each 1–1,000,000 units. One batch per
  command, fully paid at admission including waiters. A Shipyard upgrade and
  ship batches mutually exclude one another while pending. Completed units are
  materialized with integer division, never one loop per ship.
- New admissions require completed prerequisites; a waiting upgrade does not
  satisfy them. Construction pays the head immediately; research and shipyard
  follow their distinct payment rules above.

## Fleet arithmetic and validation

All resource, speed, distance, and timing calculations are exact until a
specified integer boundary. For coordinates `(g,s,p)`, no wraparound:

```text
if Δg != 0: d = 20000 × |Δg|
else if Δs != 0: d = 95 × |Δs| + 2700
else if Δp != 0: d = 5 × |Δp| + 1000
else: d = 5
```

Speed selector `k` is an index 1–10 (displayed as 10–100%), not the display
percentage. With slowest selected speed `v`, fleet factor `F=1`:

```text
D = max(5, (3500/(k/10) × sqrt(10d/v) + 10)/F)
q = HalfUp(D)
arrival = departure + q
return  = departure + HalfUp(2D)   # independently, not arrival + q
s_i = 35000/(qF−10) × sqrt(10d/v_i)
fuel = HalfUp(Σ consumption_i × count_i × d/35000 × (s_i/10+1)²) + 1
```

Do not persist approximate raw `D`: fuel uses integer `q`, and return rounding
uses raw `2D`. Do not round each ship contribution. Small cargo
uses base speed 5000 in both drive branches (the source `speed2` is unused here):
Combustion below Impulse 5, Impulse at/above 5; consumption changes 10 to 20.
Colony ship speed is `2500×(1+Impulse/5)`, consumption 1000. Capacity is 5000
per small cargo and 7500 per colony ship. Fuel uses capacity and is charged once
at departure, including failed colonization; it is not refunded.

Require at least one ship; mission 7 requires a colony ship. Cargo is strict
whole-unit canonical input: reject fractions, negatives, exponent notation,
malformed values, and excess requests; do not round, clamp, or silently floor.
Transport requires positive cargo and an authenticated-owner destination;
colonization permits zero cargo. At launch `cargo + fuel ≤ capacity` and source
balances must cover cargo plus fuel. Reject a flight to its source even though
the mathematical same-coordinate distance is 5. Refinement scales are 16, 32,
64, 128, 256. If roots/rounding cannot be certified or a signed-BIGINT effect
cannot be represented, reject before payment. Checked nonnegative persisted
counts are bounded by 9223372036854775807.

## Planets, colonization, and transport

Universe defaults: 9 galaxies, 400 systems, 15 positions. Valid coordinates are
1-based and do not wrap. Enforce unique `(universe,galaxy,system,position)`.
Homes are deterministically allocated by bounded scan through positions 3–12,
then systems and galaxies; this intentionally replaces random retry/cursor home
placement. Homes remain 40°C, 163 fields, and 500/500/0. Colonies use source
position bands (maximum temperature sampled uniformly from the inclusive source
range; minimum is maximum−40) and sampled integer fields:

| Position band | Max temperature | Fields |
|---|---:|---:|
| 1 | 220–260 | 95–108 |
| 2 | 170–210 | 97–110 |
| 3 | 120–160 | 98–137 |
| 4 | 70–110 | 123–203 |
| 5 | 60–100 | 148–210 |
| 6 | 50–90 | 148–226 |
| 7 | 40–80 | 141–273 |
| 8 | 30–70 | 169–246 |
| 9 | 20–60 | 161–238 |
| 10 | 10–50 | 154–224 |
| 11 | 0–40 | 148–204 |
| 12 | −10–30 | 136–171 |
| 13 | −50–−10 | 109–121 |
| 14 | −90–−50 | 81–93 |
| 15 | −130–−90 | 65–74 |

For a position `p`, select its row, sample integer maximum temperature and
fields inclusively, then set minimum temperature to maximum−40. Expedition
requirements are positions 1/15:8, 2/14:6, 3/13:4, 4–12:1.
They and the owned-planet cap are checked at arrival, not launch; early dispatch
is permitted with a warning. Cap is `ceil(9+min(11,L/2))`, where `L` is
Expedition level; no officers, maximum 20, includes home. Positions 1–15 only;
position 16 is never a colony target.

No coordinate reservation occurs during flight. First successfully committed
arrival-resolution claim wins; globally earliest scheduled arrival across
accounts is not promised. Successful colony birth is at its scheduled arrival,
with 500/500/0 plus cargo and all building/ship levels zero; consume one colony
ship. Remaining ships return empty; a sole consumed ship ends the fleet. Failed
colonization preserves ships and cargo for return, while fuel stays spent.
Transport adds cargo at arrival without storage clipping, clears it exactly
once, and returns ships. A missing destination retains cargo for return; a
missing source is an integrity failure and rolls back. No deletion, transfer, or
recall operation is introduced. Empty transport, foreign targets, and malformed
cargo are rejected intentionally.

## Account timeline policy

One account-scoped chronological settlement advances every owned planet under
the account lock. For each boundary: accrue all existing planets using old
rates/caps; materialize ship units; complete research/construction/ship batches;
resolve arrivals then returns; activate research first, construction by planet
ID next, shipyard last. Repeat through `now`. Research completed at arrival time
counts for eligibility; same-time cargo can fund activation; research wins
same-planet activation contention; Robotics affects new timers only. New colonies
accrue only after birth. Batch work is bounded by queued batches, not elapsed
seconds or quantity. Stable logical newborn references are
`colony:<fleet-command-token>` until persistence assigns IDs.

The colony climate/field source table is `legacy/includes/PlanetData.php:19–33`;
fleet arithmetic and catalogue provenance is `legacy/includes/classes/class.FleetFunctions.php:20–27,34–64,87–114,135–175`,
`legacy/install/install.sql:244–255,948–959,1157–1210`,
`legacy/includes/classes/PlayerUtil.class.php:567–613`, and the mission/dispatch
paths reviewed for this contract include
`legacy/includes/classes/missions/MissionCaseColonisation.class.php:25–123`,
`legacy/includes/classes/missions/MissionCaseTransport.class.php:24–87`,
`legacy/includes/pages/game/ShowFleetStep3Page.class.php:71–95,126–181,334–404`,
and `legacy/includes/PlanetData.php:19–33`. Source provenance is descriptive;
it does not claim source-runtime parity.
