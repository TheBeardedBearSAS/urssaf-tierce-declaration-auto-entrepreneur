# ADR 0004 — Sum types

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

`Periode` is a monthly **or** a quarterly period: `annee` is required, `mois` `[1..12]` and `trimestre` `[1..4]` are
optional and "ne peuvent être renseignés simultanément" (api-reference §2.5, §3.2). The rule is stated in prose only:
the Swagger has no `minimum`, `maximum`, `enum` or `oneOf` (§2.5, §5). The account periodicity `periodicite` is `M` or
`T` (§2.1, §3.1), and a period that does not match it is answered `PERIODICITE_INVALIDE` (§2.5). `Periode` is shared by
the 050 and 060 requests and the 040, 050 and 060 responses. api-reference §4 decision 5 proposes a sum type.
PHPStan `ergebnis.noExtends` forbids class inheritance outside justified extension points; CESU and PAJE use
interfaces with final implementations.

## Decision

- `Domain\Period\Periodicity` is a backed enum: `Monthly = 'M'`, `Quarterly = 'T'`, with `fromCode(string): ?self`
  (an unknown code read from 020 is `UnexpectedUrssafResponse`: the field is required and closed, §2.1).
- `Domain\Period\DeclarationPeriod` is an interface: `year(): int`, `periodicity(): Periodicity`,
  `equals(DeclarationPeriod): bool`, `toString(): string` (`2019-12`, `2019-Q3`, for logs and keys).
- Implementations, `final readonly`, private constructor, named constructor `of(int $year, int …)`:
  - `MonthlyPeriod(year, month)`: month 1–12, else `InvalidValue` `period.month_out_of_range`;
  - `QuarterlyPeriod(year, quarter)`: quarter 1–4, else `InvalidValue` `period.quarter_out_of_range`.
  The year is checked 1–9999 (`period.year_out_of_range`), the range of the reference `CalendarDate`: no narrower
  rule is published (§5). "Both set" and "none set" are unrepresentable.
- The wire mapper (`Wire\PeriodMapper`) writes `{"annee": int, "mois": int}` or `{"annee": int, "trimestre": int}`
  and reads integers or numeric strings ([ADR 0006](0006-two-contracts-one-model.md)). A response carrying both
  `mois` and `trimestre`, neither, or an out-of-range value is `UnexpectedUrssafResponse`.
- Consumers branch with `match (true) { $p instanceof MonthlyPeriod => …, $p instanceof QuarterlyPeriod => …,
  default => throw InvariantViolation::… }`; the `default` arm is kept for exhaustiveness and covered by a test double
  in tests only.
- Comparing a period with the account periodicity is `DeclarationPeriod::periodicity() === Account periodicity`; the
  local refusal before a call (only when a 020 snapshot is known, §4 decision 5) is a guard of
  [ADR 0011](0011-guards-and-opt-outs.md), never a rule of the value objects.
- The 040 response period is derived by URSSAF (§2.4): it is read into a `DeclarationPeriod` and returned with the
  estimate, never compared with anything locally.

## Consequences

- No `extends` in the domain; adding a period kind means updating every `match` (caught by tests and Infection).
- The gateway port keeps one method per operation; a period travels as a `DeclarationPeriod` and only the mapper knows
  the `mois` / `trimestre` keys.
- Persistence stores the period as periodicity + year + number (ADR 0016), so the sum type is rebuilt without a
  discriminator class name.
