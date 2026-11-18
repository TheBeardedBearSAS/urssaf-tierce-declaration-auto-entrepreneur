# ADR 0011 — Local guards and opt-outs

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The AE specification states that estimate (040), declare (050) and pay (060) require the third-party declaration
mandate (api-reference §2 "Prerequisites", `MDT_INCONNU` §3.4), that a declared period must match the account
periodicity (`PERIODICITE_INVALIDE`, 050 only, §2.5) and that a SEPA revocation takes effect at today + 1 at the
earliest (§2.8). ADR 0009 delegates to this ADR the guard against a second payment of the same period. Other rules
are server-side only or not published (§5). The project rules forbid inventing a rule: a local check enforces a
published rule, and every local guard that depends on local knowledge has an opt-out. The one exception is the
second-payment guard (item 3): it enforces no published rule but protects against the unpublished duplicate
behaviour of 060 (§5), so it is a safety default with an opt-out, never presented as an URSSAF rule.
Unlike PAJE (whose 020 says nothing about the mandate), AE 020 returns `mandatPresent` (§2.1).

## Decision

1. **Mandate guard (on by default).** `Application\Service\MandateGuard` refuses `EstimateContributions` (040),
   `DeclareTurnover` (050) and `PayContributions` (060) with `MandateRequired` (violation `mandate.required`, parameter:
   the operation number only, never the SIRET) when no active local `Mandate` exists for (tenant, SIRET)
   (`MandateRepository::findActive()`). Active = not `cancelled` (state `notified`, origin `notified` after 030 or
   `imported`, ADR 0009).
   - Opt-out per command: `skipMandateCheck: true`. Global default: `WorkflowPolicy::requireLocalMandate()` = `true`
     (bundle `workflow.require_local_mandate`).
   - A synced `AeAccount` with `mandatPresent = true` does **not** satisfy the guard: it is a snapshot that never
     moves a `Mandate` (ADR 0009), and the platform's own record is what 031 and the erasure rules rely on. An AE
     mandated outside the package passes after `ImportMandate` (`urssaf:tdae:mandate:import`), which records the
     mandate without calling URSSAF.
   - The guard runs before any record is created or claimed, and only on the paths that send.
   - `MDT_INCONNU` from URSSAF is always reported as `mandate_missing`, whatever the guard said.
2. **Periodicity guard (050 only).** When the `AeAccount` of the SIRET has been read (020) and its `Periodicity` is
   known, `DeclareTurnover` refuses a `MonthlyPeriod` for `T` or a `QuarterlyPeriod` for `M` with `ValidationFailed`
   (`declaration_period.periodicity_mismatch`, path `period`). When the account was never read, nothing is checked and
   the server answers `PERIODICITE_INVALIDE`. Opt-out per command: `skipPeriodicityCheck: true` (the periodicity may have
   changed since the last read). Not applied to 040 (no period input) nor to 060 (`PERIODICITE_INVALIDE` not published
   for 060, §2.6, §5).
3. **Second payment of a period (060).** The package cannot tell an intended second payment from a duplicate
   (§2.6, §5: duplicate behaviour not published). `PayContributions` with a new `PaymentReference` for a
   (tenant, SIRET, period) that already has a current `PaymentRecord` in `submitting`, `paid` or `outcome_unknown`
   (ADR 0009) is refused before any record is created with `ValidationFailed` (`payment.period_already_paid`, path
   `period`, no SIRET in the parameters). Opt-out per command: `allowAdditionalPayment: true` (a balance paid in two
   orders, a payment after a corrected declaration). The same `PaymentReference` is the re-entry table of ADR 0009,
   not this guard.
4. **Revocation date (071).** `RevokeSepaMandate` refuses a `dateEffet` earlier than today + 1 in the business time
   zone (`ApiConfiguration::businessTimeZone()`, bundle `business_timezone`, ADR 0001) with
   `sepa_revocation.effective_date_too_early`, before any call. Opt-out per command: `skipEffectiveDateCheck: true`
   (a host clock or time zone that differs from URSSAF's). An omitted `dateEffet` is allowed and not sent (its
   default is not published, §2.8, §5).
5. **Local validation = published bounds only** (api-reference §2, §4):
   - `Siret`: exactly 14 digits (§4.1, ADR 0008); `Rum`: 1 to 35 characters (§2.6, §2.7, §2.8);
   - `DeclarationPeriod` (ADR 0004): `mois` 1–12, `trimestre` 1–4, never both, `annee` 1–9999 (the `CalendarDate` range, ADR 0004; §2.5, §3.2);
   - turnover lines (`CaAe`) and the 060 `montant`: whole euros, int32, not negative (§1.2, §4.4); at least one turnover
     line is not required (all optional in `CaAe`); no minimum or maximum amount (§2.6: not published);
   - 070: `bic` ≤ 11, `iban` ≤ 34, `titulaireCompte` ≤ 80, `adresse1` / `adresse2` ≤ 32, `codePostal` ≤ 5, `pays` ≤ 80,
     `ville` ≤ 26, required fields present (§2.7). No pattern for `bic`, `iban`, `codePostal` (none published, §5);
   - 060 `iban`: no length or pattern (none published there, §2.6); `iban` and `rum` both optional, no combination rule
     enforced (§4.10, §5);
   - dates: strict `yyyy-MM-dd` (ADR 0001); no range on the birth date.
   Input factories collect every violation at once (`ValidationFailed`, ADR 0000 item 4).
6. **Server-side only** (documented, never checked locally): open period (`APPEL_INCONNU`), mandate validity window
   (`MDT_HORS_PERIODE`), the first-declaration schedule after the start of activity (§3.5; `date_etat` is PDF-only),
   birth-date match on 030 / 031, single platform per SIRET (`SIRET_DEJA_MANDATE`, ADR 0012), whether 060 needs a
   registered SEPA mandate, the relation between the paid amount and the declared amount, IBAN checksum and bank
   referentials (`ERREUR_IBAN_INVALIDE`, `ERREUR_RIB_INVALIDE`, `BANQUE_INCONNUE`), duplicate SEPA mandate (409).

## Consequences

- A local refusal never reaches URSSAF and never creates a record; the package never refuses a request the contract
  accepts, except through a guard that has an opt-out.
- A host that tracks mandates elsewhere sets `workflow.require_local_mandate: false` or imports its mandates.
- When the sandbox settles a point of §5, only a default or a server-side entry changes.

## Amendment (2026-11-18, lead-accepted, lot 4a)

- **Item 4: "today".** The Application reads "today" from `WorkflowPolicy::businessTimeZone()` / `today()`.
  `ApiConfiguration` lives in Infrastructure, and Deptrac forbids Application to Infrastructure. Both are fed from the
  same bundle key `business_timezone` (default `Europe/Paris`), so there is one setting and two readers (reference and
  PAJE shape).
