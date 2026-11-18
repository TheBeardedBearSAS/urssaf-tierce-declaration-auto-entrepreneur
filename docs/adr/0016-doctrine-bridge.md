# ADR 0016 — Doctrine bridge

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The roadmap asks for a Doctrine bridge tracking mandates, declarations and payments, needed to reconcile payments
whose outcome is unknown (api-reference §4.6). The reference ships one (ORM 3 / DBAL 4, XML mapping, custom DBAL types,
dedicated entity manager, no migration). AE has no status polling (no progress table), a "one active mandate per SIRET"
rule within a tenant (ADR 0012), records claimed by an atomic update before any write (ADR 0003), periods as a sum type
(ADR 0004), whole euros (ADR 0007) and minimised personal data (ADR 0010).

## Decision

1. **Reference structure.** XML mapping in `src/Bridge/Doctrine/Resources/mapping`; tables and DBAL type names
   prefixed `urssaf_tdae_` (`DoctrineIntegration::TABLE_PREFIX`, `DoctrineIntegration::types()`,
   `registerTypes()`, `mappingDriver()`); `DoctrineTransactionManager`, `DoctrineWorkflowStatistics`; entities
   non-final by necessity (ADR 0000 item 13). No name collides with another URSSAF package.
2. **Tables.** One per aggregate of ADR 0009 (no SEPA table: SEPA mandates are pass-through), every one with a
   leading `tenant_id`:
   - `urssaf_tdae_accounts` (unique `(tenant_id, siret)`; nullable `birth_date`; last `AccountStatus` snapshot:
     `mandate_present`, `periodicity`, nullable `affiliation_date` and `acre_exemption`; `last_synced_at`);
   - `urssaf_tdae_mandates` (`siret`, `origin`, `state`, timeline);
   - `urssaf_tdae_active_mandates` (primary key `(tenant_id, siret)`, unique `mandate_id`) and
     `urssaf_tdae_current_declarations` (primary key `(tenant_id, siret, periodicity, period_year, period_number)`,
     unique `declaration_record_id`): key tables written with DBAL in the save transaction of their aggregate, since
     a partial unique index is not portable to MySQL; a violation is reported as `mandate.duplicate_active` /
     `declaration_record.duplicate_current`, never with the SIRET in the parameters;
   - `urssaf_tdae_declaration_records` (`siret`, period, `fingerprint`, `state`, `attempts`, `supersedes` /
     `superseded_by`, result, errors) and `urssaf_tdae_payment_records` (unique `(tenant_id, payment_reference)`;
     `siret`, period, `montant`, nullable `rum`, `fingerprint`, `state`, `attempts`, result, errors; no IBAN column);
     both indexed `(tenant_id, siret, state)` for the second-payment and erasure checks, `(state,
     last_attempt_at)` for stale submissions;
   - `urssaf_tdae_outbox`, accessed with DBAL by `DoctrineOutbox`.
   Every filtered or sorted column is a real column; no progress table.
3. **Custom DBAL types** (`urssaf_tdae_*`): `tenant_id`, `siret` (`SiretType`), `birth_date` (`BirthDateType`, maps
   a `CalendarDate`; dedicated so that a host overrides it alone to encrypt the column, ADR 0010), `calendar_date`
   (`CalendarDateType`, `Y-m-d`), `instant` (`InstantType`, UTC, second precision), `euros` (`EurosType`, integer
   column), `rum`, `payment_reference`, the fingerprints (`declaration_fingerprint`, `payment_fingerprint`), the id
   types (`account_id`, `mandate_id`, `declaration_record_id`, `payment_record_id`), and JSON types for nullable
   groups (`declaration_result`, `payment_result`, `submission_errors`). Types are `final`; encryption is the
   `Type::overrideType()` recipe of the reference.
4. **Period.** `DeclarationPeriod` is an interface with two implementations (ADR 0004), so it is not an embeddable:
   the records map it to three non-null scalar fields and columns `periodicity` (`M` | `T`), `period_year`,
   `period_number` (month 1–12 or quarter 1–4), converted at the aggregate boundary (`MonthlyPeriod::of()` /
   `QuarterlyPeriod::of()`). No JSON, so periods are queryable and indexable.
5. **Amounts and results.** The turnover lines are never stored (only inside the fingerprint, ADR 0010). The
   `DeclarationResult` (`certificat`, `idDeclaration`, `dateExigibilite`, `ContributionAmounts` with the five `*VL`
   optional, ADR 0006, integers only, ADR 0007) and the `PaymentResult` are nullable JSON groups: Doctrine cannot
   hydrate a nullable embeddable and no query filters on them. The payment `montant` is a `euros` column.
6. **Optimistic concurrency.** Native Doctrine `version` field on every aggregate (persistence token, not a domain
   concept); a stale save is refused before any flush with `InvariantViolation` `<aggregate>.concurrent_modification`
   and the instance detached; `OptimisticLockException` is the backstop, mapped to the same code. The claim is one
   conditional `UPDATE … WHERE state = 'pending'` that bumps the version. The in-memory repositories honour the
   same contract (shared contract traits, ADR 0000 item 12).
7. **Masked conversion errors** on the `siret`, `birth_date`, `rum` and JSON types, both directions.
8. **Portability.** DQL and the DBAL query builder only; deterministic order ending with the id; CI on SQLite.
9. **No migration shipped**: the schema is the host's (`doctrine:migrations:diff`); `urssaf:tdae:check` reports a schema
   that differs from the mapping (ADR 0015).
10. **Dedicated entity manager recommended** (`persistence.entity_manager`): the repositories flush the unit of work of
    their entity manager; the mapping is attached to that entity manager only; `DoctrineTransactionManager` detaches
    only this package's entities on rollback. Standalone: `withDoctrine(EntityManagerInterface)`; the outbox stays
    separate (`withOutbox(new DoctrineOutbox($em), $sink)`).

## Consequences

- Documented in `docs/doctrine.md` (setup, schema, encryption recipe of `BirthDateType` and of the JSON columns,
  concurrency, erasure, backups).
- Schema tests on SQLite check exact columns, indexes and unique constraints per table, and the ADR 0010 assertions.

## Amendment (2026-11-18, lead-accepted, lot 1)

- Payments follow ADR 0009 (amendment lot 1): the unique constraint `(tenant, payment_reference)` is replaced by a key
  table `urssaf_tdae_current_payments` (tenant, payment reference → current record id), written with DBAL in the same
  transaction as the record, like `urssaf_tdae_current_declarations`.
- Added columns: `supersedes` / `superseded_by` on payment records, `accepted_after_supersession` on declaration
  records, `cancellation_origin` on mandates; `account_id` is nullable on mandates and records.

## Amendment (2026-11-18, lead-accepted, lot 6)

Decisions taken while implementing the bridge; they refine the items above.

- **Types.** 17 DBAL types, in this order in `DoctrineIntegration::types()`: `urssaf_tdae_tenant_id`, `_siret`,
  `_account_id`, `_mandate_id`, `_declaration_record_id`, `_payment_record_id`, `_payment_reference`, `_rum`,
  `_declaration_fingerprint`, `_payment_fingerprint`, `_calendar_date`, `_birth_date`, `_instant` (UTC, second precision),
  `_euros` (integer, whole euros), `_declaration_result`, `_payment_result`, `_submission_errors` (JSON). Masked (item 7:
  a conversion error never quotes the value, in both directions): SIRET, RUM, birth date and the three JSON types. A
  stored value the domain refuses is a DBAL `InvalidFormat`, masked or not. The results are rebuilt with
  `Certificate::fromResponse()` (tolerant: a stored certificate from a 200 answer need not match `^\d{8}$`, ADR 0009
  amendment) in the resolved shape (certificate alone) or the accepted shape (identifier, due date, amounts or period);
  any other mix is refused.
- **Schema.** Eight tables: `urssaf_tdae_accounts`, `_mandates`, `_declaration_records`, `_payment_records`, the key
  tables `_active_mandates`, `_current_declarations`, `_current_payments` and `_outbox`. The payment amount column is
  `montant` (items 2 and 5). Indexes and unique constraints are prefixed (`uniq_urssaf_tdae_*`, `idx_urssaf_tdae_*`); the
  outbox carries two nullable columns, `aggregate_id` (a SEPA event has none) and `external_reference`, the host's own
  key (not personal data), so that a host can find the entries of a payment reference. `birth_date` exists in
  `urssaf_tdae_accounts` only; no IBAN, BIC, holder or address column exists anywhere.
- **Save protocol.** A stale managed copy, or an unmanaged instance whose identifier is stored, is refused **before any
  flush** with `<aggregate>.concurrent_modification` (no parameter) and detached; the domain uniqueness guard then runs
  (concurrency is checked first, then uniqueness, as in memory); persist, flush and the key row are written in one DBAL
  transaction. A violation of a key table is reported as the domain duplicate when the guard now finds one, as
  `concurrent_modification` when the identifier now exists, else rethrown. `OptimisticLockException` (the version
  column) is the backstop and maps to `concurrent_modification`.
- **Key rows** are rewritten after the flush: delete by record id, then insert only when the mandate is active or the
  record is current. A supersession saves the superseded record first (its key row goes), then the new one.
- **Claim.** The conditional `UPDATE` also requires the record to be current (`superseded_by IS NULL`, the port
  contract); it keeps the errors and the result and does not touch the key tables.
- **Erasure.** `deleteBySiret()`: select the ids in the given states, detach their managed copies, delete with the same
  condition (a record claimed in between survives, ADR 0017 item 4), remove the key rows of the records that are gone only.
- **Ordering** follows the port documentation and the in-memory order: the key, then the identifier.
- **Transaction manager** detaches the four aggregates only on rollback (never the key or outbox entities, which are
  never managed), rethrows unchanged, never closes the entity manager.
- **Tests.** The races (a write between the guard and the flush, a delete between the select and the delete) are
  provoked through the DBAL logging middleware (`Doctrine\DBAL\Logging\Middleware` over a PSR logger of the tests): no
  `noExtends` entry beyond the DBAL `Type` base class.
