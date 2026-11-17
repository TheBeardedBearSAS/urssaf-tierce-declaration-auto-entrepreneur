# ADR 0001 — Date-only encoding, `date_etat` and business time zone

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference sends calendar dates as `YYYY-MM-DDT00:00:00Z`. Every date of the AE contract is a pure calendar date
(api-reference §4 decision 7):

- `dateNaissance` (query of 030 / 031, `format: date`, `yyyy-MM-dd`, §2.2, §2.3);
- `dateEffet` (query of 071, `yyyy-mm-dd`, optional, minimum **today + 1**, default when omitted *not published*,
  §2.8, §5);
- `dateExigibilite` (responses of 050 and 060, `format: date`, §2.5, §2.6);
- `date_etat` (response of 020, affiliation date): documented in the PDF only, in **`DD/MM/YYYY`**, absent from both
  Swagger contracts (§2.1, §5).

The API carries no instant. "Today" in the `dateEffet` rule is not qualified by the specification; URSSAF is a French
administration and the PDF reasons in French calendar months (§3.5).

## Decision

- `Domain\Shared\CalendarDate` (year, month, day; `checkdate`) is the only date type of the domain and the wire. A
  birth date is a `CalendarDate` (no `BirthDate` value object, ADR 0000).
- `Infrastructure\Urssaf\Wire\DateEncoding`:
  - `encode(CalendarDate)` writes strict `Y-m-d` (`dateNaissance`, `dateEffet`);
  - `decodeIsoDate(string)` reads strict `Y-m-d` (`dateExigibilite`): a time part, an offset, another separator or any
    other text is refused with `UnexpectedUrssafResponse`;
  - `decodeFrenchDate(string)` reads strict `DD/MM/YYYY` (two-digit day and month, four-digit year, `checkdate`) and is
    used for `date_etat` only. No other format is guessed: a malformed `date_etat` makes the 020 answer unreadable
    (`UnexpectedUrssafResponse`), as any other malformed field. An absent `date_etat` is `null` (ADR 0006).
- `Domain\Shared\Instant` (UTC, second precision) is used for local tracking only: created / updated / claimed /
  synced at, domain and integration events, outbox. It is never sent to URSSAF.
- **Business time zone.** `ApiConfiguration::businessTimeZone()` (default `Europe/Paris`, bundle key
  `business_timezone`, validated as a known IANA identifier at construction / container build) converts the PSR-20
  clock instant into the business calendar date. It is used:
  - by `RevokeSepaMandate`: a `dateEffet` given by the host must be **after today** in the business time zone
    (`sepa_revocation.effective_date_too_early`, before any call). This is a local guard of the published rule, with
    the opt-out of [ADR 0011](0011-guards-and-opt-outs.md); without `dateEffet` the parameter is omitted and URSSAF
    applies its own (unpublished) default;
  - to present instants as business dates (operator views, the Symfony scheduler).
- The package derives no other rule from "today": the period opening (`APPEL_INCONNU`), the mandate validity
  (`MDT_HORS_PERIODE`) and the first-declaration schedule (§3.5) are left to URSSAF.

## Consequences

- No `T00:00:00Z` on the wire; the reference `DateEncoding` is not reused as is.
- A 050 / 060 answer carrying an invalid `dateExigibilite` cannot be read: it becomes an unknown outcome
  ([ADR 0003](0003-retry-and-unknown-outcome.md)), never a silent success.
- A host running near midnight in another time zone gets the French "today"; a host that needs another reference
  changes `business_timezone`.
- If the contract test shows another `date_etat` format, only `decodeFrenchDate()` and its fixtures change.
