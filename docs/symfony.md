# Symfony bundle

Bundle class: `TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\UrssafTierceDeclarationAutoEntrepreneurBundle`
(alias `urssaf_tierce_declaration_auto_entrepreneur`), for Symfony 6.4 and 7.x. Register it in `config/bundles.php`.
The decisions behind it are in [ADR 0015](adr/0015-symfony-bridge.md).

Names at a glance: console commands `urssaf:tdae:*`, locks and Messenger / Scheduler names `urssaf_tdae`, environment
variables `URSSAF_TDAE_*`, monolog channel `urssaf_tdae`.

> [!NOTE]
> **Persistence.** The Doctrine bridge is not released yet: the `doctrine` driver is declared (and is the default when
> `doctrine/orm` is installed) but the container refuses to build with it. Until then set `persistence.driver` to
> `in_memory` (tests only: every record is lost at the end of the process) or `custom` (your own repositories).

## Symfony Flex recipe

The recipe lives in `recipe/thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur/<version>/` (manifest and copied
files), in the layout of [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib), where it is published:
copy the version directory there in a pull request when a release changes it (Flex picks the highest recipe version not
above the installed package version). A test boots the bundle with the copied configuration, so that the recipe follows
the configuration tree.

To try a recipe change before it is published, or to serve it from your own endpoint, `bin/build-flex-endpoint
<output-dir> <base-url>` (or `make flex-endpoint`) builds a Flex endpoint (`index.json` and one JSON file per recipe) to
serve over HTTPS at `<base-url>` and declare in `extra.symfony.endpoint` of the application
(`["<base-url>/index.json", "flex://defaults"]`).

The recipe registers the bundle, copies `config/packages/urssaf_tierce_declaration_auto_entrepreneur.yaml` and adds
`URSSAF_TDAE_ENV` (`sandbox`), `URSSAF_TDAE_CLIENT_ID` and `URSSAF_TDAE_CLIENT_SECRET` to `.env`. Put the secret in your
secret store (`secrets:set`), never in `.env`. The copied file enables the in-memory URSSAF simulator in the `test`
environment.

## Configuration reference

The configuration tree is defined in `Bridge\Symfony\DependencyInjection\BundleConfiguration`.

| Key | Type | Default | Description |
|---|---|---|---|
| `environment` | enum `sandbox` \| `production` | required | Target platform (`https://api-edi.urssaf.fr` / `https://api.urssaf.fr`). Usually `%env(URSSAF_TDAE_ENV)%`. |
| `base_uri` | string \| null | `null` | Overrides the base URI of the selected environment (checked by `urssaf:tdae:check`). |
| `user_agent` | string | `ApiConfiguration::DEFAULT_USER_AGENT` | `User-Agent` header of every call. |
| `headers.request_id` | string \| null | `null` | Name of the header that carries the request id (omitted when null). |
| `headers.application_id.name` / `.value` | string \| null | `null` | Name and value of an application header sent with every call: both or neither. Must differ from `headers.request_id` (case-insensitive). |
| `business_timezone` | string | `Europe/Paris` | Time zone of date-only values and of the declaration due dates; validated against `DateTimeZone::listIdentifiers()`. Shared by the HTTP client and the workflow policy. |
| `token_safety_margin` | int >= 0 | `60` | Seconds before expiry from which an access token is renewed. |
| `log_siret` | bool | `false` | Writes the SIRET in the logs of the HTTP client (never by default). |
| `retry_safe_writes` | list of operation codes | `[]` | Writes the HTTP client may resend after a transient failure: `"030"`, `"031"`, `"050"`, `"070"`, `"071"` (quote them in YAML). `"060"` is refused: the payment order is never resent (ADR 0003). Enable `"050"` only once the sandbox proves it idempotent: every resend e-mails the auto-entrepreneur. |
| `http_client` | string \| null | `null` | Service id of a PSR-18 client. Default: a dedicated Symfony HttpClient wrapped in `Psr18Client`. |
| `http_timeout` | float >= 0.1 | `15.0` | Total timeout (`timeout` and `max_duration`) in seconds of the default HTTP client. State your client's total timeout here when you give `http_client`: the stale rule below relies on it. |
| `credentials_provider` | string \| null | `null` | Service id of a `CredentialsProvider`. Mutually exclusive with `credentials`. |
| `credentials` | map tenant id => `{client_id, client_secret}` | `{}` | Static credentials by tenant (both keys required, non-empty). Use `%env(...)%` placeholders: the secret never appears in the compiled container. |
| `tenants` | list of string | keys of `credentials` | Tenants the commands process when `--tenant` is not given. |
| `token_store.cache_pool` | string | `cache.app` | Cache pool (PSR-6) that stores the access tokens. Recommended: a **dedicated pool** (see below). |
| `token_store.service` | string \| null | `null` | Service id of a `TokenStore`; takes precedence over the cache pool. |
| `gateway.simulated` | bool | `false` | Tests only: replaces the URSSAF API with the in-memory `Testing\SimulatedAeGateway`, exposed as a public service to script the scenarios (see [standalone.md](standalone.md)). Refused with `environment: production`. |
| `rate_limiter.enabled` | bool | `false` | Enforces the published quota in the current process (`SlidingWindowRateLimiter`). |
| `rate_limiter.limit` | int >= 1 | `300` | Requests per window. A value above 300 is accepted and reported as a warning by `urssaf:tdae:check` (ADR 0013). |
| `rate_limiter.window` | ISO 8601 duration | `PT60S` | Window of the limiter, at least one second. |
| `rate_limiter.service` | string \| null | `null` | Service id of your own `RateLimiter` (shared across processes). Requires `rate_limiter.enabled: true`. |
| `lock.enabled` | `auto` \| bool | `auto` | Non-blocking lock per command / handler (see [Locks](#locks-against-overlapping-runs)). `auto`: enabled when `symfony/lock` is installed and the factory service exists; `true`: required; `false`: disabled. |
| `lock.factory` | string | `lock.factory` | Service id of a `Symfony\Component\Lock\LockFactory`. |
| `lock.ttl` | float >= 1 | `3600.0` | Seconds after which the lock of a crashed process expires. Set it above the longest run of a command. |
| `outbox.enabled` | bool | `false` | Writes the integration events in an outbox, in the transaction of each state change (see [integration-events.md](integration-events.md)). |
| `outbox.service` | string \| null | `null` | Service id of your `Outbox` (required with the `custom` driver). Default: the in-memory outbox. |
| `outbox.sink` | string | `messenger` | `messenger`, `event_dispatcher`, or the service id of an `IntegrationEventSink`. |
| `outbox.bus` | string | `messenger.default_bus` | Bus of the `messenger` sink. |
| `outbox.retention` | ISO 8601 duration | `P30D` | How long a dispatched entry is kept (`urssaf:tdae:outbox:purge`). |
| `scheduler.enabled` | bool | `false` | Registers a Symfony Scheduler schedule that runs the crons of the package (see [Scheduler](#scheduler)). Requires `symfony/scheduler`. |
| `scheduler.name` | string | `urssaf_tdae` | Name of the schedule (transport `scheduler_<name>`). |
| `scheduler.frequencies.sync_accounts` | ISO 8601 duration \| null | `P1D` | `urssaf:tdae:account:sync`; null leaves the task out. |
| `scheduler.frequencies.reconcile_declarations` | ISO 8601 duration \| null | `PT15M` | `urssaf:tdae:declarations:reconcile`. |
| `scheduler.frequencies.reconcile_payments` | ISO 8601 duration \| null | `PT15M` | `urssaf:tdae:payments:reconcile`. |
| `scheduler.frequencies.relay_outbox` | ISO 8601 duration \| null | `PT1M` | `urssaf:tdae:outbox:relay` (ignored without the outbox). |
| `scheduler.frequencies.purge_outbox` | ISO 8601 duration \| null | `P1D` | `urssaf:tdae:outbox:purge` (ignored without the outbox). |
| `messenger.enabled` | bool | `false` | Registers the two Messenger handlers. |
| `messenger.bus` | string | `messenger.default_bus` | Bus of `urssaf:tdae:account:sync --async`. |
| `persistence.driver` | enum `in_memory` \| `doctrine` \| `custom` | `doctrine` if `doctrine/orm` is installed, else `in_memory` | Persistence adapter (see the note at the top: `doctrine` is wired by the Doctrine bridge). |
| `persistence.account_repository` | string \| null | `null` | Service id of your `AeAccountRepository` (driver `custom`). |
| `persistence.mandate_repository` | string \| null | `null` | Service id of your `MandateRepository` (driver `custom`). |
| `persistence.declaration_record_repository` | string \| null | `null` | Service id of your `DeclarationRecordRepository` (driver `custom`). |
| `persistence.payment_record_repository` | string \| null | `null` | Service id of your `PaymentRecordRepository` (driver `custom`). |
| `persistence.transaction_manager` | string \| null | `null` | Service id of your `TransactionManager` (driver `custom`; default: `ImmediateTransactionManager`). |
| `persistence.workflow_statistics` | string \| null | `null` | Service id of your `WorkflowStatistics` (driver `custom`, optional: without it `GetWorkflowOverviewHandler` is not registered). |
| `persistence.entity_manager` | string | `default` | Entity manager of the Doctrine bridge (declared so that the tree is final). |
| `workflow.stale_submission_after` | ISO 8601 duration | `PT15M` | Age after which a `submitting` record is considered stale. |
| `workflow.max_submission_attempts` | int >= 1 | `WorkflowPolicy::DEFAULT_MAX_SUBMISSION_ATTEMPTS` | Submission attempts after which a technical failure is no longer requeued. |
| `workflow.require_local_mandate` | bool | `true` | `declare`, `pay` and `estimate` require a local mandate unless `--skip-mandate-check` is given (ADR 0011). |
| `workflow.default_page_size` | int >= 1 | `100` | Records loaded by a batch use case when no limit is given. |
| `workflow.max_page_size` | int >= 1 | `1000` | Upper bound of the records loaded by a batch use case. |
| `workflow.erasure.allow_unknown_outcomes` | bool | `false` | `account:forget` may erase records whose outcome is unknown (ADR 0017). |
| `workflow.erasure.allow_active_mandate` | bool | `false` | `account:forget` may erase an account holding an active mandate (ADR 0017). |

Validation rules enforced at container build time:

- `credentials` and `credentials_provider` are mutually exclusive, and one of them is required.
- `environment` must be `sandbox` or `production`; `gateway.simulated` cannot be used with `environment: production`.
- `business_timezone` must be a known time zone; `rate_limiter.window`, `outbox.retention` and the scheduler
  frequencies must be valid ISO 8601 durations (the window at least one second).
- `headers.application_id` needs both `name` and `value`, or neither; `headers.request_id` and
  `headers.application_id.name` must be different headers.
- `retry_safe_writes` only accepts `"030"`, `"031"`, `"050"`, `"070"` and `"071"`.
- `rate_limiter.service` requires `rate_limiter.enabled: true`.
- `workflow.stale_submission_after` must be longer than twice `http_timeout` (the API call and the token call, each
  bounded by the timeout): a submission still in flight would otherwise be reconciled (ADR 0003).
- `workflow.default_page_size` must not exceed `workflow.max_page_size`.
- `outbox.enabled` with the `custom` driver requires `outbox.service`.
- The `custom` driver requires the four repository service ids.
- `lock.enabled: true` requires `symfony/lock`; `scheduler.enabled: true` requires `symfony/scheduler`.
- The `doctrine` driver requires DoctrineBundle, and is refused until the Doctrine bridge is released.

The `in_memory` driver keeps everything in the process memory: use it for tests only.

### Dedicated cache pool for the access tokens

The access tokens are stored in the `token_store.cache_pool` pool (`cache.app` by default). Declare a dedicated pool, so
that `cache:pool:clear cache.app` (a routine deployment step) does not force a new token request for every tenant, and
so that the tokens live in an adapter you control (Redis with authentication, for instance):

```yaml
framework:
    cache:
        pools:
            urssaf_tdae.token_cache:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_DSN)%'

urssaf_tierce_declaration_auto_entrepreneur:
    token_store: { cache_pool: urssaf_tdae.token_cache }
```

The pool must be shared by every process that calls URSSAF (web, workers, cron): a per-process `array` adapter would
request a token per run. Tokens are secrets: do not expose the pool through a cache dashboard or a shared namespace.

## Persistence

| Driver | Use |
|---|---|
| `in_memory` | Tests and prototypes: every record is lost at the end of the process. |
| `custom` | Your own repositories and transaction manager (`persistence.*` service ids). Records must survive between runs: the claim of a declaration or a payment (ADR 0009) is what prevents a double submission. |
| `doctrine` | Default when `doctrine/orm` is installed. Wired by the Doctrine bridge, which is not released yet: the container refuses to build with it ("the doctrine persistence driver is wired by the Doctrine bridge, not available yet"). Without DoctrineBundle it fails earlier with a message naming `doctrine/doctrine-bundle`. |

```yaml
urssaf_tierce_declaration_auto_entrepreneur:
    persistence:
        driver: custom
        account_repository: App\Urssaf\AccountRepository
        mandate_repository: App\Urssaf\MandateRepository
        declaration_record_repository: App\Urssaf\DeclarationRecordRepository
        payment_record_repository: App\Urssaf\PaymentRecordRepository
```

## Console commands

All commands live in the `urssaf:tdae` namespace. A command that needs a tenant takes `--tenant=<id>`; without it the
only configured tenant is used, and several configured tenants make the option mandatory (exit `2`).

Every command validates its input first, then asks for a confirmation (if any), then takes its lock, then reads the
hidden prompts, then calls the use case. A confirmation or a hidden prompt in a non-interactive run exits with `2`
(`--force` lifts the confirmation only).

| Command | Arguments and options | Purpose |
|---|---|---|
| `account:sync` | `[siret]`, `--with-birth-date`, `--tenant`, `--limit`, `--dry-run`, `--async` | With a SIRET: start tracking the account (or refresh it) with method 020. Without: refresh the least recently synced accounts of the tenant (or of every tenant). `--dry-run` lists the accounts that would be refreshed; `--async` dispatches one `SyncAccountsMessage` per tenant (needs `messenger.enabled`). `--with-birth-date` asks the birth date with a hidden prompt. |
| `mandate:notify` | `<siret>`, `--tenant` | Notify the mandate to URSSAF (030). Asks the birth date (hidden) when the account holds none. |
| `mandate:cancel` | `<siret>`, `--tenant`, `--force` | Cancel the mandate (031), after a confirmation. |
| `mandate:import` | `<siret>`, `--tenant` | Record a mandate already notified elsewhere (no call to URSSAF). An empty answer to the birth date prompt means none. |
| `mandate:record-cancellation` | `<siret>`, `--tenant` | Record a mandate cancelled elsewhere (no call to URSSAF). |
| `estimate` | `<siret>`, `--payload=<file>`, `--skip-mandate-check`, `--tenant` | Estimate the contributions of a turnover (040). |
| `declare` | `<siret>`, `--payload=<file>`, `--skip-mandate-check`, `--skip-periodicity-check`, `--tenant` | Declare the turnover (050). |
| `pay` | `<siret> <payment-reference>`, `--payload=<file>`, `--rum=<rum>`, `--with-iban`, `--allow-additional-payment`, `--skip-mandate-check`, `--tenant` | Order the SEPA payment (060). The IBAN, when needed, comes from a hidden prompt (`--with-iban`). |
| `sepa:register` | `<siret>`, `--payload=<file>`, `--tenant` | Register a SEPA mandate (070). |
| `sepa:revoke` | `<siret> <rum>`, `--effective-on=YYYY-MM-DD`, `--skip-effective-date-check`, `--tenant` | Revoke a SEPA mandate (071). |
| `sepa:list` | `<siret>`, `--tenant` | List the SEPA mandates of an account (075): RUM and masked IBAN only. |
| `declarations:reconcile` | `--limit`, `--dry-run` | Settle the declarations left `submitting` for `workflow.stale_submission_after`. Exits `1` when it moved a record (operator alert). Never resends. |
| `payments:reconcile` | `--limit`, `--dry-run` | Same for the payments. |
| `declarations:resolve` | `[siret]` with `--period=YYYY-MM\|YYYY-Tn`, or `--record-id`; `--declared-as=<certificat>` or `--not-received`; `--tenant` | Settle by hand a declaration whose outcome is unknown, once checked with URSSAF (no call). |
| `payments:resolve` | `[payment-reference]` or `--record-id`; `--paid-as=<certificat>` or `--not-received`; `--tenant` | Same for a payment. |
| `requeue` | `--tenant`, `--limit` | Move the records refused for invalid credentials back to `pending`, once the credentials are fixed. |
| `outbox:relay` | `--limit` | Deliver the pending integration events (only with the outbox). |
| `outbox:purge` | `--older-than=<ISO 8601>` | Delete the outbox entries dispatched for longer than `outbox.retention` (only with the outbox). |
| `account:forget` | `[siret]` or `--account-id`, `--allow-active-mandate`, `--allow-unknown-outcomes`, `--force`, `--tenant` | Erase an account and its local records (local store only, URSSAF is not called; ADR 0017). Asks for a confirmation. |
| `check` | `--tenant`, `--skip-remote` | Check the installation (see below). |

The period option of `declarations:resolve` is `YYYY-MM` for a month or `YYYY-Tn` (`n` from 1 to 4) for a quarter.

### Personal inputs

Personal data never comes from a plain argument or option (shell history, process list, cron logs, ADR 0010):

- the birth date (`account:sync --with-birth-date`, `mandate:notify`, `mandate:cancel`, `mandate:import`) and the IBAN of
  `pay --with-iban` are read from a **hidden prompt**; there is no fallback to an option or an environment variable;
- the turnover of `estimate` / `declare`, the amount of `pay` and the bank data of `sepa:register` come from a **JSON
  payload file** (`--payload`, at most 1 MiB, parsed through the domain input factories, never echoed). `pay` refuses
  `iban` and `rum` keys in the payload (use the prompt and `--rum`).

Payload shapes (all values below are fictitious):

```json
{"period": {"year": 2026, "month": 10}, "turnover": {"sales": 1000, "commercial_services": 500}}
```

```json
{"period": {"year": 2026, "month": 10}, "amount": 220}
```

`declare` takes the first shape (`period` as `{year, month}` or `{year, quarter}`, and the `turnover` lines `sales`,
`furnished_rental`, `commercial_services`, `other_services`, `cipav_receipts`); `estimate` takes the turnover lines
directly; `pay` the second.

The outputs hold identifiers, states, periods, codes and the amounts the command computed. They never show a birth
date, an IBAN (`sepa:list` prints the masked form), a BIC, a holder, an address, nor an URSSAF `message` /
`description`.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Success; a lock held by another process (the command prints `Skipped: "<resource>" is held by another process.`); an aborted confirmation. |
| `1` | Failure: a rejected, `outcome_unknown`, `invalid_credentials` or retry-later outcome, a failed batch item or delivery, a reconcile that moved records, a recording failure (URSSAF accepted the request but the local record failed: the message says not to run the command again and which `*:resolve` / `mandate:import` command to use), a refused erasure (`account:forget`), any `UrssafException` raised by a call. |
| `2` | Invalid input or usage: bad option or argument, missing tenant, invalid payload (the messages never echo a value), a prompt in a non-interactive run, a mandate required (`--skip-mandate-check` or `mandate:import`), an unknown record, a payment reference already recorded. |

## Checking the installation

`urssaf:tdae:check` prints one line per check (`Check | Subject | Status | Detail`) and exits with `1` when one of them
fails (warnings do not fail): run it after each deployment, or in the CI of the application with `--skip-remote`.

| Check | Failure / warning |
|---|---|
| `configuration` | failure: the environment or the base URI is invalid |
| `credentials` | failure: no credentials for a tenant in the configured provider |
| `authentication` | failure: URSSAF refuses the credentials or cannot be reached. A new access token is requested for every tenant (any cached one is ignored), the tokens of the application are not touched. Skipped with `--skip-remote`; replaced by a `simulated gateway` warning when `gateway.simulated` is enabled |
| `token store` | warning: the tokens are stored in `cache.app` instead of a dedicated pool |
| `persistence` | warning: driver `in_memory` |
| `lock` | warning: the lock is disabled or `symfony/lock` is missing |
| `rate limiter` | warning: `rate_limiter.limit` above the published 300 per minute |

The database schema check comes with the Doctrine bridge.

Add checks of your own by implementing `Bridge\Symfony\Diagnostic\InstallationCheck` (autoconfigured, tag
`urssaf_tierce_declaration_auto_entrepreneur.installation_check`).

## Safety crons

The package never retries a write on its own (ADR 0003): a declaration or a payment whose outcome is unknown stays
`submitting` until `declarations:reconcile` / `payments:reconcile` moves it to a state an operator resolves. Run them
(and `account:sync`) regularly, from the crontab or the Scheduler:

```
*/15 * * * * php bin/console urssaf:tdae:declarations:reconcile
*/15 * * * * php bin/console urssaf:tdae:payments:reconcile
0 3 * * *    php bin/console urssaf:tdae:account:sync
```

A reconcile that moved records exits with `1` on purpose, so that the failure of the cron alerts an operator.

## Messenger

With `messenger.enabled: true`, two messages (namespace `Bridge\Symfony\Message`) are handled by the handlers of
`Bridge\Symfony\MessageHandler`. They are plain `final readonly` classes holding identifiers only, so they serialize
with any Messenger serializer.

| Message | Properties | Effect |
|---|---|---|
| `SyncAccountsMessage` | `tenantId` | Refreshes the least recently synced accounts of the tenant (method 020). |
| `SyncAccountMessage` | `tenantId`, `accountId` | Refreshes one account (method 020). |

Messenger never calls 030, 031, 050, 060, 070 nor 071: a retried message could not be told from a new request (ADR 0003).
Declarations and payments are only submitted by your own code or the commands.

Failure mapping (`MessageFailures`):

- `RetryableException` (URSSAF unavailable, rate limit exceeded...) => `RecoverableMessageHandlingException`: the
  message is retried by the retry strategy of the transport, with the delay requested by URSSAF (`Retry-After`) or by
  the local rate limiter when known. The delay needs a symfony/messenger version whose
  `RecoverableMessageHandlingException` accepts it (7.2+); on older versions the retry strategy of the transport alone
  decides.
- Operation already running (lock held) => `RecoverableMessageHandlingException` with a 30 s delay: the message is
  retried later instead of being lost or run concurrently.
- Any other `UrssafException` (including an unknown outcome and the domain exceptions) =>
  `UnrecoverableMessageHandlingException`: retrying the same message cannot succeed.
- Failures of other origins (database down...) are left to the retry strategy of the host.

The `outbox.sink: messenger` dispatches each `IntegrationEvent` on `outbox.bus` (see
[integration-events.md](integration-events.md)).

## Scheduler

Instead of the crontab, the bundle can register a [Symfony Scheduler](https://symfony.com/doc/current/scheduler.html)
schedule that runs the safety crons through Messenger (`Symfony\Component\Console\Messenger\RunCommandMessage`, handled
by the framework): same locks, same reports.

```bash
composer require symfony/scheduler
```

```yaml
urssaf_tierce_declaration_auto_entrepreneur:
    scheduler:
        enabled: true
        frequencies: { sync_accounts: P1D, reconcile_declarations: PT15M, reconcile_payments: PT15M }
```

Run one worker for the schedule (and keep it running, like any Messenger worker):

```bash
php bin/console messenger:consume scheduler_urssaf_tdae
```

The schedule runs `account:sync`, `declarations:reconcile`, `payments:reconcile` and, with the outbox, `outbox:relay`
and `outbox:purge`. It never runs a write and never `requeue`. A command that exits with a non-zero code (a reconcile
that moved records, a failed sync item) makes its message fail (`RunCommandFailedException`): it shows in the worker
logs and is retried by the transport, which is harmless since all scheduled commands are idempotent. Run a single
scheduler worker: two workers of the same schedule would run each task twice (the locks make the second run skip).

## Locks against overlapping runs

Two crons that overlap, a cron racing a Messenger worker, or two operators changing the same mandate could duplicate
calls. The atomic claims keep declarations and payments safe (a record is never sent twice), but URSSAF quotas are
shared. When `symfony/lock` is installed, the commands and handlers below take a **non-blocking** lock named
`urssaf_tdae.<operation>.<scope>` before they work, and release it afterwards:

| Operation | Used by | Scope |
|---|---|---|
| `mandate` | `mandate:notify`, `mandate:cancel`, `mandate:import`, `mandate:record-cancellation`, `account:forget` | tenant and SIRET digest |
| `account.sync` | `account:sync <siret>`, `SyncAccountMessage` | tenant and SIRET digest |
| `accounts.sync` | `account:sync` (batch), `SyncAccountsMessage` | tenant |
| `declarations.reconcile` | `declarations:reconcile` | `all` |
| `payments.reconcile` | `payments:reconcile` | `all` |
| `requeue` | `requeue` | tenant |
| `outbox.relay` / `outbox.purge` | `outbox:relay` / `outbox:purge` | `all` |

`declare` and `pay` take no lock: they rely on the atomic claim of their record (ADR 0009).

The SIRET never appears in a lock name in clear: the scope holds the first 32 hexadecimal characters of its SHA-256.
The digest is pseudonymous, not anonymous (a SIRET has 14 digits and can be enumerated): it only keeps the SIRET out of
the lock store keys and the logs. The reconcile commands are cross-tenant, hence their global lock.

When the lock is already held: a **command** prints `Skipped: "<resource>" is held by another process.` and exits with
`0` (the run that holds the lock does the work); a **Messenger handler** throws `RecoverableMessageHandlingException`
with a 30 s delay. `--dry-run` takes no lock.

Setup:

```bash
composer require symfony/lock
```

```yaml
framework:
    lock: '%env(LOCK_DSN)%'   # redis://..., or `flock`; must be shared by every host that runs the crons / workers

urssaf_tierce_declaration_auto_entrepreneur:
    lock: { enabled: auto, factory: lock.factory, ttl: 3600 }
```

The store must be visible to all the processes that may overlap: `flock` only protects processes of the same machine,
use a Redis / database / Zookeeper store otherwise. `ttl` bounds how long the lock of a crashed process blocks the
operation. Without `symfony/lock` (or with `enabled: false`) everything just runs, protected by the claims only.

## Validation and forms

The use cases validate their input themselves and throw `ValidationFailed` with every violation at once (code
`<subject>.<reason>`, path such as `turnover.sales`, scalar params). When `symfony/validator` is installed, the bundle
registers the public service `Bridge\Symfony\Validation\ViolationListConverter`, which turns them into a Symfony
`ConstraintViolationList`:

| `ConstraintViolation` | Value |
|---|---|
| `getPropertyPath()` | the violation path, with the optional prefix |
| `getCode()`, `getMessageTemplate()` | the violation code (`euros.out_of_range`) |
| `getMessage()` | the translation of the code in the domain `urssaf_tierce_declaration_auto_entrepreneur` (current locale of the `translator`), or the English message without translator |
| `getParameters()` | the violation params as `%name%` placeholders |
| `getCause()` | the original `Domain\Validation\Violation` |
| `getInvalidValue()` | always `null`: the rejected raw values (personal data) are never exposed |

`convert()` keeps the errors only; `convertWithWarnings()` keeps the warnings too. A controller that catches
`ValidationFailed` can throw a `ValidationFailedException` built from `$violations->convert($failed)`, or add each
violation to a form with `FormError`, or let the Form component place them with `(new ViolationMapper())->mapViolation()`.

## Translations

The violation codes (`<subject>.<reason>`, for example `euros.out_of_range`) are translatable messages of the
translation domain `urssaf_tierce_declaration_auto_entrepreneur`. The bundle ships English and French:
`Resources/translations/urssaf_tierce_declaration_auto_entrepreneur.en.yaml` and `.fr.yaml` (same keys, kept in sync
with the codes found in `src/` by the test suite).

To add another locale: create `translations/urssaf_tierce_declaration_auto_entrepreneur.<locale>.yaml` in your
application, with the same keys as the English file. To reword a shipped message, define the key in your application's
file of that locale; keys defined in your application's `translations/` directory override the shipped messages.
