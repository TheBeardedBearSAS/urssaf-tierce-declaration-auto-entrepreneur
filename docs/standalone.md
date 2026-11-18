# Without a framework

With Symfony, use the bundle instead: see [symfony.md](symfony.md).

`TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone\UrssafTierceDeclarationAutoEntrepreneurBuilder` builds
every use case of the package from a PSR-18 transport (`HttpTransport`), a `CredentialsProvider` and the
`ApiConfiguration`; `build()` returns `Standalone\UrssafTierceDeclarationAutoEntrepreneur`, whose methods return the 27
handlers and the gateway (`gateway()`). Each accessor returns a new, stateless handler. A handler is invoked with its
command or query: `$urssaf->declareTurnover()($command)`.

| Commands | Queries |
|---|---|
| `trackAccount()`, `syncAccount()`, `syncAccounts()`, `forgetAccount()` | `getAccount()` |
| `notifyMandate()`, `cancelMandate()`, `importMandate()`, `recordMandateCancellation()` | `estimateContributions()` |
| `declareTurnover()`, `resolveDeclarationOutcome()`, `reconcileStaleDeclarations()` | `getDeclaration()`, `findDeclarations()` |
| `payContributions()`, `resolvePaymentOutcome()`, `reconcileStalePayments()` | `getPayment()`, `findPayments()` |
| `requeueAfterInvalidCredentials()` | `listSepaMandates()`, `getWorkflowOverview()` |
| `registerSepaMandate()`, `revokeSepaMandate()`, `relayOutbox()`, `purgeOutbox()` | |

```php
$urssaf = UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
    new HttpTransport($psr18Client, $psr17Factory, $psr17Factory),
    new InMemoryCredentialsProvider(['tenant-1' => Credentials::of($clientId, $clientSecret)]),
    ApiConfiguration::forEnvironment(Environment::Sandbox),
)->build();
```

Every `with...()` method returns a new builder (the builder is never modified):

| Method | Default | Use |
|---|---|---|
| `withTokenStore(TokenStore)` | in-memory | A store shared by every process that calls URSSAF (`Psr6TokenStore`, `Psr16TokenStore`). |
| `withClock(Clock)` | UTC system clock | Also used by the HTTP gateway. |
| `withIdGenerator(IdGenerator)` | random UUIDs | Record ids and request ids. |
| `withEventPublisher(EventPublisher)` | none | Domain events after each commit. |
| `withFailureReporter(FailureReporter)` | ignored | Receives the failure of an after-commit publication (the commit stays). |
| `withLogger(LoggerInterface)` | none | Logs of the HTTP gateway (never personal data, [ADR 0010](adr/0010-personal-data.md)). |
| `withRateLimiter(RateLimiter)` | `SlidingWindowRateLimiter` | The HTTP gateway enforces the URSSAF quota in the process; `NoopRateLimiter` disables it ([ADR 0013](adr/0013-quotas-and-rate-limit.md)). |
| `withRateLimit(int $limit, int $windowSeconds)` | 300 per 60 s | Sizes the default limiter; an explicit `withRateLimiter()` wins. |
| `withPolicy(WorkflowPolicy)` | see below | Page sizes, stale submission delay (must exceed the HTTP client timeout), attempts, business time zone, mandate guard, erasure opt-ins, outbox retention. Replaces the policy as given. |
| `withRepositories($accounts, $mandates, $declarations, $payments, $transactions, ?$statistics)` | in-memory | Your own persistence; without statistics `getWorkflowOverview()` throws. |
| `withOutbox(Outbox, IntegrationEventSink)` | no outbox | Integration events ([integration-events.md](integration-events.md)); `relayOutbox()` needs it. |
| `withGateway(TierceDeclarationGateway)` | HTTP gateway | For instance the `Testing\SimulatedAeGateway` in tests. **Refused with `Environment::Production`** (`LogicException`): nothing but the HTTP gateway talks for a real auto-entrepreneur. |

**The policy defaults to `new WorkflowPolicy($configuration->businessTimeZone())`**: the business time zone is one
setting with two readers (the HTTP gateway and the guards, [ADR 0011](adr/0011-guards-and-opt-outs.md)).

The defaults (in-memory repositories, statistics and token store) are meant for tests and prototypes: they live and die
with the process. There is no `withDoctrine()` yet: it comes with the Doctrine bridge (lot 6, not yet). Until then give
your own repositories and a transactional `TransactionManager` with `withRepositories()`, and a token store shared by
every process.

## Declaring turnover

```php
$declaration = TurnoverDeclaration::of(MonthlyPeriod::of(2026, 10), Turnover::of(Euros::of(1000), null, null, null, null));

$submission = $urssaf->declareTurnover()(new DeclareTurnover($tenant, $siret, $declaration));

match ($submission->outcome) {
    ItemOutcome::Declared => $submission->declaration->status->result?->certificat,
    ItemOutcome::OutcomeUnknown => 'an operator checks on www.autoentrepreneur.urssaf.fr',
    default => null,
};
```

The record is the (tenant, SIRET, period) pair: see the re-entry table in [workflow.md](workflow.md#re-entry-tables).
A retryable failure is thrown as `RetryableUrssafFailure` after the commit: its previous exception carries the delay.

## Paying contributions

```php
$order = PaymentOrder::of($period, Euros::of(220), Iban::fromString($iban), null);

$payment = $urssaf->payContributions()(new PayContributions($tenant, PaymentReference::fromString('pay-2026-10'), $siret, $order));
```

`PaymentReference` is your idempotency key. **The IBAN is never stored**: pass it again on every retry of the same
reference. A second payment of the same period needs a new reference (and `allowAdditionalPayment: true` when the period
is already paid).

## SEPA mandates

`registerSepaMandate()` (070) returns the new `SepaMandate` with its RUM; `listSepaMandates()` (075) lists the active
ones; `revokeSepaMandate()` (071) takes a `SepaRevocation` (SIRET, RUM, optional effective date, tomorrow at the
earliest). Nothing is stored locally; the events carry the RUM only.

## Scheduling

Run the polling and recovery use cases yourself (cron, worker), see [workflow.md](workflow.md#safety-crons):

- `syncAccounts()` per tenant (method 020) to follow the accounts;
- `reconcileStaleDeclarations()` and `reconcileStalePayments()` to mark records stuck in `submitting` as `outcome_unknown`;
- `requeueAfterInvalidCredentials()` per tenant once the credentials are fixed, then call the use cases again: the
  package keeps neither the turnover nor the IBAN;
- `relayOutbox()` and `purgeOutbox()` when the outbox is used.

Two runs of the same use case must not overlap (use a lock of your own); the atomic claim keeps `declareTurnover()` and
`payContributions()` safe.

## Unknown outcomes

A declaration or a payment whose answer was lost is `outcome_unknown` ([ADR 0003](adr/0003-retry-and-unknown-outcome.md)):
check the AE space on www.autoentrepreneur.urssaf.fr with the `certificat`, then call `resolveDeclarationOutcome()` or
`resolvePaymentOutcome()` (`asDeclared` / `asPaid` with the `certificat`, or `asNotReceived`). See
[workflow.md](workflow.md#unknown-outcomes).

## Testing with the simulator

`Testing\SimulatedAeGateway` is an in-memory URSSAF AE platform: it keeps the accounts, the mandates, the closed
periods and the SEPA mandates, answers the nine operations like the real API (same errors, classified by the same
`ErrorClassifier`) and never contacts URSSAF. It is refused by the builder with the production environment.

```php
$simulator = new SimulatedAeGateway();
$simulator->knowAccount($siret, $birthDate);         // the account exists (no mandate yet)
$urssaf = $builder->withGateway($simulator)->build();

$urssaf->notifyMandate()(...);                       // 030 holds the mandate in the simulator
$simulator->calls();                                 // ['030', ...]
```

| Operation | Answer |
|---|---|
| 020 | unknown SIRET `CPTE_INCONNU`; otherwise the account, `mandatPresent` = the calling tenant holds the mandate |
| 030 | other birth date `PARAM_INVALIDE` (convention); held by another tenant `SIRET_DEJA_MANDATE`; otherwise held by the tenant |
| 031 | other birth date `PARAM_INVALIDE` (convention); not held by the tenant `PARAM_INVALIDE` "Aucun mandat à supprimer"; otherwise released |
| 040 | not mandated `MDT_INCONNU`; otherwise the default amounts (sales 220) for the period before today in Paris (month, or quarter for `T`) |
| 050 | not mandated `MDT_INCONNU`; other periodicity `PERIODICITE_INVALIDE`; closed period `APPEL_INCONNU`; otherwise the amounts, the due date (last day of the month after the period), a `certificat` stable per period and a new `idDeclaration` |
| 060 | not mandated `MDT_INCONNU`; otherwise the `certificat` of the period and a new `idTelepaiement`. No duplicate detection |
| 070 | unknown SIRET HTTP 404 (convention); same IBAN already active `ERREUR_MANDAT_ALREADYEXIST` (409); otherwise a mandate with a new RUM |
| 071 | unknown SIRET or RUM HTTP 404 (convention); otherwise removed |
| 075 | unknown SIRET HTTP 404 (convention); otherwise the active mandates, none being an empty list |

Rows marked "convention" answer a case the specification does not publish ([api-reference.md](api-reference.md) §5).

Script the failures, per operation, in order:

| Method | Effect |
|---|---|
| `failNext(Operation, Throwable)` | the call throws before anything is processed, e.g. `UrssafUnavailable::withStatus(...)` |
| `refuseNext(Operation, $code, $message, $description)` | the call is refused (HTTP 400) with this error, classified like the real API |
| `loseNextAnswer(Operation)` | the call is processed (state changed, identifier consumed) but throws `OutcomeUnknown` (030, 031, 050, 060, 070, 071; refused for 020, 040, 075) |

Set up the world with `knowAccount()` / `knowAccountWith()`, `mandate()`, `withdrawMandate()`, `setPeriodicity()`,
`closePeriod()`, `setAmounts()` and `setEstimatePeriod()`; inspect it with `calls()` and `isMandated()`.

## Manual wiring

The builder only calls public constructors: wire the classes yourself when you need another combination (a decorated
gateway in production, for instance).

```php
$gateway = UrssafGatewayFactory::create($transport, $credentials, $tokenStore, $configuration);
// $transactions is the host TransactionManager (a Doctrine or DBAL transaction around the operation). Do not use
// ImmediateTransactionManager here: it runs the operation without any transaction and is only meant for in-memory
// persistence (tests, demos), where there is nothing to roll back (see "Transactions" below).
$unitOfWork = new UnitOfWork($transactions, $eventPublisher, new IgnoredFailures(), $outbox);

$declareTurnover = new DeclareTurnoverHandler(
    $declarations,
    $accounts,
    new DeclarationSubmitter($declarations, $gateway, $clock, $unitOfWork, $policy),
    new MandateGuard($mandates, $policy),
    $ids,
    $clock,
    $unitOfWork,
    $policy,
);
```

## Transactions

Several guarantees of the use cases are rollbacks, and a rollback needs a transactional `TransactionManager`: the
atomicity of the mandate commands (mandate and account saved together), the retry of a conflicting mandate recording,
the whole transaction of `RequeueAfterInvalidCredentials` and `ForgetAccount`.

The default `ImmediateTransactionManager` (what the builder uses with the in-memory repositories) runs the operation
with no transaction: **nothing is rolled back**. A conflict found after the first save leaves the aggregates saved
before it, and the events of that part are not published. In memory this is acceptable (tests, demos); for real data,
give your own transaction manager and repositories (`withRepositories()`).

Whatever the transaction manager, once URSSAF accepted a call that could not be recorded the failure is a dedicated
signal and the call is never repeated: `MandateRecordingFailed` after 030 / 031, `DeclarationRecordingFailed` after 050
and `PaymentRecordingFailed` after 060 (each carries what the host needs to recover). See
[workflow.md](workflow.md).
