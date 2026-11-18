# Workflow

How the package follows an auto-entrepreneur (AE) account, its mandate, its declarations and its payments, and what it
does when a call fails. The rules come from the ADRs ([0003](adr/0003-retry-and-unknown-outcome.md),
[0009](adr/0009-local-lifecycles.md), [0010](adr/0010-personal-data.md), [0011](adr/0011-guards-and-opt-outs.md),
[0014](adr/0014-integration-events.md), [0017](adr/0017-account-erasure.md)); this page is the summary. The URSSAF
contract is in [api-reference.md](api-reference.md).

The AE API has no operation to read a declaration or a payment. 050 is last-wins on an open period (the `certificat` is
stable, the `idDeclaration` changes, and every call e-mails the AE); 060 has no published idempotency, so a payment
whose answer was lost is never resent. The local records exist to track what was sent and to protect against a double
declaration or a double payment. Everything is keyed by (tenant, SIRET): there is no external reference for a
declaration, and the host's `PaymentReference` is the idempotency key of a payment.

## Lifecycles

### Account

An `AeAccount` is unique per (tenant, SIRET). It holds the last snapshot of method 020 (`mandatPresent`, periodicity,
affiliation date, ACRE exemption) and the instant it was read; it has **no lifecycle stage**.

- `TrackAccount` (020, then the account) creates it. `SyncAccount` and `SyncAccounts` (020 only) are the only refresh.
- A refresh never moves a mandate: `mandatPresent` is a snapshot the host compares (the `AccountSynced` event tells
  when it changed).
- An account is created from a 020 read only. A mandate, a declaration or a payment may exist before the account is
  tracked.
- The birth date is optional, given to `TrackAccount`, `NotifyMandate`, `CancelMandate` or `ImportMandate`, and is
  never shown by a view (`hasBirthDate` only).

### Mandate

`notified` -> `cancelled`. At most one non-cancelled mandate per (tenant, SIRET). Origins: `notified` (030 accepted) or
`imported` (no call); the cancellation records its own origin (`cancelled` after 031, `recorded` without a call).

- `NotifyMandate` (030) records the mandate after URSSAF accepted it. `ImportMandate` records a mandate obtained outside
  the package, without a call.
- Both are idempotent: an active local mandate is never notified again (no call).
- `CancelMandate` (031) needs an active local mandate (`mandate.not_found` otherwise: import it first).
  `RecordMandateCancellation` cancels it without a call.
- `SIRET_DEJA_MANDATE` (another platform holds the mandate) is an `UrssafRequestRejected` of kind
  `mandate_held_elsewhere` and records nothing.
- **The birth date of 030 and 031** is the one of the command, else the one stored on the tracked account. Without
  either, the call is refused before sending (`ValidationFailed`, `account_reference.birth_date_required`, path
  `birthDate`). A given birth date is stored on the tracked account in the recording transaction, never without one.
- Once URSSAF accepted 030 or 031 the transition is recorded without another call, up to 3 attempts, each re-reading
  the account and the mandate in a new transaction (see [Mandate recovery](#mandate-recovery)).
- Serialize the mandate commands of one SIRET (a lock per SIRET): without it two processes can both pass the "no active
  mandate" check.

### Declaration record (050)

One **current** record per (tenant, SIRET, period).

```
pending -> submitting -> declared
                      -> rejected               (final; superseded by the next DeclareTurnover of the period)
                      -> outcome_unknown -> declared | pending
                      -> error -> pending       (retryable failure, attempts left)
                      -> invalid_credentials -> pending   (RequeueAfterInvalidCredentials)
```

`DeclareTurnover` creates the record, claims it atomically (`pending` -> `submitting`, attempts + 1) and sends it
synchronously. The call is made outside any transaction; its answer is settled in a transaction of its own. The record
holds a fingerprint (SHA-256 of tenant, SIRET, period and the five turnover lines, an absent line differing from `0`),
never the turnover. A declared record keeps the `certificat`, the `idDeclaration`, the due date and the amounts.

### Payment record (060)

One **current** record per (tenant, `PaymentReference`), the host's idempotency key: the package cannot tell an
intended second payment from a duplicate. The states are the ones of a declaration with `paid` as success.
`PayContributions` creates, claims and sends it. The record keeps the SIRET, the period, the amount, the optional RUM
and a fingerprint; **the IBAN is passed to 060 and never stored, hashed or put in an event**, so a retry passes it again.
A paid record keeps the `certificat`, the optional `idTelepaiement`, the due date and the period.

A second payment of the same period needs a new `PaymentReference` (see [Guards](#guards-and-opt-outs)).

### SEPA mandates (070, 071, 075)

No local aggregate: registering, revoking and listing are pass-through. 070 and 071 write an integration event that
carries the tenant and the RUM only. `EstimateContributions` (040) is a query and records nothing.

## Re-entry tables

What `DeclareTurnover` and `PayContributions` do when a current record already exists. "Same fingerprint" means the
same turnover (declaration) or the same SIRET, period, amount, RUM and IBAN presence (payment).

| Current record | Declaration, same fingerprint | Declaration, other fingerprint | Payment, same fingerprint | Payment, other fingerprint |
|---|---|---|---|---|
| none | created, claimed, sent | n/a | created (after the second-payment guard), claimed, sent | n/a |
| `pending` | sent | superseded, sent | sent | `PaymentAlreadyRecorded` |
| `error`, attempts left | requeued, sent | superseded, sent | requeued, sent | `PaymentAlreadyRecorded` |
| `error`, exhausted | `exhausted`, no call | superseded, sent | `exhausted`, no call | `PaymentAlreadyRecorded` |
| `submitting` | `not_claimed`, no call | `not_claimed`, no call | `not_claimed`, no call | `not_claimed`, no call |
| `declared` / `paid`, `outcome_unknown`, `invalid_credentials` | `replayed`, no call | superseded, sent | `replayed`, no call | `PaymentAlreadyRecorded` |
| `rejected` | superseded, sent | superseded, sent | superseded, sent | same SIRET and period: superseded, sent; otherwise `PaymentAlreadyRecorded` |

- **Superseding** creates a new record linked to the previous one (`supersedes` / `supersededBy`). The previous record
  keeps its state and stops being current. For a declaration this is URSSAF's last-wins rule. The supersession is saved
  old record first, new record second.
- A payment reference stays bound to its SIRET and period: only the amount, the RUM and the IBAN may change after a
  rejection (typically `invalid_bank_details`).
- A "sent" row runs the guards first (mandate required: refused, nothing recorded). A "no call" row does not.
- Declaration superseding is refused from `submitting`, which is the `not_claimed` row.

### Concurrent calls

The repositories refuse to save a record that another writer changed since it was read
(`<subject>.concurrent_modification`) and the claim is a compare-and-set, so two concurrent calls never send the same
record twice. The call that loses a race sends nothing and returns `not_claimed` (or the record the winner created).
`ReconcileStale…` skips a record that changed since it was read and reports it `not_claimed` with the code
`<subject>.concurrent_modification`; `SyncAccounts` reports an account that changed `retry_later`. Every rollback of this page needs a transactional `TransactionManager`, see
[standalone.md](standalone.md#transactions).

## Settlement table

The answer of a call is settled against the record **as re-read when the answer arrives**. Only the process that
claimed a record (through the submitter) and `Reconcile…` (to `outcome_unknown`) move it out of `submitting`.

| State when the answer arrives | Accepted 200 | 400 | Unknown / unexpected | Retryable, may have reached (050 listed only) | Proven not sent (429, token, rate limit, 401 / 403) |
|---|---|---|---|---|---|
| `submitting`, same attempt | `declared` / `paid` | `rejected` | `outcome_unknown` (and the exception is rethrown unless it is an `OutcomeUnknown`) | `error` -> `pending` / `exhausted` | `error` -> `pending` / `exhausted`; 401 / 403: `invalid_credentials`; missing credentials: `error`, rethrown |
| `outcome_unknown`, `pending`, `error`, `invalid_credentials`, `rejected`, `submitting` of a later attempt | `declared` / `paid` (late acceptance; a superseded declaration is flagged) | unchanged | unchanged | unchanged | unchanged |
| `declared` / `paid` | unchanged, first result kept | unchanged | unchanged | unchanged | unchanged |
| erased ([ForgetAccount](#erasure)) | `DeclarationRecordingFailed` / `PaymentRecordingFailed` with the `certificat` | nothing | nothing | nothing | nothing |

- "Unchanged" means no save, no event, no outbox entry and no exception: a failure that is rethrown on an owned record
  (unexpected, local refusal, retryable) gives instead the current re-entry outcome when the record is not owned any
  more.
- **An accepted answer is never discarded and never resent.**

### Classification of failures

| Failure | Kind | Settlement |
|---|---|---|
| `UrssafRequestRejected` | `rejected` | `rejected`, errors kept |
| `UrssafAuthenticationFailed` (401, 403) | `invalid_credentials` | `invalid_credentials` |
| `OutcomeUnknown` | `outcome_unknown` | `outcome_unknown`, returned |
| `UrssafUnavailable` that may have reached URSSAF | `resendable` on 050 (the gateway raises it only when `050` is listed in `retry_safe_writes`), `outcome_unknown` on 060 | `error` -> `pending` / `exhausted`, or `outcome_unknown` |
| `UrssafUnavailable` that did not reach URSSAF (429, token), `RateLimitExceeded` | `not_sent` | `error` -> `pending` / `exhausted` |
| `RecordNotFound` `credentials.not_found` (nothing sent) | `local_refusal` | `error` -> `pending` / `exhausted`, rethrown unchanged |
| anything else (another `DomainException`, `UnexpectedUrssafResponse`, a foreign throwable) | `unexpected` | `outcome_unknown`, rethrown |

A foreign gateway may not wrap its failures after sending, so no other exception is assumed to be raised before the
call: 060 is never requeued on a doubt. A record moved back to `pending` makes `DeclareTurnover` / `PayContributions`
throw `RetryableUrssafFailure` after the commit (its previous exception is the original failure, with its delay). After
`workflow.max_submission_attempts` (default 5) the outcome is `exhausted` and the record stays in `error`.

Stored errors never hold the message of a foreign exception, only its class ([ADR 0010](adr/0010-personal-data.md)).

## Guards and opt-outs

The guards run before any record is created, and only on the paths that send. Each has an opt-out
([ADR 0011](adr/0011-guards-and-opt-outs.md)).

| Use case | Order | Opt-out |
|---|---|---|
| `DeclareTurnover` | periodicity guard, then mandate guard | `skipPeriodicityCheck`, `skipMandateCheck` |
| `PayContributions` | second-payment guard (new reference only), then mandate guard | `allowAdditionalPayment`, `skipMandateCheck` |
| `EstimateContributions` | mandate guard | `skipMandateCheck` |
| `RevokeSepaMandate` | effective date at least tomorrow in the business time zone | `skipEffectiveDateCheck` |

- **Mandate guard** (on by default, `WorkflowPolicy::requireLocalMandate()`): needs an active local `Mandate` for
  (tenant, SIRET). A synced account with `mandatPresent = true` does **not** satisfy it: import the mandate.
  `MDT_INCONNU` from URSSAF is always reported as `mandate_missing`.
- **Periodicity guard** (050): only when the account was read and its periodicity is known.
- **Second payment** (060): a new reference for a (tenant, SIRET, period) that already has a current record in
  `submitting`, `paid` or `outcome_unknown` is refused (`payment.period_already_paid`). It is not applied when a
  rejected record of the same reference is superseded.
- **Today** comes from `WorkflowPolicy::today()`: at 23:30 UTC on day D it is D + 1 in Paris.

## Unknown outcomes

A write whose answer is lost leaves the record `outcome_unknown` with a reason (`may_have_reached_urssaf`,
`stale_submission`). The package never resends 060, and resends 050 only when `050` is listed in `retry_safe_writes`.

1. `ReconcileStaleDeclarations` / `ReconcileStalePayments` move the records left `submitting` for longer than
   `workflow.stale_submission_after` (default `PT15M`) to `outcome_unknown` with the reason `stale_submission`.
2. An operator checks the AE space on www.autoentrepreneur.urssaf.fr: the `certificat` identifies the summary shown there.
3. `ResolveDeclarationOutcome::asDeclared($tenant, $selector, $certificat)` or `ResolvePaymentOutcome::asPaid(...)`
   records the result (a blank or non `^\d{8}$` certificat is refused; the other fields stay `null`).
   `asNotReceived()` puts the record back to `pending`: for a declaration the host calls `DeclareTurnover` again (last
   wins), for a payment the host alone decides to call `PayContributions` again.

**`DeclarationRecordingFailed` / `PaymentRecordingFailed`** (`…recording_failed`) mean that URSSAF accepted the call but
the result could not be saved (3 conflicts, or another failure; the last failure is the `last_code` parameter). The
exception carries the `certificat` and the `idDeclaration` / `idTelepaiement`. It is never resent: run the `Reconcile…`
use case, then `Resolve…::asDeclared()` / `asPaid()` with that `certificat`. With `ImmediateTransactionManager` the save
may already have gone through: if the record is `declared` / `paid`, there is nothing to do.

## Mandate recovery

- 030 unknown outcome: check the AE space. If the mandate is there, `ImportMandate` (no call); only if it is not, call
  `NotifyMandate` again. 031: if it is cancelled there, `RecordMandateCancellation`; otherwise `CancelMandate` again.
- **`MandateRecordingFailed`** (`mandate.recording_failed`) is the only exception of the mandate commands that means URSSAF
  accepted 030 / 031: recover without any call (`ImportMandate` / `RecordMandateCancellation`), never call the use
  case again. Its parameters give the method, the attempts and the `last_code`. Every other exception means "not sent"
  or "unknown, check the AE space".
- `RecordMandateCancellation` never throws `MandateRecordingFailed`: it makes no call.

## `retry_safe_writes`

`ApiConfiguration::retrySafeWrites()` lists the unspecified writes the host declares safe to resend. For AE it is
meaningful for **050 only** (last-wins, but every call e-mails the AE). 060 is never resent: listing it is refused.

What changes when `050` is listed: an uncertain failure of 050 (`UrssafUnavailable` that may have reached URSSAF) is
settled as `error` instead of `outcome_unknown`, then `pending` while attempts remain, and sent again. The AE may
receive one e-mail per call.

## `stale_submission_after`

`workflow.stale_submission_after` must exceed four times the HTTP client timeout: a call is a token call and the API
call, each bounded by the timeout, and the single replay after a 401 doubles it (the token is renewed and the call
resent, [ADR 0003](adr/0003-retry-and-unknown-outcome.md)). Below that, reconciling a call that is still in flight is a
misconfiguration. With the default 15 second timeout the threshold must be longer than one minute (the default is
`PT15M`); the Symfony bundle refuses a shorter one at build time.

## Erasure

`ForgetAccount` ([ADR 0017](adr/0017-account-erasure.md)) erases, in one transaction, the account, its mandates, its
declaration records and its payment records for a (tenant, SIRET), without any URSSAF call. It refuses (in this order)
an active mandate, a record in `submitting` (always) and a record in `outcome_unknown`, unless `allowActiveMandate` /
`allowUnknownOutcomes` (or the policy) allows it. A selector matching nothing is a `RecordNotFound`. A 050 or 060
accepted after its record was erased cannot be recorded: see `…RecordingFailed` above.

## Monitoring

`GetWorkflowOverview` returns one `TenantOverview` per tenant (or the requested one). `needingAttention()` is:
declarations `outcome_unknown` + `error` + `invalid_credentials`, plus payments `outcome_unknown` + `error` +
`invalid_credentials`, plus the declarations accepted after supersession (the order in which URSSAF applied the two calls
is unknown). An `error` record that remains is always exhausted, because a retryable one is back to `pending`.

## Events

Domain events are published after each commit; integration events are written to the outbox in the same transaction and
relayed at least once, see [integration-events.md](integration-events.md). `RelayOutbox` runs every minute and
`PurgeOutbox` daily; two runs of the same use case must not overlap.

## Safety crons

| Use case | Frequency | Why |
|---|---|---|
| `ReconcileStaleDeclarations`, `ReconcileStalePayments` | every 15 minutes | records stuck in `submitting` after a crash |
| `SyncAccounts` (per tenant) | daily | follows the accounts (020 is cheap, not free) |
| `RequeueAfterInvalidCredentials` (per tenant) | once the credentials are fixed | then call the use cases again |
| `RelayOutbox` | every minute | only with an outbox |
| `PurgeOutbox` | daily | retention `P30D` by default |
