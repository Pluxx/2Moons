# First playable economy: UX brief

## Product intent

Make the first loop legible and satisfying: create an account, arrive at one
working planet, understand its resources and power, choose a building, and see
the world continue to advance when revisited. Keep the original 2Moons strategic
space-game character—patient planning and meaningful trade-offs—without copying
legacy layouts or implying that later fleet features already exist.

Use Symfony-rendered Twig pages, with progressive enhancement and no SPA
framework or npm requirement. Backend owns values, permissions, FIFO queue
processing, construction completion, and elapsed-world advancement; no browser
timer may become authoritative. Templates follow the shared Phase B view
contract.

## Page and information hierarchy

### Sign in and registration

- Calm, focused account forms with a short plain-language explanation of the
  game, not a promotional landing page. Keep the form the visual anchor.
- Include only the fields and policy text required by the eventual auth design;
  do not invent a username/password policy in the UI brief. Link sign-in and
  registration clearly; preserve entered values where safe after validation.
- Show field-level errors beside their fields and a concise summary at the top.
  Associate labels, help, and errors programmatically. Focus the summary or
  first invalid field after a server response. Never reveal whether an account
  exists in a way that undermines the backend's chosen security policy.
- Provide visible focus, keyboard access, password-manager-friendly inputs, and
  an accessible show-password control if one is implemented. These are design
  requirements, not claims about the current authentication system.

### Planet overview (primary playable page)

1. **Context:** planet name and actual maximum temperature. Do not invent
   coordinates or unsupported planet editing.
2. **Resources:** metal, crystal, and deuterium stock; current hourly rate; and
   storage capacity for each. Show the initial state accurately: 500 / 500 / 0
   stock, 20 / 10 / 0 per hour, and level-zero capacity 10,000 each. These are
   starting-contract values, not examples of unlimited inventory.
3. **Power:** generated energy, mine demand, and a clearly named production
   factor or equivalent plain-language status. Distinguish adequate power from
   constrained mine production. At zero mines, demand is zero; do not label that
   as a power failure. Energy is a production budget, not a stockpile.
4. **Construction:** show the active job prominently, followed by the FIFO
   waiting queue (at most five entries including the active head). The active
   job has a due-time countdown; waiting entries have estimates only. Queued
   costs are charged only when a build starts. A waiting build that cannot be
   paid for at activation fails then and does not block later entries. No cancel,
   reorder, or demolition controls are presented.
5. **Buildings:** seven distinct cards, ordered by economy usefulness: metal
   mine, crystal mine, deuterium synthesizer, solar plant, then metal, crystal,
   and deuterium storage. Each card shows current level, next level, next price
   split by resource, build duration, and a clear build action. If no next level
   is available under configured rules, explain that state rather than showing
   a misleading enabled button. The catalogue for this milestone has no seeded
   prerequisites; do not invent prerequisites.
6. **Planet development:** fields used / total (the source-derived home example
   is 0 / 163 initially, subject to the actual created planet data). This is
   context, not an implied limit on this seven-building milestone.

Use a compact resource strip on wide screens, then a two-column working layout:
queue/status alongside the building catalogue. On narrow screens,
reorder to context → resources → queue → buildings; never require
horizontal scrolling for prices, labels, or actions. Cards may collapse details
only if the essential cost and action remain discoverable.

## Decision and feedback states

- **Affordable:** enable the build action and state the exact next-level cost
  and duration before commitment.
- **Insufficient resources:** disable or reject the action server-side and name
  each shortfall (current balance versus required amount). Do not conflate this
  with insufficient energy: energy affects production, not whether a cost can
  be paid, unless an independently established rule says otherwise.
- **Power constrained:** explain that mines still operate at a reduced rate;
  show generation, demand, and resulting production impact. Do not imply all
  production stops or treat energy as spendable inventory.
- **Storage full/near full:** indicate the affected resource and capacity, and
  explain that positive accrual stops at capacity. Storage upgrades are an
  understandable remedy, not an automatic purchase.
- **Build in progress:** communicate the active construction and FIFO waiting
  entries. Waiting completion estimates assume earlier entries succeed; mark
  them as estimates. Explain queued payment-on-start and activation failure in
  plain language. Do not add cancel/reorder controls.
- **Empty/error states:** if account/planet data is unavailable, show a useful
  recovery action without fabricating a planet. For an unexpected request
  failure, retain a safe page context and offer retry; never claim a build
  succeeded until the server confirms it.
- **Feedback:** after a successful action, confirm the building and target level,
  updated resource state, and due time. Announce status changes accessibly.
  Refresh/reload remains a reliable way to reconcile state.

## Interaction and accessibility

Use real links for navigation and forms/buttons for actions. Make the full
building action a generous target; do not make a whole card an ambiguous click
target. Preserve server-side validation as the source of truth. Use semantic
headings, landmarks, labelled progress/status text, sufficient contrast, visible
keyboard focus, and status announcements that do not steal focus. Do not encode
resource or energy status by color alone. Respect reduced-motion preferences.

Prefer a quiet, purposeful transition for confirmed feedback over ambient
animation. If a countdown is enhanced in the browser, label it approximate,
derive it from server-sampled time and due timestamps, and never trigger
completion or grant resources client-side. Reload remains a safe reconciliation
path.

## Copy and implementation guardrails

Use direct labels: “Metal”, “Crystal”, “Deuterium”, “Energy generated”, “Energy
demand”, “Production”, “Storage”, “Build level 2”. Explain unfamiliar terms in
short supporting text. Avoid marketing claims, invented bonuses, fake alerts,
fictional routes/API shapes, or claims that registration security controls
already exist. Native registration, password handling, CSRF protection,
validation, and authorization must be provided and reviewed by the backend;
visual affordances do not establish them.

Economy figures must come from the approved economy contract and actual persisted
world state. The initial resource/rate/capacity values above describe the stated
new-planet contract. New planets in this milestone have a maximum temperature
of 40°C as an equal-start policy; display the actual supplied value.
Construction prices and duration vary
by target level and settings, so render computed backend values rather than
hard-coding examples. Persisted precision/fractional carry remains unresolved;
do not round balances or costs in a way that changes affordability.

The visual direction is a quiet command console: deep ink-blue navigation,
warm ivory data panels, restrained copper actions, and clear tabular numerals.
Keep all essential information readable without color, motion, or JavaScript.
