# ADR 0009 — Local lifecycles of accounts, mandates, declarations and payments

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference drives its records through explicit state machines (claim, attempts, polling of an URSSAF status). The
AE API has no status endpoint: only 020 can be read again (`mandatPresent`, `periodicite`, PDF-only `date_etat` and
`exoAccrePresent`, api-reference §2.1). 050 is last-wins on an open period (`certificat` stable, `idDeclaration`
changes, §1.2, §2.5); 060 has no published idempotency (§2.6, §5); 070 / 071 / 075 manage SEPA mandates that URSSAF
lists (§2.7 – §2.9). The roadmap asks for tracking of mandates, declarations and payments "needed to reconcile payments
whose outcome is unknown". This ADR is the single source of the states, transitions and settlement rules;
[ADR 0003](0003-retry-and-unknown-outcome.md) refers to it.

## Decision

**Aggregates** (each with `TenantId`, repository interface in the Domain, optimistic concurrency in the repository
contract: `save()` of a stale copy is `<subject>.concurrent_modification`):

- `Domain\Account\AeAccount`, unique `(tenant, Siret)`: optional birth date (`CalendarDate`, given to `TrackAccount`,
  `NotifyMandate` or `CancelMandate`, [ADR 0010](0010-personal-data.md)), the last `AccountStatus` snapshot of 020
  (`mandatePresent`, `Periodicity`, `?affiliationDate`, `?acreExemption`, [ADR 0006](0006-two-contracts-one-model.md))
  and `lastSyncedAt` (`Instant`). `TrackAccount` (020, then the account), `SyncAccount` / `SyncAccounts` (020 only:
  the only refresh). A refresh never moves a `Mandate`: `mandatPresent` is a snapshot the host compares.
- `Domain\Mandate\Mandate` (third-party declaration mandate), at most one non-cancelled per `(tenant, Siret)`:
  `MandateState` `notified` → `cancelled`; `MandateOrigin` `notified` (030 accepted) | `imported` (no call). Commands:
  `NotifyMandate` (030), `ImportMandate` (none), `CancelMandate` (031, refused before any call without a local active
  mandate: `mandate.not_found`), `RecordMandateCancellation` (none). An active local mandate makes `NotifyMandate` /
  `ImportMandate` idempotent (no call). The call is made outside any transaction; a rejection (`SIRET_DEJA_MANDATE` is
  kind `mandate_held_elsewhere`), an unknown outcome or a temporary failure records nothing. Once URSSAF accepted 030 /
  031, `MandateRecorder` records the transition without another call (at most 3 attempts, each re-reading account and
  mandate in a new unit of work); **`MandateRecordingFailed` (`mandate.recording_failed`) is the only exception of the
  mandate commands that means the call was accepted**, recovered call-free with `ImportMandate` / `RecordMandateCancellation`.
- `Domain\Declaration\DeclarationRecord`: one **current** record per `(tenant, Siret, DeclarationPeriod)`.
  `DeclarationFingerprint` = SHA-256 of tenant, SIRET, period and the five turnover lines (absent ≠ `0`). Once
  `declared` it keeps a `DeclarationResult`: `certificat`, `idDeclaration`, `dateExigibilite`, `ContributionAmounts`.
- `Domain\Payment\PaymentRecord`: unique `(tenant, PaymentReference)`, the host's idempotency key, because the package
  cannot tell an intended second payment from a duplicate (§5). Snapshot: SIRET, period, `montant` (`Euros`), optional
  `rum`, `PaymentFingerprint` (tenant, SIRET, period, montant, rum, whether an IBAN was given). The IBAN is passed to
  060 and **never stored**, hashed or put in an event. Once `paid` it keeps a `PaymentResult`: `certificat`, optional
  `idTelepaiement`, `dateExigibilite`, period.
- **No SEPA aggregate.** `RegisterSepaMandate` (070), `RevokeSepaMandate` (071) and `ListSepaMandates` (075) are
  pass-through; 070 / 071 success write an integration event carrying tenant and `rum` only
  ([ADR 0014](0014-integration-events.md)). `EstimateContributions` (040) is a query: it records nothing.

**States** of `DeclarationRecord` (success `declared`) and `PaymentRecord` (success `paid`): `pending` → `submitting`
(atomic claim, `attempts + 1`) → `declared`/`paid` | `rejected` (errors kept as `SubmissionError`: code, `RejectionKind`
value, message, description) | `outcome_unknown` (reason `may_have_reached_urssaf` or `stale_submission`) | `error`
(a retryable failure: proven not sent, or, for 050 only when listed in `retry_safe_writes`, possibly sent; back to
`pending` while `attempts < workflow.max_submission_attempts`, default 5, else outcome `exhausted`) |
`invalid_credentials` (requeued by `RequeueAfterInvalidCredentials`). Also `outcome_unknown → declared / paid` and
`→ pending` (`ResolveDeclarationOutcome::asDeclared(certificat)` / `ResolvePaymentOutcome::asPaid(certificat)`,
`::asNotReceived()`), and late acceptance (below). A resolved result holds the `certificat` read by the operator only
(other fields `null`: nothing else is shown reliably, §2.5); a blank or non-`^\d{8}$` certificat is refused. Any other
transition is `InvalidStateTransition`.

**Re-entry** (`DeclareTurnover`, `PayContributions`; returned `ItemOutcome`):

| Current record | Same fingerprint | Different fingerprint |
|---|---|---|
| none | created, claimed, sent | — |
| `pending`, `error` with attempts left | sent | declaration: superseded; payment: `PaymentAlreadyRecorded` |
| `error`, attempts exhausted | `exhausted` (no call) | as above |
| `submitting` | `not_claimed` (no call) | `not_claimed` (no call) |
| `declared`/`paid`, `outcome_unknown`, `invalid_credentials` | `replayed` (no call) | declaration: superseded; payment: `PaymentAlreadyRecorded` |
| `rejected` | superseded, sent | superseded, sent |

Superseding creates a new record linked to the previous one (`supersedes` / `supersededBy`); the previous one keeps its
state and stops being current. For a declaration this is URSSAF's last-wins rule: the new call replaces the previous
declaration of the open period. For a payment, supersession only follows a rejection; a second payment of the same
period needs a new `PaymentReference` (local guard: [ADR 0011](0011-guards-and-opt-outs.md)).

**Settlement.** The claim is committed before the call; the call runs outside any transaction; its answer is settled
in a transaction of its own against the record **as re-read** then:

| State when the answer arrives | Accepted 200 | 400 | Unknown / unexpected | Retryable, may have reached (050 listed only) | Proven not sent (429, token, rate limit, 401 / 403) |
|---|---|---|---|---|---|
| `submitting`, same attempt | `declared`/`paid` | `rejected` | `outcome_unknown` | `error` → `pending` / `exhausted` | `error` → `pending` / `exhausted`; `invalid_credentials` |
| `outcome_unknown`, `pending`, `error`, `invalid_credentials`, `rejected`, `submitting` of a later attempt | `declared`/`paid` (late acceptance) | unchanged | unchanged | unchanged | unchanged |
| `declared`/`paid` | unchanged (first result kept) | unchanged | unchanged | unchanged | unchanged |
| erased ([ADR 0017](0017-account-erasure.md)) | `DeclarationRecordingFailed` / `PaymentRecordingFailed` with the `certificat` | nothing | nothing | nothing | nothing |

An accepted answer is never discarded and never resent: a save that fails throws the `…RecordingFailed` exception
carrying `certificat` and `idDeclaration` / `idTelepaiement`, recovered with `Reconcile…` then `Resolve…::asDeclared()`
/ `asPaid()`. A late acceptance on a superseded declaration is recorded on that record and counted under
`needingAttention()`: the order in which URSSAF applied the two calls is unknown.

## Consequences

- Unique constraints: accounts `(tenant, siret)`, one non-cancelled mandate per `(tenant, siret)`, one current
  declaration per `(tenant, siret, period)`, payments `(tenant, payment reference)`.
- A host retries by calling the same command with the same input; records never hold the IBAN, so a payment retry
  passes it again.
- Only 020 is polled; declarations and payments leave `outcome_unknown` by operator decision only.

## Amendment (2026-11-18, lead-accepted, lot 1)

- **Payments: one current record per reference.** `PaymentReference` identifies a payment intent, not a row: the
  uniqueness is "one **current** `PaymentRecord` per (tenant, `PaymentReference`)". After a `rejected` answer the same
  reference may be sent again: `PaymentRecord::supersede()` (allowed from `rejected` only) creates a new current record
  linked by `supersedes` / `supersededBy`, as for declarations. ADR 0016 holds the matching key table.
- **Declarations: no supersession while submitting.** `DeclarationRecord::supersede()` is refused from `submitting`
  (`InvalidStateTransition`), so the aggregate itself enforces the `not_claimed` row of the re-entry table.
- **Accounts are created from a 020 read only.** A mandate, declaration or payment may exist before the account is
  tracked: their `accountId` is nullable; the key is always (tenant, `Siret`).
- **Certificates.** A `certificat` read from a 200 answer is kept as received (blank refused, ADR 0006: tolerant
  reading); only a `certificat` typed by an operator (`Resolve…::asDeclared()` / `asPaid()`) must match `^\d{8}$`.
- **Namespaces.** `TenantId` lives in `Domain\Tenant` (ADR 0012); `SubmissionError` and `OutcomeUnknownReason` in
  `Domain\Submission` (shared by declarations and payments). There is no `ExternalReference`: the only host reference
  is `Payment\PaymentReference`, which carries the reference rules (non-blank, at most 191 characters).

## Amendment (2026-11-18, lead-accepted, lot 4b)

- **Settlement of failures that are not URSSAF answers.**
  - Only the `RecordNotFound` `credentials.not_found`, which the ports document as "nothing sent"
    (`ContributionPayer` / `TurnoverDeclarer`), is a local refusal: the record goes to `error`, then to `pending` or
    `exhausted`, and the exception is rethrown unchanged.
  - Every other throwable is the "unknown / unexpected" column: the record goes to `outcome_unknown`, and the exception
    is rethrown. This covers any other `DomainException`, `UnexpectedUrssafResponse`, a retryable exception other than
    `UrssafUnavailable` / `RateLimitExceeded`, and a foreign throwable.
  - The HTTP gateway already wraps every failure after sending (mapping errors included, `guard()` →
    `OutcomeUnknown`). A foreign gateway may not, so no other `DomainException` is assumed to be raised before sending:
    060 is never requeued on a doubt.
  - `UrssafUnavailable` with `mayHaveReachedUrssaf()` on 060 is `outcome_unknown` (never resent). On 050 it is the
    "retryable, may have reached" column: the HTTP gateway raises it only when `050` is listed in `retry_safe_writes`.
- **Retryable outcome.** A record moved back to `pending` makes `DeclareTurnover` / `PayContributions` throw
  `RetryableUrssafFailure` after the commit (reference). Its previous exception is the original failure, with its
  delay. An `exhausted` record is returned (`ItemOutcome::Exhausted`).
- **Birth date of 030 / 031.** The birth date of the command, else the one stored on the tracked `AeAccount`. Without
  either, the call is refused before sending with `ValidationFailed` (`account_reference.birth_date_required`, path
  `birthDate`).
  - `ImportMandate` also takes an optional birth date, stored on the tracked account (ADR 0015 item 6 prompts for it on
    `mandate:import`).
  - A given birth date is stored on the tracked account in the recording transaction. It is never stored without a
    tracked account.
- **`RecordMandateCancellation`** never throws `MandateRecordingFailed`, because no call was made. Only 030 / 031 do.
