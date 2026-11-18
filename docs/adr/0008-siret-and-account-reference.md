# ADR 0008 — SIRET identification and the account reference

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The AE operations identify the account by `siret` or `nir` (api-reference §2 common table): both are optional in the
contract, sending both is `ERR_PARAM_MULTIPLES`, sending none is `PARAM_INVALIDE` (§1.2). The PDF states that "le SIRET
est nécessaire au bon fonctionnement du service" and flags `nir` as *déprécié* (§1.2, §4 decision 1). Lengths differ:
`siret` max 14 on 020 – 060, exactly 14 on 070 / 071 / 075 (§2.7 – §2.9); `nir` 13 on 020 – 060, 13 – 15 on the SEPA
operations (§5). No SIRET checksum is published. 030 and 031 also require `dateNaissance`, checked by URSSAF against
the birth date it holds (§2.2, §2.3). 070 carries `siret` in the body (§2.7), 071 in the path (§2.8), the others in the
query. For an AE, a natural person, the SIRET identifies the person; it is not a secret (§4 decision 11).

## Decision

- `Domain\Account\Siret`: `final readonly`, `Siret::fromString(string)`, exactly 14 ASCII digits (`^\d{14}$`), else
  `InvalidValue` `siret.invalid_format` (the raw value never in the message). No trimming, no removal of spaces, and
  **no Luhn check**: INSEE's key is not part of the URSSAF specification and the package does not invent it.
  `toString()` gives the wire value; `equals()`.
- Masking: `Siret::__debugInfo()` shows `**********1234` (last four digits) so that dumps and exception traces do not
  spread it; the SIRET is kept out of log context by default (opt-in and the rest of the personal-data policy in
  [ADR 0010](0010-personal-data.md)).
- `Domain\Account\AccountReference`: `final readonly`, `AccountReference::of(Siret, CalendarDate $birthDate)`, used by
  030 and 031 only. The birth date is a `CalendarDate` ([ADR 0001](0001-date-only-encoding.md)) marked
  `#[SensitiveParameter]` and masked in `__debugInfo()`; no local plausibility rule (age, future date) is added, since
  none is published: URSSAF checks it (mismatch code *not published*, §5, [ADR 0005](0005-error-classification.md)).
- Every other operation takes a `Siret` only: 020, 040, 050, 060 in the query (`siret=`), 075 in the query, 070 in the
  body (`MandatSepaToAdd.siret`), 071 in the path (`/sepa/mandats/{siret}/{rum}`, encoded per
  [ADR 0002](0002-http-client-adaptations.md)).
- **NIR is never supported**: no `Nir` value object, no `nir` key ever written by a request mapper, so
  `ERR_PARAM_MULTIPLES` cannot be produced. A `siret` echoed by a 070 / 075 response is read into a `?Siret`; an
  invalid echoed value is `UnexpectedUrssafResponse` on 075 (safe) and kept out of the result on 070 rather than
  turning an accepted registration into an unknown outcome (the request SIRET is authoritative).
- The SIRET is the account key of every local aggregate ([ADR 0009](0009-local-lifecycles.md)): `(TenantId, Siret)`.
  The birth date is stored with the tracked account for 030 / 031, never part of a key.

## Consequences

- A host holding only a NIR cannot use the package; this follows the PDF deprecation.
- A 14-digit value with a wrong INSEE key is sent and answered by URSSAF (`PARAM_INVALIDE` or `CPTE_INCONNU`), which
  stays the only judge of validity.
- If URSSAF publishes a checksum rule, `Siret` gains it by amendment.
