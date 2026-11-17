# ADR 0015 — Symfony bridge

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The package ships a Symfony 6.4 / 7.x bundle, as the reference does (ADR 0000 item 11). Several URSSAF packages can be
installed in one application. AE has no status endpoint (ADR 0009): only 020 is a refresh. 050 and 060 are writes with
no idempotency key (ADR 0003); 060 is never resent. Some inputs are personal data (birth date, IBAN, bank address,
turnover; ADR 0010).

## Decision

1. **Names.** Bundle `UrssafTierceDeclarationAutoEntrepreneurBundle` (`AbstractBundle`); alias and configuration root
   `urssaf_tierce_declaration_auto_entrepreneur`; service ids, parameters, tags and translation domain with the same
   prefix (`ServiceIds` constants); console namespace `urssaf:tdae:`; lock prefix, Monolog channel and schedule name
   `urssaf_tdae`; environment variables `URSSAF_TDAE_ENV`, `URSSAF_TDAE_CLIENT_ID`, `URSSAF_TDAE_CLIENT_SECRET`. Nothing is
   shared with the other URSSAF packages.
2. **Configuration tree (root).** `environment`, `base_uri`, `user_agent`, `headers.request_id`,
   `headers.application_id` (off, ADR 0002), `business_timezone` (`Europe/Paris`, ADR 0001), `token_safety_margin`,
   `log_siret` (ADR 0010), `retry_safe_writes`, `http_client`, `http_timeout`, `credentials` /
   `credentials_provider` / `tenants` (ADR 0012), `token_store`, `gateway.simulated`, `rate_limiter.*` (ADR 0013),
   `lock`, `outbox` (`enabled`, `service`, `sink`, `bus`, `retention` `P30D`), `scheduler`, `messenger`,
   `persistence` (`driver` `in_memory` | `doctrine` | `custom`, `entity_manager`; `doctrine` by default when
   `doctrine/orm` is installed), `workflow` (`stale_submission_after` `PT15M`, `max_submission_attempts` `5`,
   `require_local_mandate` `true`, ADR 0011, `erasure.*`, ADR 0017).
3. **`retry_safe_writes`.** List of quoted operation numbers among `"030"`, `"031"`, `"050"`, `"070"`, `"071"` (the
   `UnspecifiedWrite` operations, ADR 0003), default `[]`, passed to `ApiConfiguration::retrySafeWrites()`. `"060"` and
   any other value are refused at container build: 060 is never resent and the choice is not configurable; 020, 040,
   075 and the token are `Safe` and always retried. Enabling `"050"` is documented as waiting for the sandbox proof.
4. **Build-time rules.** `gateway.simulated` refused with `environment: production` (also at runtime); `doctrine`
   driver without DoctrineBundle; `custom` driver without the repository services; `outbox.enabled` with `custom`
   without `outbox.service`; `lock.enabled: true` without symfony/lock; `scheduler.enabled: true` without
   symfony/scheduler (conflict `<6.4.3`, ADR 0000 item 2); valid time zone and ISO 8601 durations;
   `stale_submission_after` longer than `http_timeout` plus the token call (ADR 0003; skipped for an `%env()%`
   placeholder); container dumps show
   only the `%env()%` placeholder of a secret.
5. **Console commands** (`urssaf:tdae:`; `--tenant` on each command that needs one, ADR 0012; SIRET as argument, it is
   not secret):

   | Command | Does |
   |---|---|
   | `account:sync` | `TrackAccount` / `SyncAccount` (020) for one SIRET, `SyncAccounts` for every tracked account of the tenant |
   | `mandate:notify` / `mandate:cancel` | 030 / 031 (confirmation for `cancel`, `--force` skips it) |
   | `mandate:import` / `mandate:record-cancellation` | `ImportMandate` / `RecordMandateCancellation`, no call (recovery of `mandate.recording_failed`, ADR 0009) |
   | `estimate` / `declare` / `pay` | 040 / 050 / 060 (`--skip-mandate-check`; `declare --skip-periodicity-check`; `pay <payment-reference> --rum`, `--with-iban`, `--allow-additional-payment`; ADR 0011) |
   | `sepa:register` / `sepa:revoke` / `sepa:list` | 070 / 071 (`--effective-on`, `--skip-effective-date-check`) / 075 |
   | `declarations:reconcile` / `payments:reconcile` | `ReconcileStaleDeclarations` / `ReconcileStalePayments`: stale `submitting` to `outcome_unknown` (no call, ADR 0003) |
   | `declarations:resolve` / `payments:resolve` | `ResolveDeclarationOutcome` (`--declared-as <certificat>` or `--not-received`) / `ResolvePaymentOutcome` (`--paid-as <certificat>` or `--not-received`); only the `certificat` is recorded (ADR 0009) |
   | `requeue` | `RequeueAfterInvalidCredentials`: `invalid_credentials` records back to `pending` (no call) |
   | `outbox:relay` / `outbox:purge` | outbox only |
   | `account:forget` | local erasure (ADR 0017; confirmation, `--force`) |
   | `check` | configuration, credentials, authentication, token store, persistence, schema, lock, rate limit |

6. **Personal inputs** never come from plain arguments or options (shell history, process list, cron logs): the
   birth date (`account:sync --with-birth-date`, and `mandate:notify`, `mandate:cancel`, `mandate:import` when the
   `AeAccount` holds none, ADR 0009) and the IBAN of
   `pay` (`--with-iban`) are read from a hidden prompt; the turnover of `estimate` / `declare` and the bank data of
   `sepa:register` come from a JSON file (`--payload`, ≤ 1 MiB, read through the Domain input factories, never
   echoed). A command needing a hidden prompt in a non-interactive run exits with `2`.
7. **Outputs.** Identifiers, states, periods, codes and amounts the command computed; never a birth date, an IBAN (075
   prints `Iban::masked()` only), a BIC, a holder, an address, nor an URSSAF `message` / `description`.
8. **Exit codes.** `0` success (and a busy lock, which is skipped), `1` failure (`rejected`, `outcome_unknown`,
   `invalid_credentials`, a failed batch item or delivery), `2` invalid input or usage.
9. **Messenger** is limited to the account sync (020, a safe read): `SyncAccounts` (tenant) and `SyncAccount` (tenant,
   account id), scalar. No message ever calls 030, 031, 050, 060, 070 or 071. `RetryableException` (429,
   `RateLimitExceeded`, 5xx) is recoverable with the known delay; every other `UrssafException` is unrecoverable.
10. **Locks** `urssaf_tdae.<operation>.<tenant>[.<account id>]` (non-blocking, tenant always included as in the
    reference, ADR 0012): mandate commands and `account:forget` share `mandate.<tenant>.<account id>`; `account:sync`
    per account or per tenant; reconcile per tenant; `outbox:relay` global (the outbox is not tenant-scoped). `declare` and
    `pay` rely on the atomic claim of their record (ADR 0009), not on a lock.
11. **Scheduler** (`urssaf_tdae`): account sync (`P1D`), `declarations:reconcile` and `payments:reconcile` (`PT15M`),
    `outbox:relay` (`PT1M`) and `outbox:purge` (`P1D`) with the outbox. Never a write, never `requeue`.
12. **Translations** en / fr for every violation code found in `src/`, checked by a test both ways.

## Consequences

- Documented in `docs/symfony.md`; the standalone builder (`docs/standalone.md`) refuses a custom gateway with
  `Environment::Production`, since `Standalone` cannot recognise the `Testing` simulator.
