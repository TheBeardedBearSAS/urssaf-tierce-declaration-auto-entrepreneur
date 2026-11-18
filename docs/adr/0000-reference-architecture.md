# ADR 0000 — Reference architecture inherited from urssaf-tiers-prestations

Status: Accepted (2026-11-17). Reference: `thebeardedbear/urssaf-tiers-prestations` (HEAD), README "Architecture",
docs/api-reference.md §4, docs/workflow.md, docs/symfony.md, docs/doctrine.md, docs/testing.md,
docs/integration-events.md, phpstan.neon.dist, mago.toml, rector.php, sonar-project.properties. Secondary references:
`thebeardedbear/urssaf-tierce-declaration-cesu` and `thebeardedbear/urssaf-tierce-declaration-paje` (sibling "Tierce
Déclaration" APIs sharing `/tiersdecl/v1`, the OAuth2 flow and the mandate model). Their adaptations are reused only
through the ADRs of this package listed below, never implicitly. When a sibling and the reference disagree, the
reference prevails.

## Context

Every URSSAF package shares one architecture so that integrators and maintainers move between them freely. This ADR
lists what this package inherits, adapts or leaves out. Any further decision or deviation needs its own ADR
`docs/adr/NNNN-<title>.md` (Context / Decision / Consequences), accepted by the lead before the code implementing it.

The AE API (docs/api-reference.md) has nine unitary operations: an account read (020), the notification and
cancellation of a third-party declaration mandate (030 / 031), an estimate (040), a turnover declaration (050), a SEPA
payment order (060), and the registration, revocation and listing of SEPA mandates (070 / 071 / 075). It has no status
endpoint, no idempotency key, and two Swagger contracts that differ (production 1.5.1, sandbox 1.5.0).

## Inherited as is

1. **Layers (Deptrac).** Domain (no dependency), Contract (no dependency); Application → Domain, Contract;
   Infrastructure → Application, Domain, Contract, Psr; Testing → Domain, Contract, Application, Infrastructure;
   Standalone → Domain, Contract, Application, Infrastructure, DoctrineBridge, Doctrine, Psr; DoctrineBridge → Domain,
   Contract, Application, Infrastructure, Doctrine, Psr; SymfonyBridge → all of the above + Testing, Symfony. The
   skeleton `deptrac.yaml` lacks Contract, Testing and Standalone: they are added back with the first class of each
   layer. Tests are not analysed.
2. **PSR-only runtime.** `php >= 8.2`, the `ext-*` the code needs and `psr/*` interfaces, each added with the code
   that uses it. Symfony, Doctrine and HTTP client implementations stay in `require-dev` + `suggest`, with the
   reference `conflict` floors (`symfony/scheduler <6.4.3` is added with the Scheduler code).
3. **Value objects.** `final readonly`, private constructor, named constructors (`of`, `fromString`, `fromInt`…),
   stable violation codes `<subject>.<reason>`, no raw value in exception messages; personal data masked
   (`__debugInfo`, `#[SensitiveParameter]`); `Credentials` not serializable. No `float` in the domain.
4. **Validation.** Notification pattern (`Violation`, `ValidationResult`, `Validator`); input factories collect every
   violation at once (`ValidationFailed`).
5. **Errors.** `UrssafException` and `RetryableException` markers; abstract `DomainException` (`violationCode`,
   `params`, `toViolation`) with `InvalidValue`, `InvariantViolation`, `InvalidStateTransition`, `RecordNotFound`,
   `ValidationFailed`; gateway channels `UrssafRequestRejected`, `UrssafAuthenticationFailed`, `UrssafUnavailable`
   (`mayHaveReachedUrssaf()`, `retryAfterSeconds()`), `UnexpectedUrssafResponse`. Wire codes are classified in
   Infrastructure and exposed as port enums: the Application never compares wire strings.
6. **Wire.** `Payload` is the single reader of untyped data; `JsonEncoder` refuses floats; unknown codes kept raw next
   to a nullable enum (`ApiErrorCode::fromCode()`, `normalise()`); `{}` forced for empty objects.
7. **HTTP.** OAuth2 client credentials, token cached in a `TokenStore` (key = hash of environment, base URI and client
   id, never the secret) with a safety margin; one replay after a 401; `RetryAfter` (delta-seconds only); sandbox
   host overridable; no secret, token, body or query string logged.
8. **Application.** One `final readonly` handler per use case (`__invoke`); ports implemented by the outer layers;
   `UnitOfWork` writing integration events to the outbox in the state-change transaction and publishing domain
   events after commit (failures isolated through `FailureReporter`); atomic claim before any URSSAF write; attempt
   counter; `invalid_credentials` state with requeue; every tunable in `WorkflowPolicy`.
9. **Events.** Domain events recorded by aggregates; public integration events in the `Contract` layer (scalar,
   versioned `TYPE` / `VERSION`) written to an outbox and relayed at least once.
10. **Multi-tenant.** `TenantId` on every aggregate and row; `CredentialsProvider::forTenant()`; unique constraints,
    locks and rate limiting per tenant.
11. **Bridges.** Symfony: `AbstractBundle`, `ServiceIds` constants, `BundleConfiguration` with build-time rules,
    registrars, console commands, scalar Messenger messages with recoverable / unrecoverable mapping, non-blocking
    Lock, Scheduler, diagnostics, translations en / fr, Flex recipe. Doctrine: XML mapping, custom DBAL types,
    dedicated entity manager recommended, no migration shipped. `Standalone` builder and facade; `Testing` simulator.
12. **Tests.** Unit / Functional / Contract suites; hand-written fakes rather than mocks; repository contract traits
    run against the in-memory and Doctrine implementations; Contract suite skipped without
    `URSSAF_SANDBOX_CLIENT_ID` / `URSSAF_SANDBOX_CLIENT_SECRET` and run only by a manual CI job; PHPBench benchmarks
    (`tests/Benchmark/*Bench.php`, CI fails above a 20 % regression); 100 % lines / methods / classes, branch coverage
    ≥ 96 %, Infection MSI 100 %, PHPStan max, Mago, Rector, PHP-CS-Fixer, Deptrac, composer checks, hadolint, guard
    (SPDX headers), DCO.
13. **Quality exceptions.** Only those justified in the reference configuration: container parameters, `noExtends`
    for framework extension points / `IntegrationEvent` / `DomainException`, default and nullable parameters for value
    objects, nullable returns for finders, non-final Doctrine entities, static assertions in tests, wire DTO
    constructor threshold 12, no `mago fmt`, Sonar `php:S2187` for contract-trait test classes. Each one is restored
    when the code that needs it arrives, with the reference justification.

## Adapted (each with its own ADR)

| Topic | ADR |
|---|---|
| Date-only `yyyy-MM-dd` encoding, `date_etat` in `DD/MM/YYYY`, business time zone | [0001](0001-date-only-encoding.md) |
| HTTP: DELETE and POST with query, empty 200, 204, 404 / 409 bodies, no contract header | [0002](0002-http-client-adaptations.md) |
| No reconciliation lookup: 060 never resent, 050 resent only on opt-in, `outcome_unknown` | [0003](0003-retry-and-unknown-outcome.md) |
| Sum types (monthly / quarterly declaration period) | [0004](0004-sum-types.md) |
| Error classification by code (object or list payload, trimmed codes) | [0005](0005-error-classification.md) |
| Two contracts (production 1.5.1, sandbox 1.5.0), one model, wrapper tolerance | [0006](0006-two-contracts-one-model.md) |
| Whole euros instead of cents | [0007](0007-whole-euros.md) |
| SIRET identification and the account reference | [0008](0008-siret-and-account-reference.md) |
| Local lifecycles of accounts, mandates, declarations and payments instead of status polling | [0009](0009-local-lifecycles.md) |
| Personal and bank data | [0010](0010-personal-data.md) |
| Local guards and opt-outs | [0011](0011-guards-and-opt-outs.md) |
| Tenant model | [0012](0012-tenant-model.md) |
| Quota of 300 requests per minute per API client | [0013](0013-quotas-and-rate-limit.md) |
| Integration event catalogue `urssaf.tdae.*` | [0014](0014-integration-events.md) |
| Symfony bridge | [0015](0015-symfony-bridge.md) |
| Doctrine bridge | [0016](0016-doctrine-bridge.md) |
| Account erasure (GDPR) | [0017](0017-account-erasure.md) |

Smaller adaptations, accepted here:

- Wire mappers are split per direction (`…RequestMapper` / `…ResponseMapper`) instead of one class per operation: the
  operations share request fragments (account query, `CaAe`, `Periode`) and response fragments (`MontantsAe`,
  `Periode`, `Erreur`). Precedent: CESU and PAJE.
- Gateway ports are segregated per operation family (account, mandate, contribution, SEPA mandate) and aggregated by
  `TierceDeclarationGateway`, as in the reference (`UrssafGateway` aggregates its sub-ports). docs/roadmap.md listed a
  single interface; the reference prevails.
- Repository interfaces live in the Domain next to their aggregate, as in the reference. docs/roadmap.md placed the
  tracking repositories in the Application; the reference prevails.
- Test repositories (`Journaling*`) decorate the production in-memory repositories instead of duplicating them; the
  shared contract traits prove the inner ones. Precedent: CESU and PAJE.
- `ApiConfiguration` keeps the reference public constructor with defaulted parameters plus `forEnvironment()`, carries
  a business time zone (default `Europe/Paris`) instead of the pairing time zone, the opt-in list of retry-safe writes
  (ADR 0003) and optional request-id / application-id header names (off by default, ADR 0002).
- No `BirthDate` value object: a birth date is a `CalendarDate`, as in CESU and PAJE; the Doctrine bridge maps it with a
  dedicated column type so that a host can encrypt it on its own (ADR 0010, ADR 0016).
- PHPStan analyses against PHP 8.2 (`phpVersion: 80200`) and `type_coverage.constant` is 0: typed class constants need
  PHP 8.3, so the reference setting (inert on PHP 8.2) fails `make ci PHP_VERSION=8.4`. Precedent: CESU and PAJE.

## Not applicable

- Batching by 10 (methods 050 / 070 of Tiers de prestations): every AE call is unitary.
- ASCII transliteration of names: the AE contract publishes no name pattern; values are sent as given (lengths only).
- URSSAF status polling with polling windows (settled / unknown / max age): the AE API has no status endpoint; only the
  account read (020) is refreshed.
- Pairing acceptance instant, billing third-party id, progress table.
- `x-request-id` / `recouv.application.id` headers: not in the AE contract (optional configuration, ADR 0002).
- NIR identification: deprecated by the PDF and never sent (api-reference §4.1), so `ERR_PARAM_MULTIPLES` cannot be
  produced by the package; the code is still catalogued.

## Process

New decisions and deviations get an ADR (Context / Decision / Consequences), accepted by the lead before the code that
implements them. An amendment is appended to the ADR it changes, dated and marked lead-accepted.

## Amendment (2026-11-18, lead-accepted, lot 4a)

Smaller adaptations of the application layer, each overriding the reference (precedent: PAJE lot 4a):

- The outbox relay stores a sink failure as the exception class and code, never its message (the reference stores the
  message). A host sink may put personal data in it (ADR 0010).
- `PsrLoggerFailureReporter` logs the class, the code and the event class names, not the failure message (the
  reference logs the message). The failure comes from a host listener (ADR 0010 items 5 and 7).
- `PurgeOutbox::$retention` is nullable and defaults to `WorkflowPolicy::outboxRetention()` (bundle
  `outbox.retention`, `P30D`, ADR 0015). The reference makes the retention mandatory in the command.
- `WorkflowPolicy` has immutable `with*()` copies and the erasure and mandate tunables (ADR 0011, ADR 0017). The
  reference has neither.
- The in-memory repositories track versions per instance (`VersionTokens`), because the AE aggregates expose no
  version accessor (ADR 0016 item 6).

## Amendment (2026-11-18, lead-accepted, lot 4b)

Smaller adaptations of the application exceptions, following the reference `DomainException` (no previous exception):

- `MandateRecordingFailed`, `DeclarationRecordingFailed` and `PaymentRecordingFailed` carry the last failure as the
  parameter `last_code`: its violation code, or its class name when it has none.
- `RetryableUrssafFailure` (reference) keeps the original retryable failure as its previous exception.
- PAJE's base class, which takes a previous exception, is not adopted.

## Amendment (2026-11-18, lead-accepted, lot 6)

- Infection `timeout` is 180 s instead of the reference 30 s, and `make infection` fails when a mutant is *skipped*.
  Infection never runs a mutant whose covering tests exceed the limit in total; with the Doctrine and Symfony
  functional suites covering the shared value objects, 30 s left 139 mutants untested while MSI still read 100 %.
  The change makes the gate stricter (justified in `infection.json5`).
- Infection `timeoutsAsEscaped` is enabled and `make infection` (and the CI job) also fails on timed-out and syntax-error
  mutants: neither is a verdict. The source is written so that every mutant terminates (bounded retry loops) and is valid
  PHP (no `($a !== null) === $b` comparison, which mutates into a non-associative chain).

## Amendment (2026-11-18, lead-accepted, lot 8)

- Item 1: `deptrac.yaml` now declares every layer of the reference (Contract added in lot 4a, Testing and Standalone in
  lot 4b, the bridges in lots 5 and 6).
