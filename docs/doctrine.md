# Doctrine persistence

The Doctrine bridge (`TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine`) persists the four
aggregates (accounts, mandates, declaration records, payment records) with Doctrine ORM 3 / DBAL 4 (`composer.json`
declares a `conflict` with `doctrine/orm < 3.5.3`, `doctrine/dbal < 4.0` and `doctrine/doctrine-bundle < 2.19`; Doctrine
itself is a `suggest` of the package, never a hard requirement). The domain classes carry no Doctrine metadata: the
mapping is a set of XML files in `src/Bridge/Doctrine/Resources/mapping`. The decisions are in
[ADR 0016](adr/0016-doctrine-bridge.md).

Classes: `DoctrineAeAccountRepository`, `DoctrineMandateRepository`, `DoctrineDeclarationRecordRepository`,
`DoctrinePaymentRecordRepository` (`Bridge\Doctrine\Repository`, implementing the domain repository interfaces),
`DoctrineWorkflowStatistics`, `DoctrineOutbox`, `DoctrineTransactionManager` (implements `TransactionManager`),
`DoctrineIntegration` (types, mapping driver, bundle configuration), plus the DBAL types (`Bridge\Doctrine\Type`) and the
JSON serializers.

## Tables

All tables are prefixed with `urssaf_tdae_` (`DoctrineIntegration::TABLE_PREFIX`), and the DBAL types, indexes and
constraints carry the same prefix: no name collides with the other URSSAF packages, so they can share a database or a
connection. Keep a dedicated entity manager per package all the same (see below): a save flushes the whole unit of work,
so a shared entity manager would write another package's pending changes. Every table starts with `tenant_id`
([ADR 0012](adr/0012-tenant-model.md)).

| Table | Entity | Content |
|---|---|---|
| `urssaf_tdae_accounts` | `Domain\Account\AeAccount` | Tracked auto-entrepreneur: id, tenant, SIRET, optional birth date, the last account status (mandate present, periodicity, affiliation date, ACRE exemption) and the creation, last sync and update instants. |
| `urssaf_tdae_mandates` | `Domain\Mandate\Mandate` | Declaration mandate: id, tenant, SIRET, optional account, origin, state, cancellation origin and the timeline (notified, cancelled, updated instants). |
| `urssaf_tdae_declaration_records` | `Domain\Declaration\DeclarationRecord` | Turnover declaration: id, tenant, SIRET, period (periodicity, year, number), fingerprint, supersession links, creation instant and status (state, attempts, last attempt, URSSAF errors as JSON, result as JSON, outcome-unknown reason, update instant). |
| `urssaf_tdae_payment_records` | `Domain\Payment\PaymentRecord` | Payment order: the same columns plus the payment reference, the amount (`montant`, whole euros) and the optional RUM. **No IBAN column.** |
| `urssaf_tdae_active_mandates`, `urssaf_tdae_current_declarations`, `urssaf_tdae_current_payments` | `Bridge\Doctrine\Entity\ActiveMandate`, `CurrentDeclaration`, `CurrentPayment` | Key tables, see below. |
| `urssaf_tdae_outbox` | `Bridge\Doctrine\Entity\OutboxMessage` | Integration events waiting to be relayed (`DoctrineOutbox`, written with DBAL in the transaction of the state change). Nullable `aggregate_id` and `external_reference` columns (a SEPA event has no aggregate). See [integration-events.md](integration-events.md). |

There is no table for the SEPA mandates (they are pass-through, [ADR 0010](adr/0010-personal-data.md)) and no progress
table (the API has no status polling).

Unique constraints: `(tenant_id, siret)` on the accounts. The mandate, declaration and payment tables have none: their
rules ("one active mandate per SIRET", "one current declaration per period", "one current payment per reference") are
held by the key tables, because a partial unique index (`WHERE superseded_by IS NULL`) does not exist on MySQL.

Every column that a query filters or sorts on is a real column of the aggregate's own table; the URSSAF errors and the
declaration and payment results are JSON columns (no query filters on them, and Doctrine cannot hydrate a nullable
embeddable). Indexes serve the port queries (accounts by last sync, mandates of a SIRET by notification, records by state
and update, by period, by payment reference, stale submissions) and the erasure of
[ADR 0017](adr/0017-account-erasure.md): `(tenant_id, siret, state)` on the record tables serves `countBySiret()` and
`deleteBySiret()`.

### The key tables

| Table | Rule | Primary key |
|---|---|---|
| `urssaf_tdae_active_mandates` | one active (non-cancelled) mandate per tenant and SIRET | `(tenant_id, siret)` |
| `urssaf_tdae_current_declarations` | one current declaration record per tenant, SIRET and period | `(tenant_id, siret, periodicity, period_year, period_number)` |
| `urssaf_tdae_current_payments` | one current payment record per tenant and payment reference | `(tenant_id, payment_reference)` |

"Current" means not superseded ([ADR 0009](adr/0009-local-lifecycles.md)). Each repository `save()` rewrites the key row of
its aggregate with DBAL, in the transaction of the flush, after the flush: it deletes the row of the record id, then
inserts it only when the mandate is active or the record is current. Two concurrent writers therefore cannot both
succeed, on any platform. A supersession saves the superseded record first (its key row goes), then the new one (its key
row comes), in one transaction. The key tables are read-only for the ORM: never write them yourself.

## Registering types and mapping without Symfony

```php
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineIntegration;

DoctrineIntegration::registerTypes();                     // idempotent, global DBAL type registry
$configuration->setMetadataDriverImpl(DoctrineIntegration::mappingDriver());   // SimplifiedXmlDriver
```

`DoctrineIntegration::types()` returns the 17 custom types by name (`urssaf_tdae_*`), `mappingDirectory()` the XML
directory, `MAPPING_NAMESPACE` the namespace prefix handled by the simplified driver, and
`doctrineBundleConfiguration()` the array that the Symfony bundle prepends to the `doctrine` extension.

Then build the persistence from your `EntityManagerInterface` (see [standalone.md](standalone.md)):

```php
$package = UrssafTierceDeclarationAutoEntrepreneurBuilder::create($transport, $credentials, $configuration)
    ->withDoctrine($entityManager)
    ->withOutbox(new DoctrineOutbox($entityManager), $sink)   // optional
    ->build();
```

`withDoctrine()` registers neither the types nor the mapping: they are yours.

### Lazy loading and PHP 8.4

Doctrine ORM 3 loads lazy references either through generated proxies built on the lazy ghosts of `symfony/var-exporter`
6.4 / 7 (the only option before PHP 8.4, and the default), or through the native lazy objects of PHP 8.4 (ORM 3.4+). The
lazy ghosts are gone from `symfony/var-exporter` 8: with that version, and without native lazy objects, the ORM throws
"Symfony LazyGhost is not available" as soon as it builds its proxy factory.

Without the Symfony bundle, enable the native objects yourself on PHP 8.4+:

```php
if (DoctrineIntegration::nativeLazyObjectsAvailable()) {   // PHP >= 8.4 and an ORM that implements them
    $configuration->enableNativeLazyObjects(true);
}
```

`doctrineBundleConfiguration()` adds `orm.enable_native_lazy_objects: true` under the same condition
(`doctrineBundleConfigurationWith([...])` takes the ORM options explicitly); with the Symfony bundle this is done for
you, and your own configuration still wins.

The mapped entities (`AeAccount`, `Mandate`, `DeclarationRecord`, `PaymentRecord` and the four bridge entities) are not
declared `final` so that Doctrine can generate their lazy-loading proxies with any `auto_generate_proxy_classes` mode
(including pre-generation at cache warm-up); they are final by convention (`@final`) and must not be extended. Value
objects are embeddables and custom DBAL types (`DoctrineIntegration::types()`); enums are mapped as backed enums.

## Dedicated entity manager (strongly recommended)

The repositories `persist()` and then `flush()` the entity manager they are built with on **every** `save()`. A Doctrine
flush has no per-entity variant (`flush($entity)` was removed in ORM 3): it writes **all** the pending changes of the
unit of work, host entities included. If the package shares the entity manager of your application, a `save()` made by
the package would therefore also flush half-finished changes of your own entities (and a rollback of the package
transaction would roll them back too).

Use a **dedicated entity manager** (and connection) for the package tables, and point the bundle at it:

```yaml
doctrine:
    dbal:
        connections:
            default: { url: '%env(resolve:DATABASE_URL)%' }
            urssaf_tdae: { url: '%env(resolve:URSSAF_TDAE_DATABASE_URL)%' }   # same database is fine, different connection
    orm:
        entity_managers:
            default: { connection: default, mappings: { App: { type: attribute, dir: '%kernel.project_dir%/src/Entity', prefix: App\Entity } } }
            urssaf_tdae: { connection: urssaf_tdae }                          # the bundle adds the package mapping to this one

urssaf_tierce_declaration_auto_entrepreneur:
    persistence: { driver: doctrine, entity_manager: urssaf_tdae }
```

With a non-default `persistence.entity_manager` the bundle attaches the package mapping to that entity manager only, so
the host entities and the package entities never share a unit of work. When you must share the entity manager, flush
your own changes before calling a package handler, and accept that the handler flushes whatever is pending. The
`entity manager` line of `urssaf:tdae:check` warns when the `default` entity manager is used.

Without the Symfony bundle (standalone), build the entity manager yourself with the package mapping and give it to the
builder:

```php
$package = UrssafTierceDeclarationAutoEntrepreneurBuilder::create($transport, $credentials, $configuration)
    ->withDoctrine($urssafEntityManager)
    ->withOutbox(new DoctrineOutbox($urssafEntityManager), $sink)
    ->build();
```

The `doctrine` driver needs DoctrineBundle: without it the container build fails with a `LogicException` (register the
bundle, or choose `in_memory` or `custom`). The driver is the default when `doctrine/orm` is installed.

## Identity and concurrency

**Managed instances.** Unlike the in-memory repositories, which hand out copies, a read returns the instance managed by
the entity manager: two reads of the same record give the same object, and a change made to it is visible to every
holder before it is saved. Call `EntityManager::clear()` to forget the managed instances (the Symfony Messenger worker
does it between two messages for every entity manager of DoctrineBundle). After `clear()`, read the aggregate again
before saving it: an instance read before the `clear()` is detached and its save is refused with
`<aggregate>.concurrent_modification` when its identifier is stored.

**Optimistic locking.** The four aggregates carry a persistence concurrency token (`version`, a native Doctrine version
field: not a domain concept, no accessor) incremented by every update. When a record was changed by another process (or
another entity manager) since it was read, the save is refused **before any flush** (the entity manager stays usable),
the instance is detached, and the repository throws an `InvariantViolation` with the code `ae_account.concurrent_modification`,
`mandate.concurrent_modification`, `declaration_record.concurrent_modification` or `payment_record.concurrent_modification`
(translated, see [symfony.md](symfony.md)). Nothing was written: read the record again and redo the operation. An
unmanaged instance whose identifier is already stored is refused the same way. The claim of a declaration or a payment
(see below) and any bulk update of these tables also increment the version; `OptimisticLockException` is the backstop for
a write that slips between the check and the flush and maps to the same code. The ports define this contract for every
repository, in-memory ones included ([ADR 0009](adr/0009-local-lifecycles.md)); the bridge is tested against the same
shared scenarios with a second entity manager over the same database. Concurrency is checked first, then uniqueness (as
in memory).

The race it closes: a `DeclareTurnover` call slower than `workflow.stale_submission_after` while the stale reconciliation
moves the record to `outcome_unknown`. The slow call's answer is settled against the record as re-read when it arrives
([ADR 0009](adr/0009-local-lifecycles.md), settlement table): an accepted answer is never discarded, a concurrent
modification during that save is retried a bounded number of times, then reported as a recording failure carrying the
certificate ([workflow.md](workflow.md)).

**A failed flush closes the entity manager.** When a flush fails (a concurrent insert violated a key table, a database
error), the ORM closes the entity manager: it cannot be used again. The repositories still report the duplicate as the
domain code (`declaration_record.duplicate_current`...), but the caller of a long-running process must reset the entity
manager (`ManagerRegistry::resetManager()`, or let the Messenger worker do it). The package never resets it for you.

## Personal data (GDPR)

The tables hold the personal data that the workflow needs ([ADR 0010](adr/0010-personal-data.md)):

- the **SIRET** of the auto-entrepreneur (sole trader: identifying) in every table but the outbox;
- the **birth date** (`urssaf_tdae_accounts.birth_date`, required by the mandate methods), under its own DBAL type
  `urssaf_tdae_birth_date`, the only column of that type, so that it can be encrypted alone;
- the **URSSAF error codes and messages** of a rejected declaration or payment (`errors` columns, JSON), which may echo
  values that the host sent;
- the **declared results** (`result` columns, JSON: certificate, identifiers, due date, contribution amounts, payment
  period), needed to answer `GetDeclaration` / `GetPayment`;
- the **amount** of a payment order and its optional **RUM**.

Never stored: the IBAN, the BIC, the account holder and the address of a SEPA mandate (they are sent to URSSAF and never
kept: the mandate registration is pass-through), the e-mail and phone number. A test asserts that no column of the package
tables looks like one, and the standalone end-to-end test dumps every table after a payment and a SEPA registration with
fictitious values. A conversion error on a SIRET, a RUM, a birth date or a JSON document never contains the stored value
(DBAL's message receives `***`). The package gives you the tools, the policy is yours:

1. **Retention.** Define how long an account, a mandate and the records are kept after they are no longer needed, and run
   a scheduled job in your application that selects the expired records.
2. **Erasure by deletion.** The `ForgetAccount` use case (and its console command `urssaf:tdae:account:forget`) erases, in
   one transaction, an account, its mandates and its declaration and payment records for a (tenant, SIRET), then records
   the integration event ([ADR 0017](adr/0017-account-erasure.md), the rules are in [workflow.md](workflow.md)). Nothing is
   erased at URSSAF: the data they hold is subject to their own retention rules. For your own retention jobs, the
   repositories expose the building blocks: `delete()` (one row, no event, nothing happens when it does not exist) and
   `deleteBySiret()` / `countBySiret()` on the record repositories. The outbox entries are not purged by an erasure (they
   hold identifiers only); `urssaf:tdae:outbox:purge` removes the dispatched ones after `outbox.retention`.
3. **Encryption at rest.** The types are `final`: to encrypt a column, write a type of your own that wraps the package
   one and register it under the same name, after `DoctrineIntegration::registerTypes()`. Example for the birth date:

   ```php
   final class EncryptedBirthDateType extends Type
   {
       public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
       {
           // The mapping declares a date column: widen it to a string for the ciphertext.
           return $platform->getStringTypeDeclarationSQL(['length' => 255] + $column);
       }

       public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
       {
           $plain = (new BirthDateType())->convertToDatabaseValue($value, $platform);

           return $plain === null ? null : $this->cipher()->encrypt($plain);
       }

       public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CalendarDate
       {
           return (new BirthDateType())->convertToPHPValue($value === null ? null : $this->cipher()->decrypt((string) $value), $platform);
       }
   }

   DoctrineIntegration::registerTypes();
   Type::overrideType(BirthDateType::NAME, EncryptedBirthDateType::class);
   ```

   With the Symfony bundle, register it in the configuration of DoctrineBundle (your configuration wins over the one
   the bundle prepends):

   ```yaml
   doctrine:
       dbal:
           types:
               urssaf_tdae_birth_date: App\Doctrine\EncryptedBirthDateType
   ```

   The same pattern covers the JSON types (`SubmissionErrorsType`, `DeclarationResultType`, `PaymentResultType`: the JSON
   column becomes text) and the RUM (`RumType`). No query filters on these columns and no constraint or index uses them,
   so encryption breaks nothing. Use authenticated encryption with a key kept outside the database (KMS, Symfony
   secrets), and re-run `doctrine:migrations:diff` so that the column is wide enough for the ciphertext. Do not encrypt the
   SIRET, the payment reference or the identifiers: they are part of unique constraints, key tables and indexes.
4. **Backups and logs.** Backups keep the data that was erased afterwards: bound their retention and replay the erasures
   after a restore. The package never logs personal data, and the value objects mask it in `var_dump()`.

## Migrations

The package ships **no migration**: the schema depends on your platform and on the rest of your schema. Generate it in
your application:

```bash
php bin/console doctrine:migrations:diff --em=urssaf_tdae
php bin/console doctrine:migrations:migrate
```

(or `doctrine:schema:update --dump-sql` for a quick look). Re-run `diff` after each upgrade of the package: the mapping
may evolve (see the changelog). `urssaf:tdae:check` reports the pending changes of the package tables (`database schema`
check, failure when a change is pending). On MySQL keep the key lengths in mind: the payment reference is 191 characters
long so that its key stays within the index limit of `utf8mb4`.

## Transactions

`DoctrineTransactionManager::transactional()` runs the operation in a DBAL transaction. The repositories flush on every
save, so those flushes join the surrounding transaction and become durable together (this is what makes a mandate and its
account saved together, a supersession, `delete()` then `save()` of a record, the key rows and the outbox write atomic).
On failure the transaction is rolled back, the package entities still managed by the entity manager (the four
aggregates) are detached so that none of them keeps a state that was rolled back, and the throwable is rethrown
unchanged. The entity manager is neither cleared nor closed: the entities of your application stay managed (domain
exceptions are ordinary control flow). Domain events are published by `UnitOfWork` only after the commit.

Because every repository `save()` flushes (see [Dedicated entity manager](#dedicated-entity-manager-strongly-recommended)),
changes to host entities pending in the same entity manager are written, and rolled back, with the package
transaction: use a dedicated entity manager.

If a handler runs inside a transaction opened by your application, the package transaction becomes a nested one
(a savepoint when the platform supports it): its changes become durable only when your outer transaction commits, but
the domain events are published when the nested transaction returns, i.e. before that commit. See the outbox pattern in
[workflow.md](workflow.md) and [integration-events.md](integration-events.md).

## Claim semantics

`claimForSubmission(id, now): bool` is the concurrency guard of `DeclareTurnover` and `PayContributions`. It is one
conditional `UPDATE ... SET state = 'submitting', submission_attempts = submission_attempts + 1, last_attempt_at = :now,
updated_at = :now, outcome_unknown_reason = NULL, version = version + 1 WHERE id = :id AND state = 'pending' AND
superseded_by IS NULL`: exactly one worker wins (returns `true`); the others get `false` and the handler reports
`not_claimed`. A superseded record is never claimed (the port contract). It records no domain event, never loads the
aggregate (the managed copy is detached, so call `get()` again to read the claimed row), keeps the errors and the result
as they are, and does not touch the key tables.

A record left in `submitting` (worker crash, lost answer) is never resubmitted blindly: see the stale reconciliation in
[workflow.md](workflow.md). `workflow.stale_submission_after` must be longer than four times `http_timeout` (a token call
and the API call, twice with the replay after a 401).

## Erasure

`deleteBySiret()` of the record repositories runs in one transaction: it selects the ids of the records of the
(tenant, SIRET) in the given states, detaches their managed copies, deletes them with one `DELETE` carrying the same
condition (a record claimed in between survives, and the re-count of the handler catches it, ADR 0017 item 4), then
removes the key rows of the records that are **gone** only (a survivor keeps its key row), and returns the ids.

## Ordering and portability

The queries use DQL and the DBAL query builder only, no driver-specific SQL, and never rely on "affected rows" except
for the claim (whose `UPDATE` always changes a value) and for Doctrine's versioned `UPDATE` (safe because the version
always changes). The order follows the port documentation, which is the order of the in-memory repositories: the key (last
sync, notification, creation or update instant), then the identifier, so that a list is always deterministic.
`tenants()` lists the tenants in the order of the database collation, which is the byte order for the ASCII tenant ids
the package expects. The test suite runs on SQLite.

## Uniqueness error codes

`save()` translates a duplicate into an `InvariantViolation` (a `UrssafException`) with the same stable code as the
in-memory repositories, never carrying a SIRET, a RUM or a payment reference in its parameters; a concurrent insert that
hits a key table first is reported with the same code:

| Code | Rule |
|---|---|
| `ae_account.duplicate_siret` | `(tenant_id, siret)` of the accounts |
| `mandate.duplicate_active` | one non-cancelled mandate per `(tenant_id, siret)` (key table) |
| `declaration_record.duplicate_current` | one current declaration record per `(tenant_id, siret, period)` (key table) |
| `payment_record.duplicate_reference` | one current payment record per `(tenant_id, payment_reference)` (key table) |
| `ae_account.concurrent_modification`, `mandate.concurrent_modification`, `declaration_record.concurrent_modification`, `payment_record.concurrent_modification` | optimistic lock lost (see above) |

The use cases check these conditions first and raise their own exceptions; the constraints are the safety net against
concurrent inserts.

## Backups

The tables hold personal data and the only local trace of the payment orders sent to URSSAF (there is no status endpoint
to rebuild them): back them up like the rest of your database, bound the retention of the backups (see the erasure
above) and test a restore. A restore that goes back in time can bring back a record in `pending` whose submission had
already reached URSSAF: reconcile such records by hand before resuming the workers.
