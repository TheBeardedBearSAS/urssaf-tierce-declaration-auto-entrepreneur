# ADR 0017 — Account erasure (GDPR)

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The package stores personal data of a natural person: the SIRET, the birth date on the account, the fingerprints and
results of the declaration records, the payment amounts and RUM, URSSAF messages (ADR 0010), and leaves retention to
the host. Hosts need a supported way to erase an auto-entrepreneur from the package store (right to erasure). The
package does not decide *when* data may be erased; it protects its own workflow guarantees: a payment whose outcome is
unknown must not be forgotten silently, since 060 is never resent and its record is the only local trace
(ADR 0003, api-reference §4.6). Taken from the CESU and PAJE packages (PAJE ADR 0015).

## Decision

1. **Use case.** `Application\UseCase\ForgetAccount` (`TenantId`, `AccountSelector` = account id or SIRET, nullable
   `allowActiveMandate`, `allowUnknownOutcomes`) erases, in one `UnitOfWork` transaction, everything stored for
   (tenant, SIRET): the `AeAccount` if tracked, the mandates (and the active-mandate key row), the declaration records
   (and their current-declaration key rows) and the payment records. Records exist without a tracked account
   (ADR 0009), so a SIRET selector erases them even when no `AeAccount` exists. It returns an `AccountErasure`
   (nullable account id and the erased ids). A selector matching nothing is a `RecordNotFound`. SEPA mandates have
   no local data to erase (ADR 0009).
2. **No URSSAF call.** Cancelling the mandate (031) or revoking a SEPA mandate (071, which the platform must do as soon
   as the user asks, §1.2) is the host's separate, earlier choice. URSSAF keeps its data and its mandates.
3. **Refusals** (`AccountErasureRefused`, carrying the account id and a count only), checked before any deletion, in
   this order:
   - a non-cancelled mandate (`ae_account.forget_active_mandate`): opt-in `allowActiveMandate`;
   - a declaration or payment record in `submitting` (`ae_account.forget_submission_in_flight`): always refused, the
     call may still be running;
   - a declaration or payment record in `outcome_unknown` (`ae_account.forget_unknown_outcome`): opt-in
     `allowUnknownOutcomes`.
   The opt-in defaults come from `WorkflowPolicy` (both `false`; bundle `workflow.erasure.allow_active_mandate`,
   `workflow.erasure.allow_unknown_outcomes`); `null` in the command means the policy.
4. **Ports.** `MandateRepository::delete()`, `DeclarationRecordRepository::countBySiret()` / `deleteBySiret()`,
   `PaymentRecordRepository::countBySiret()` / `deleteBySiret()`, `AeAccountRepository::delete()`. Counts and ids come
   from selected ids, never from affected-row counts. After the deletions the handler re-counts; anything left
   (another process wrote a record meanwhile) throws `InvariantViolation` `ae_account.concurrent_modification`, the
   transaction rolls back and no event is published.
5. **Event.** `AeAccount::forget(Instant, …ids)` records the domain event `AccountForgotten` and changes no state
   (the handler raises the same event itself when no account is tracked); the integration event
   `urssaf.tdae.account.forgotten` (ADR 0014) carries the tenant, the account id (`aggregateId`, `null` when
   untracked) and the erased ids only: no SIRET, no birth date, no amount. Outbox entries already written are not
   purged (they hold no personal data, ADR 0014); `outbox:purge` removes them after `outbox.retention`.
6. **Console.** `urssaf:tdae:account:forget <siret>` (or `--account-id`), `--allow-active-mandate`,
   `--allow-unknown-outcomes`, confirmation unless `--force` (required when not interactive, exit `2`); the question
   does not print the SIRET; `--force` never sets an opt-in. Lock `urssaf_tdae.mandate.<tenant>.<account id>` when the account
   is tracked (ADR 0015).

## Consequences

- Idempotency on the host's `PaymentReference`s and the declaration fingerprints is lost for the erased records; a
  later 050 for the same period is a new declaration (the last one replaces the previous one at URSSAF, §1.2).
- Erasing an `outcome_unknown` payment with the opt-in loses the only local trace of a possibly ordered payment: the
  console warns and the host must check the AE account first.
- A 050 or 060 accepted after its record was erased cannot be recorded: the use case reports it (recording failure of
  ADR 0009) with the `certificat`, so that the host can reconcile.
- Backups are the host's: erasure from backups is outside the package.
