# ADR 0003 — Retries and unknown outcomes

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference reconciles uncertain payment requests through a search operation; the AE API has no idempotency key,
no status endpoint and no way to read a declaration or a payment back (api-reference §1, §4 decision 6, §5):

- 050: the last declaration on an **open** period cancels and replaces the previous one; `certificat` stays the same,
  `idDeclaration` changes (§1.2, §2.5). A resend therefore converges on the same declaration, but **every call
  e-mails the AE** (§1.2) and a resend after the period closed is answered `APPEL_INCONNU`.
- 060: no idempotency, no duplicate detection, no relation to the declared amount published (§2.6, §5). A resend may
  debit the AE twice.
- 030 is not idempotent (`SIRET_DEJA_MANDATE` concerns another platform, §2.2); the repetition of 031, 070 and 071 is
  *not published* (§4 decision 6, §5). 075 can show whether a SEPA mandate exists, but is not a reconciliation of a
  given call.

This ADR supersedes api-reference §4 decision 6 where it calls retries of 050 "safe": the e-mail sent on each call
and the unpublished behaviour once a period closes make an automatic resend a host decision.

## Decision

- **Retry classes** (`Application\Port\Operation::retryClass()`, enum `Application\Port\OperationRetryClass`):

  | Class | Operations | Default on an uncertain failure |
  |---|---|---|
  | `Safe` | token, 020 `FindAccount`, 040 `Estimate`, 075 `ListSepaMandates` | retryable |
  | `NeverResent` | 060 `Pay` | `OutcomeUnknown` |
  | `UnspecifiedWrite` | 030 `NotifyMandate`, 031 `CancelMandate`, 050 `Declare`, 070 `RegisterSepaMandate`, 071 `RevokeSepaMandate` | `OutcomeUnknown` |

- `ApiConfiguration::retrySafeWrites()` (bundle `retry_safe_writes`, list of operation codes, empty by default) lists
  the unspecified writes that the host declares safe to resend, typically `050` once the sandbox has shown the
  last-wins behaviour. A listed operation is then treated as `Safe`. Listing `060` (or any code that is not an
  unspecified write) is refused at construction (`InvalidValue`) and by the bundle configuration rule: 060 is never
  resent and this is not configurable.
- **Safe** (and listed writes): a transport error, 429, 500, 502, 503 or 504 is a `UrssafUnavailable`
  (`RetryableException`; `mayHaveReachedUrssaf()` false only for 429), 429 with its `Retry-After`; any other 5xx is
  `UnexpectedUrssafResponse`.
- **Never resent / unlisted writes**: a transport error, any 5xx, or a 2xx answer that cannot be read (invalid JSON,
  missing field, invalid date or amount, [ADR 0001](0001-date-only-encoding.md), [ADR 0007](0007-whole-euros.md))
  throws `OutcomeUnknown` (`mayHaveReachedUrssaf()` true, not retryable). An unreadable 2xx is an `OutcomeUnknown` even
  for a listed write: the call was accepted, a resend cannot help reading it.
- 429 and a local rate-limit refusal ([ADR 0013](0013-quotas-and-rate-limit.md)) stay retryable on every class: the
  call provably did not reach the service. A token failure is never an unknown outcome (the API call was not sent).
- The single replay after a 401 ([ADR 0002](0002-http-client-adaptations.md), reference behaviour) applies to every
  class except `NeverResent` (060), see the amendment of 2026-11-18 (after lot 8): a 401 is an authentication refusal
  at the gateway, so the call was not processed, but this reading is inherited from the reference, not stated by the
  AE specification, and is not trusted for a payment.
- **Records** ([ADR 0009](0009-local-lifecycles.md) holds the states, transitions and settlement table):
  - `DeclarationRecord` and `PaymentRecord` move to `outcome_unknown` (reason `may_have_reached_urssaf`) on an
    `OutcomeUnknown`. With `050` listed, an uncertain failure of 050 (a `UrssafUnavailable` that
    `mayHaveReachedUrssaf()`) is settled as `error` instead and resent while attempts remain
    (`workflow.max_submission_attempts`, default 5); see the "retryable, may have reached" column of ADR 0009.
  - `ReconcileStaleDeclarations` / `ReconcileStalePayments` move records left `submitting` longer than
    `workflow.stale_submission_after` (default `PT15M`) to `outcome_unknown` with reason `stale_submission`. The value
    must exceed four times the HTTP client timeout: the token call and the API call, twice with the single replay
    after a 401 (bundle configuration rule, `docs/workflow.md`).
  - An operator checks the AE space on www.autoentrepreneur.urssaf.fr (the `certificat` identifies the summary
    shown there, §2.5) and records the result: `ResolveDeclarationOutcome::asDeclared(certificat)` or
    `::asNotReceived()` (back to `pending`; the host then calls `DeclareTurnover` again, last wins);
    `ResolvePaymentOutcome::asPaid(certificat)` or `::asNotReceived()` (back to `pending`: the host
    alone decides to call `PayContributions` again).
- **Mandates and SEPA**: an unknown outcome on 030 / 031 changes nothing locally (no mandate recorded or cancelled);
  the operator checks, then records without a call (`ImportMandate`, `RecordMandateCancellation`) or calls again
  ([ADR 0009](0009-local-lifecycles.md)). On 070 / 071 the exception reaches the caller, who may check with
  `ListSepaMandates` (075) before calling again; the package does not reconcile automatically.
- **Messenger** (safeguard only, [ADR 0015](0015-symfony-bridge.md)): no message of the bundle calls 050 or 060;
  `OutcomeUnknown` and every non-retryable package exception are unrecoverable, `RetryableException` is recoverable
  with its delay.

## Consequences

- The package never produces an automatic duplicate payment, and an automatic duplicate declaration (with its e-mail)
  only when the host listed `050`.
- Some declarations and payments need a manual action; `GetWorkflowOverview` counts them under `needingAttention()`.
- The retry safety of 030 / 031 / 050 / 070 / 071 stays an open point (api-reference §5) settled by configuration, not
  code.

## Amendment (2026-11-18, lead-accepted, lot 8)

- The single replay after a 401 on 060 is **not** exercised by the contract tests (a sandbox cannot be made to answer
  401 on demand). It stays the inherited reading of the reference and an open point of api-reference §5: if URSSAF
  shows that a 401 on 060 may follow a processed payment, the replay is removed for `NeverResent` by a new amendment.

## Amendment (2026-11-18, lead-accepted, after lot 8)

- The replay after a 401 is **removed for `NeverResent` operations** (060 `PayContributions` only). On a 401 the
  client still invalidates the cached token, but it does not send the call again: it raises
  `UrssafAuthenticationFailed` (401), the payment record is settled `invalid_credentials` and is requeued by the
  operator / host (`RequeueAfterInvalidCredentials`) once the credentials are checked. The next attempt fetches a new
  token. Every other operation keeps the single replay.
- This supersedes the lot 8 amendment above (the replay on 060 as an inherited reading and an open point) and the
  bullet of the decision that applied the replay to every class: a payment order is never sent twice by the package,
  whatever the answer, since the "401 proves the call was not processed" reading is not published by URSSAF.
- The `workflow.stale_submission_after` threshold (four times the HTTP timeout) is unchanged: it still covers the
  other operations, which can replay.
