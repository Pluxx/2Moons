# Colonization and transport UX brief

## Purpose and implementation boundary

Extend the accepted 2Moons command console into an earned colony and owned-planet transport loop: develop facilities/research → build a colony ship → colonize an eligible open position → transport resources between owned planets. This is not a free second-planet grant and does not approve combat, PvP, expedition missions, or other fleet features.

This is a design brief, not a route, controller, or view-model contract. Use server-rendered Symfony/Twig forms and progressive enhancement. Backend owns values, permissions, quotes, queues, arrival effects, and mission completion. View names, event ordering, arrival eligibility, and race/failure policies still need review; do not guess routes, variables, model fields, or entity shapes.

## Shared interface and page hierarchy

Keep the accepted ink-blue shell, ivory panels, restrained copper actions, tabular resource values, serif headings, existing resource band, and separate energy balance. Add only useful destinations: owned-planet overview/selector, research, shipyard, fleet dispatch/review, and mission history. No dead or future combat, galaxy-fight, alliance, expedition, or unsupported-feature links.

On the planet overview, distinguish selected-planet data from account-wide research. Planet context may show backend-supplied name, coordinates, actual temperature, fields used/total/reserved, resources, power, local construction, and local shipyard batches. Identify the homeworld's fixed 40°C policy; colony climate and fields vary by destination/creation rules. Do not imply colonies share homeworld conditions. A compact selector switches only among owned planets.

### Facilities and research

Add Robotics Factory, Shipyard, and Research Laboratory beside the seven economy buildings. Reuse the established building-card hierarchy: level, target level, backend-computed cost/time, enabled action or nearby explanatory reason. Facility levels, queue, and field use belong to the planet; research levels belong to the account. Show the research source planet/laboratory and make the two-entry account research queue distinct from each planet's construction/shipyard queue.

Present this source-backed progression for middle-position colonization followed by transport as a readable prerequisite checklist, not legacy IDs:

- Robotics Factory 2; Shipyard 4; Research Laboratory 3.
- Energy Technology 1; Combustion Engine Technology 2; Impulse Engine Technology
  3; Spy Technology 3; Expedition Technology 1.
- One Colony Ship (208). A Small Cargo Ship (202) is an optional escort.

Use the verified name **Expedition Technology**, never “Astrophysics.” Its level 1 is required for ordinary middle-slot colonization even though expedition missions are out of scope. Show backend-supplied prerequisite closure, costs, times, and unmet reasons; do not invent bonuses or formulas. Position gates are distinct from planet-count limits. Whether requirements are checked on launch, arrival, or both remains an architecture/product decision.

This progression has meaningful costs: keep actual balance, hourly rates, storage capacities, and research prices visible. If the server quote exceeds a resource's current storage capacity, identify the relevant storage constraint and useful existing storage upgrade without implying an automatic purchase or inventing a prerequisite.

### Shipyard and owned ships

Offer only Small Cargo Ship and Colony Ship. Show owned inventory separately
from ships in production or committed to flight. Each planet's shipyard has a
FIFO queue of at most ten pending batches; a batch specifies ship type and whole
count from 1 to 1,000,000. Show server-computed total cost and timing for the
selected count. Costs are prefunded at admission; explain this before
confirmation. Show active/waiting batch, quantity, backend timing, and why an
action is disabled. No cancel/reorder controls. Fleet forms cannot select more
ships than the source planet's available inventory. Do not present unverified
catalogue fields as confirmed behavior.

### Destination and colonization

Use a short, labelled galaxy/system/position form—not a 54,000-slot map. Bounds
come from actual universe settings; source defaults are 9 galaxies, 400 systems,
15 positions, not hard-coded UI constants. A reasonable default selection is
position 8. Show occupancy/availability only from a server check and state
clearly that it does **not** reserve the position; another player may claim it
before arrival. The server rechecks at the approved event boundary. Never
promise first-come priority or silently reserve an open slot.

For the default 15-position universe, Expedition Technology minimums are:
positions 1/15: level 8; 2/14: level 6; 3/13: level 4; 4–12: level 1. Render
only configured positions (never position 16 when max is 15) and explain the
specific unmet requirement. Ownership limit and UI policy remain pending; do not
invent a lower cap or officer bonuses.

Before dispatch, review source, destination, colony ship, optional escort counts,
estimated duration, server-computed fuel, and cargo. Explain that successful
colonization consumes one colony ship. On success, show the actual new
coordinates, generated climate/fields, and starting resources; this milestone's
colony starts with 500 metal / 500 crystal / 0 deuterium plus carried cargo. Any
remaining escort ships return with empty cargo. On failure, ship and cargo
return, but spent fuel is not refunded. Do not display a colony before the
server confirms success.

### Owned-planet transport and mission history

Transport goes only between planets owned by the signed-in player. The player
explicitly selects source and destination from owned planets; no arbitrary
coordinates, foreign owner, or foreign recipient. Show which planet supplies
ships, cargo, and fuel; distinguish cargo from fuel and ships in flight.

Use a server-generated review/quote before confirmation. It reflects selected
source/destination, ship counts, whole-unit cargo, speed (10–100% in 10-point
steps), estimated flight time, fuel, and remaining capacity after fuel. Recheck
at confirmation; a stale quote cannot authorize spending. Never trust client
prices, fuel, capacity, duration, or outcome. Reject fractional cargo inputs
with useful field errors rather than rounding them up. Exact cargo limits,
display precision, and transport storage-cap behavior await backend contract.

Show owned missions with backend-supported states (outbound, arriving,
returning, complete, failed), origin/destination, purpose, ships, loaded cargo,
fuel spent, and server timing/outcome. Distinguish delivery from ships' return.
No recall, cancellation, reorder, colony destruction, or combat controls unless
separately approved.

## Interaction, feedback, and accessibility

- Use labelled server-rendered forms, a visible dispatch review, CSRF,
  authorization, and server revalidation. Progressive enhancement only; no
  client game math or authoritative client simulation.
- Explain prefunding and queue scope. Insufficient resources and insufficient
  energy are different states. A stale quote returns the player to a fresh
  review; never silently reuse obsolete amounts.
- Confirm only a server-accepted dispatch. Countdown/arrival estimates are
  explicitly approximate; manual refresh is safe. No browser completion,
  automatic POST, or resource/planet grant.
- Associate concise field errors, provide a summary/focus target, and explain
  occupied coordinates, missing requirements, insufficient stock, stale quote,
  and ship/cargo limits without exposing internals or promising availability.
  No admin cheats, default accounts, or invented auth policy copy.
- Preserve semantic headings, keyboard order, visible focus, non-color-only
  status, reduced motion, descriptive units, and mobile tap targets. Stack
  review and queue context on narrow screens; keep source/destination, fuel,
  cargo, and mission status together without horizontal scrolling or hover-only
  details.

## Evidence boundary

Use the accepted visual reference in [UX_BRIEF.md](UX_BRIEF.md). The source
review is not yet a frozen backend contract. Coordinate races,
ownership cap, random colony setup, arrival eligibility, event order, stale
quotes, fuel/travel rounding, destination deletion, and transport overflow need
architecture/numeric review before implementation. Preserve source uncertainty;
do not use unreviewed legacy artwork.
