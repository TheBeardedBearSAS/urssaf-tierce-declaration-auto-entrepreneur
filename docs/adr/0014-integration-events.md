# ADR 0014 — Integration event catalogue `urssaf.tdae.*`

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference publishes versioned, scalar integration events (`Contract\Event`) written to an outbox in the
state-change transaction and relayed at least once (ADR 0000 items 8 and 9). The AE package changes local state on
020 (account read), 030 / 031 (mandate), 050 (declaration), 060 (payment), 070 / 071 (SEPA mandate) and on the local
erasure (ADR 0017); 040 and 075 change nothing. Hosts react to these facts (show a certificate, schedule a payment,
alert an operator on an unknown outcome). An AE is a natural person: turnover and contributions reveal personal
income (ADR 0010), and events travel to buses and consumers with weaker controls than the package store.

## Decision

1. **Base class.** `Contract\Event\IntegrationEvent` (abstract readonly; `noExtends` exception of ADR 0000 item 13)
   with scalar properties `eventId` (stable for one fact, for deduplication), `occurredAt` (ISO 8601, UTC),
   `tenantId`, `aggregateId` (package id of the account, mandate or record; nullable: `null` for the SEPA events,
   which have no local aggregate, ADR 0009, and for an erasure without a tracked account, ADR 0017),
   `externalReference` (the host's reference when the aggregate has one, the `PaymentReference` of a payment,
   otherwise `null`); `TYPE` and `VERSION` constants. `IntegrationEventCodec` serialises with an allow-list of the
   catalogue classes.
2. **Catalogue v1** (every `VERSION = 1`; period = `periodicity` `M` | `T` (the `Periodicity` codes), `periodYear`,
   `periodNumber` (month or quarter), ADR 0004):

   | Class | `TYPE` | When | Specific properties |
   |---|---|---|---|
   | `AccountSynced` | `urssaf.tdae.account.synced` | 020 observed a change of `mandatPresent` or `periodicite` (not emitted when nothing changed) | `previousMandatePresent`, `mandatePresent`, `previousPeriodicity`, `periodicity` (`M` / `T`; previous values null on the first read) |
   | `MandateNotified` | `urssaf.tdae.mandate.notified` | 030 accepted, or mandate imported | `accountId`, `origin` (`notified`, `imported`) |
   | `MandateCancelled` | `urssaf.tdae.mandate.cancelled` | 031 accepted, or cancellation recorded without a call | `accountId`, `origin` (`cancelled`, `recorded`) |
   | `DeclarationDeclared` | `urssaf.tdae.declaration.declared` | 050 accepted | `accountId`, period, `certificat`, `idDeclaration` |
   | `DeclarationRejected` | `urssaf.tdae.declaration.rejected` | 050 refused (final) | `accountId`, period, `errorCodes` |
   | `DeclarationOutcomeUnknown` | `urssaf.tdae.declaration.outcome_unknown` | 050 may have reached URSSAF, no answer (ADR 0003) | `accountId`, period, `reason` |
   | `PaymentOrdered` | `urssaf.tdae.payment.ordered` | 060 accepted | `accountId`, period, `certificat`, `idTelepaiement` (nullable, optional in §2.6), `rum` (nullable) |
   | `PaymentRejected` | `urssaf.tdae.payment.rejected` | 060 refused (final) | `accountId`, period, `errorCodes` |
   | `PaymentOutcomeUnknown` | `urssaf.tdae.payment.outcome_unknown` | 060 may have reached URSSAF, no answer (never resent, ADR 0003) | `accountId`, period, `reason` |
   | `SepaMandateRegistered` | `urssaf.tdae.sepa_mandate.registered` | 070 accepted (pass-through, ADR 0009) | `rum` |
   | `SepaMandateRevoked` | `urssaf.tdae.sepa_mandate.revoked` | 071 accepted (204) | `rum` |
   | `AccountForgotten` | `urssaf.tdae.account.forgotten` | local erasure (ADR 0017) | `mandateIds`, `declarationRecordIds`, `paymentRecordIds` |

   `account.synced` is kept because a change seen by 020 is the only signal that the AE or URSSAF cancelled the mandate
   out of band (`mandatPresent` turned `false`) or that the periodicity changed (ADR 0011 periodicity guard); it is
   emitted on change only so that the daily sync does not flood the outbox.
3. **Payloads: identifiers, periods, states and codes only.** Never the SIRET (identifies a natural person), the birth
   date, bank data (ADR 0010), URSSAF messages or descriptions, nor **amounts**: turnover, contributions, the paid
   `montant` and the due date are left out. The amounts are personal income data of a natural person; a consumer
   needing them reads the record through `FindDeclarations` / `FindPayments` with the `aggregateId`, under the host's
   access control. `certificat`, `idDeclaration`, `idTelepaiement` and `rum` are URSSAF technical identifiers,
   kept because hosts reconcile on them (§2.5, §2.6, §2.7). `accountId` is the package id of the `AeAccount`, `null`
   when the SIRET is not tracked as an account (ADR 0009 does not require one before 050 / 060).
4. **No event** for 040 / 075 (no state change), local refusals, retryable failures, requeues, records left in
   `error` or `invalid_credentials`, or operator resolutions other than the outcome they record (a resolution of an
   `outcome_unknown` record as declared / paid, like a late acceptance, emits `DeclarationDeclared` /
   `PaymentOrdered`; as not received, no event). Hosts list those records with `FindDeclarations::inState()` /
   `FindPayments::inState()`.
5. Integration events are built from the domain events alone, written to the outbox in the `UnitOfWork`
   transaction. Exceptions: the SEPA events, which have no aggregate (ADR 0009), are written by the handler in a
   transaction of their own after the 200 / 204; `AccountForgotten` without a tracked account is raised by the
   `ForgetAccount` handler (ADR 0017).

## Consequences

- Documented in `docs/integration-events.md`; any payload change bumps `VERSION` of that type.
- The type prefix `urssaf.tdae.` never collides with the other URSSAF packages (`urssaf.paje.`, …).
- A test asserts that no catalogue class has a property named after the SIRET, a birth date, bank data or an amount.

## Amendment (2026-11-18, lead-accepted, lot 4a)

- `idDeclaration` is nullable: `null` when the declaration was resolved by an operator, whose result holds the
  `certificat` only (ADR 0009); same as `idTelepaiement`.
- `externalReference` is the `PaymentReference` on the three payment events and `null` on every other type.
- The event id hashes the tenant together with the type, the aggregate id (possibly `null`), the instant and the
  facts: two tenants that register the same RUM at the same instant get two ids.
- The relay orders delivery per aggregate. Events without an aggregate (SEPA, an erasure without a tracked account)
  are ordered per tenant: a failed one holds back the next aggregate-less events of the same tenant only, never an
  aggregate's events.
- The SEPA events are built by `Application\Service\SepaMandateEvents`, called by the 070 / 071 handlers (lot 4b).
