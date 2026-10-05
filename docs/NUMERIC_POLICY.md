# Economy numeric and persistence policy

**Status:** approved and implemented technical contract for the first playable
resource-economy milestone, accepted through Phases A and B and exercised by
Phase C automated acceptance. This describes the current local implementation;
it is not a production-readiness or full-WCAG-audit claim. It applies with
the source-derived rules in [ECONOMY_RULES.md](ECONOMY_RULES.md).

## Authoritative arithmetic

- Use `Brick\Math\BigRational` for all authoritative balances, costs, rates,
  energy demand/allocation, and formula intermediates; use `BigInteger` for
  integral calculations and checked timestamp arithmetic.
- Do not use PHP floats or native floating-point arithmetic in authoritative
  calculations. Decimal source constants are exact rationals: `1.1 = 11/10`,
  `1.5 = 3/2`, and `1.8331954764 = 18331954764/10000000000`.
- Keep elapsed accrual as exact `rate * seconds / 3600`. Saves, reloads,
  deductions, queue activation, and partitioned settlements do not round or
  quantize balances. Negative balances are invalid.
- All economy settings that affect rates, prices, energy, or duration are fixed
  for this milestone, including while a planet has a nonempty queue. A later
  setting change requires a versioned rule/schema migration and an explicit
  policy for settling existing balances and queues.

### Sole authoritative rounding points

1. Generated solar energy rounds to the nearest integer using HALF_UP for the
   nonnegative generated amount.
2. Storage capacity applies FLOOR to the storage expression before multiplying
   by 5000.
3. Construction duration applies FLOOR to calculated seconds, then the configured
   minimum duration (1 second for this milestone).

Mine/solar operating percentages, energy demand, energy allocation factors,
resource rates, costs, and balances remain exact rationals. Display formatting is
not authoritative: six fractional decimal places may be used as a default
presentation precision, but small fractional values are approximations there.
The UI may show additional decimal places where needed to make a cost legible;
display precision and presentation are subject to the designer's decisions.
Neither displayed values nor display rounding feed payment, affordability,
storage, or persistence.

## Canonical serialization

Serialize each authoritative rational as one UTF-8 ASCII string in reduced
`numerator/denominator` form. Numerator is a signed base-10 integer; denominator
is a positive base-10 integer. The strict lexical grammar is:

```text
value       := numerator "/" denominator
numerator   := "0" | positive-integer | "-" positive-integer
positive-integer := nonzero-digit *digit
denominator := positive-integer
digit       := "0" | nonzero-digit
nonzero-digit := "1" | "2" | ... | "9"
```

Additionally, numerator and denominator must have greatest common divisor 1;
zero has exactly the representation `0/1`. Thus leading zeroes, `+`, whitespace,
negative denominators, `-0/1`, decimal notation, unreduced fractions, and
denominator zero are invalid. Negative numerators are permitted for intermediate
signed rates where the domain requires them, but never for resource balances,
prices, or costs.

On load, strictly validate grammar, canonical reduction, positivity constraints,
and domain range before constructing/using a rational. Any malformed or
noncanonical persisted value is an integrity error: reject/roll back the
operation; never coerce it, normalize silently, or fall back to zero. Re-encode
stored values in the same canonical form. Numerator/denominator strings must not
pass through PHP floats, native integer casts, or SQL numeric comparisons.

The current additive migration stores three string-backed `LONGTEXT` columns,
one canonical rational string per resource balance. `DECIMAL(65,6)` is not
authoritative: it cannot represent general repeating rationals exactly. The
migration and local schema validation passed; this does not make the local
database a production deployment target.

## Range, admission, and timestamp guards

- Preserve the reviewed catalogue maximum level 255 and the approved field
  limits. Do not invent a lower level cap to fit a numeric column or shorten
  long builds.
- Before admission or payment, calculate the exact cost, duration, projected
  activation/completion timestamps, and field reservations. Use big integers
  while calculating; verify signed-BIGINT and native 64-bit epoch-second
  representability before conversion or persistence. Reject unrepresentable
  construction with a typed technical-range error and no partial mutation or
  payment. Never clamp values silently.
- The queue admits at most five pending entries including the active head.
  Waiting timestamps are estimates assuming earlier entries succeed; only the
  active entry has an authoritative completion timestamp. With milestone
  settings immutable, validate projected times at admission.
- An equal timestamp accrues zero seconds but still processes transitions due at
  that timestamp. A timestamp earlier than the last settled time is a clock
  regression error with no state change.

## Approved economy context

The default game speed is 2500; resource, storage, and energy multipliers are 1.
Each newly registered home planet starts at maximum temperature 40°C as the
approved equal-start policy (an intentional difference from variable legacy
planet climates), with initial resources 500/500/0, home fields 163, all seven
building levels 0, and production percentages 100%. Basic hourly incomes are
20/10/0. The first milestone has one planet per player, seven buildings, and
the five-entry construction queue described in the economy contract. Eventual
v1 breadth remains undecided.
