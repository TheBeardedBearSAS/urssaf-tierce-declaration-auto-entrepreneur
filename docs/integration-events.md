# Integration events (outbox)

The host application usually needs to react to the workflow: show the certificate of a declaration, schedule a
payment, alert an operator when an outcome is unknown, notice that the mandate was cancelled out of band. The package
publishes these facts as **integration events**: public, scalar classes
(`TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\*`, [ADR 0014](adr/0014-integration-events.md))
versioned independently of the domain model, written to an **outbox** in the same transaction as the state change, then
delivered by a **relay** (`RelayOutboxHandler`).

Guarantees:

- **Durable**: an event exists if and only if its state change was committed (a rolled back use case writes none).
- **At least once**: an event is marked as dispatched only once the sink accepted it; a crash between the delivery and
  the mark delivers it again. Deduplicate on `eventId`: the same fact always has the same id.
- **Ordered per record**: the events of one account, mandate, declaration record or payment record are delivered in
  recording order; when one fails, the next events of the same record wait for the next run (the other records go on).
  The events without a record (SEPA mandates, an erasure without a tracked account) are ordered per tenant: a failed
  one holds back the next record-less events of the same tenant only.

The domain events (`Domain\...\Event\*`, dispatched after the commit) are an internal mechanism and may change with the
domain model. Integrate with the integration events.

Payloads carry identifiers, periods, states and codes only. They never carry the SIRET, the birth date, bank data (IBAN,
BIC, holder), amounts (turnover, contributions, the paid amount), due dates nor a URSSAF message or description
([ADR 0010](adr/0010-personal-data.md)): an auto-entrepreneur is a natural person and these are personal income data.
Read them with the package queries, under your own access control, using the `aggregateId`.

## Catalogue

Common properties: `eventId`, `occurredAt` (ISO 8601, UTC), `tenantId`, `aggregateId` (account id, mandate id,
declaration record id or payment record id; `null` for the SEPA events and for an erasure without a tracked account),
`externalReference` (the `PaymentReference` on the payment events, `null` on every other type); `type()` and
`version()` (1 for every type today; a change of payload bumps it).

Declaration and payment events share `accountId` (`null` when the SIRET is not tracked as an account), `periodicity`
(`M` monthly, `T` quarterly), `periodYear` and `periodNumber` (the month or the quarter).

| Class | `type()` | When | Specific properties |
|---|---|---|---|
| `AccountSynced` | `urssaf.tdae.account.synced` | 020 observed a change of `mandatPresent` or `periodicite` (never emitted when nothing changed) | `previousMandatePresent`, `mandatePresent`, `previousPeriodicity`, `periodicity` (previous values `null` on the first read) |
| `MandateNotified` | `urssaf.tdae.mandate.notified` | 030 accepted, or a mandate imported | `accountId`, `origin` (`notified`, `imported`) |
| `MandateCancelled` | `urssaf.tdae.mandate.cancelled` | 031 accepted, or a cancellation recorded without a call | `accountId`, `origin` (`cancelled`, `recorded`) |
| `DeclarationDeclared` | `urssaf.tdae.declaration.declared` | URSSAF recorded the declaration (050), or an operator resolved an unknown outcome | period, `certificat`, `idDeclaration` (`null` after an operator resolution) |
| `DeclarationRejected` | `urssaf.tdae.declaration.rejected` | URSSAF refused the declaration (final) | period, `errorCodes` |
| `DeclarationOutcomeUnknown` | `urssaf.tdae.declaration.outcome_unknown` | 050 may have reached URSSAF without an answer ([ADR 0003](adr/0003-retry-and-unknown-outcome.md)) | period, `reason` (`may_have_reached_urssaf`, `stale_submission`) |
| `PaymentOrdered` | `urssaf.tdae.payment.ordered` | URSSAF accepted the payment order (060), or an operator resolved an unknown outcome | period, `certificat`, `idTelepaiement` (nullable), `rum` (nullable) |
| `PaymentRejected` | `urssaf.tdae.payment.rejected` | URSSAF refused the payment order (final) | period, `errorCodes` |
| `PaymentOutcomeUnknown` | `urssaf.tdae.payment.outcome_unknown` | 060 may have reached URSSAF without an answer (never resent) | period, `reason` (`may_have_reached_urssaf`, `stale_submission`) |
| `SepaMandateRegistered` | `urssaf.tdae.sepa_mandate.registered` | 070 accepted (pass-through, no local record) | `rum` |
| `SepaMandateRevoked` | `urssaf.tdae.sepa_mandate.revoked` | 071 accepted (204) | `rum` |
| `AccountForgotten` | `urssaf.tdae.account.forgotten` | the account and its records were erased from the package store ([ADR 0017](adr/0017-account-erasure.md)) | `mandateIds`, `declarationRecordIds`, `paymentRecordIds` |

Notes:

- `AccountSynced` with `mandatePresent: false` after `true` means that the mandate was cancelled out of band (by the
  auto-entrepreneur or URSSAF): the local `Mandate` is not moved, record the cancellation if you track it.
- No event exists for 040 / 075 (no state change), local refusals, retryable failures, requeues, records left in
  `error` or `invalid_credentials`, nor a resolution as not received: list those records with
  `FindDeclarations::inState()` / `FindPayments::inState()`.

### Erasure

`AccountForgotten` tells you to erase your own copies of the account, its mandates, declarations and payments:
`aggregateId` is the erased account id (`null` when the SIRET was not tracked as an account), `mandateIds`,
`declarationRecordIds` and `paymentRecordIds` are the `aggregateId` of the erased mandate, declaration and payment
events (oldest first). It carries identifiers only. Nothing was erased at URSSAF.

The outbox is **not** purged by the erasure (its entries hold no personal data): the entries already dispatched are
deleted by the purge (`PurgeOutbox`) after `outbox.retention` (default `P30D`), the pending ones stay until delivered.
The relay orders events per record, not across records, so an event of a listed mandate or record may still arrive
**after** `account.forgotten`: ignore it, never use it to recreate a copy.

## Versioning

Each class has `TYPE` and `VERSION` constants. Adding, removing, renaming or changing the meaning of a property bumps
`VERSION` of that type; the type name never changes. `IntegrationEventCodec::CLASSES` is the allow-list used to decode
the outbox payloads (PHP serialization restricted to the twelve event classes); a new type is added there.

## Handling

```php
final readonly class ShowDeclarationCertificate
{
    public function __construct(private DeclarationScreens $screens) {}

    public function __invoke(DeclarationDeclared $event): void
    {
        // The same event may be delivered twice: deduplicate on $event->eventId.
        $this->screens->byRecord($event->aggregateId)->showCertificate($event->certificat, $event->eventId);
    }
}
```

Without the Symfony bundle, relay with `RelayOutboxHandler` (an `IntegrationEventSink` such as
`Psr14IntegrationEventSink`) and purge with `PurgeOutboxHandler`, from a scheduler; runs of the relay must not overlap.
A failed delivery is stored as the class and the code of the sink exception only, never its message (it may hold
personal data).
