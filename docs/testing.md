# Testing

How the package is tested, and how to test a host application with it. Every command runs in Docker (`make <target>`).

## Suites

`phpunit.xml.dist` declares three suites:

| Suite | Directory | In the default run | What it covers |
|---|---|---|---|
| `unit` | `tests/Unit` | yes | Domain, wire mappers, gateway (PSR-18 mock client), use cases, simulator, events. No network, no database. |
| `functional` | `tests/Functional` | yes | Standalone builder, Symfony bundle and Flex recipe (kernel in `var/`), Doctrine mapping, types and repositories (SQLite file). |
| `contract` | `tests/Contract` | **no** | Live checks against the URSSAF sandbox. Opt-in, see below. |

`make test` runs `unit` and `functional`; `make test-unit`, `make test-functional` and `make test-contract` run one suite.
Tests run in random order (`executionOrder="random"`), and a risky test, a warning, a notice or a deprecation fails the run.

## Fakes, not mocks

The package never mocks its own classes: tests use in-memory implementations of the ports.

- `Testing\SimulatedAeGateway` is an in-memory URSSAF AE platform (accounts, mandates, closed periods, SEPA mandates). It
  answers the nine operations like the real API, with the same error codes classified by the same `ErrorClassifier`, and
  can be scripted: `knowAccount()`, `mandate()`, `closePeriod()`, `refuseNext()`, `failNext()`, `loseNextAnswer()`
  (an unknown outcome), `calls()`. The behaviour per operation is in [standalone.md](standalone.md#testing-with-the-simulator).
  It is refused with the production environment and never contacts URSSAF.
- `Infrastructure\Clock\FrozenClock`, `Infrastructure\Id\RandomUuidGenerator`, `Infrastructure\RateLimiter\NoopRateLimiter`,
  `Infrastructure\TokenStore\InMemoryTokenStore`, `Infrastructure\Credentials\InMemoryCredentialsProvider`.
- In-memory repositories, outbox and statistics (`Infrastructure\Persistence\InMemory`, `Infrastructure\Outbox`).

In a Symfony application enable the simulator in the test environment only:

```yaml
# config/packages/test/urssaf_tierce_declaration_auto_entrepreneur.yaml
urssaf_tierce_declaration_auto_entrepreneur:
    gateway: { simulated: true }
```

It is then the public service `TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway`, reset
with the kernel (see [symfony.md](symfony.md)). Without Symfony pass it to the builder with `withGateway()`
([standalone.md](standalone.md)).

## Repository contract traits

`tests/Functional/Doctrine/Contract` holds one trait per repository port (accounts, mandates, declarations, outbox,
workflow statistics). The in-memory implementations and the Doctrine ones are tested against the same trait, so they
cannot drift apart. A custom repository (the `custom` persistence driver) can reuse the traits the same way.

## Contract tests (live sandbox)

`tests/Contract` runs the real stack (Psr17Factory, a PSR-18 Symfony HTTP client with a 30 s timeout, `OAuth2TokenProvider`,
`JsonApiClient`, `UrssafHttpGateway`, in-memory token store, no rate limiter) against the URSSAF sandbox
("environnement Editeur"). The classes are tagged `#[Group('contract')]`; the suite is not part of the coverage and
mutation gates, nor of `make ci`. Without credentials every test is skipped and the run exits 0.

```sh
export URSSAF_SANDBOX_CLIENT_ID=...
export URSSAF_SANDBOX_CLIENT_SECRET=...
export URSSAF_SANDBOX_SIRET=...          # a sandbox test account
export URSSAF_SANDBOX_BIRTH_DATE=...     # its birth date (Y-m-d)
make test-contract
```

| Variable | Required | Use |
|---|---|---|
| `URSSAF_SANDBOX_CLIENT_ID`, `URSSAF_SANDBOX_CLIENT_SECRET` | yes | Without them everything is skipped. |
| `URSSAF_SANDBOX_BASE_URI` | no | Host override (the sandbox host is to be confirmed at onboarding). |
| `URSSAF_SANDBOX_SIRET` | for 020, 040, 075 and every write | A sandbox account. Test data, never in the code. |
| `URSSAF_SANDBOX_BIRTH_DATE` | for the writes | Its birth date (`Y-m-d`), sent by 030 / 031; every write test needs it. |
| `URSSAF_SANDBOX_WRITE` | no | `1` enables the write tests. They change sandbox state and **e-mail the auto-entrepreneur**. |

Values are read from the process environment only (trimmed, empty means unset) and forwarded by `make test-contract`
with `docker compose run -e NAME`; nothing is written in a file. Each skip message names the missing variable.

| Class | Operations | Needs |
|---|---|---|
| `ReadOnlySandboxContractTest` | token, 020 (an unknown SIRET, then the account), 040, 075 | credentials; the SIRET for 020 / 040 / 075 |
| `WriteSandboxContractTest` | 030 / 031, 050, 060, 070 / 071 | credentials, SIRET, birth date, `URSSAF_SANDBOX_WRITE=1` |

A write test leaves the sandbox as it found it: a mandate or a SEPA mandate it created is cancelled / revoked in a
`finally`; one that already existed is never touched. The SEPA data are the examples of the official documentation
(fictitious), accepted or refused by the sandbox: a refusal is an accepted outcome when its codes are catalogued.

### What the tests settle

The tests assert what the SDK relies on, and write a non-failing note (standard error and `var/contract/observations.log`,
structure and catalogue codes only, never a value or a URSSAF message) for each open point of
[api-reference.md](api-reference.md) §5:

| Note | Open point |
|---|---|
| `response-shape` | wrapped (`OutputDeclaration` / `OutputTelepaiement`) or direct answer on 040, 050, 060 (production 1.5.1 vs sandbox 1.5.0, [ADR 0006](adr/0006-two-contracts-one-model.md)) |
| `compte-pdf-only-fields` | whether 020 returns `date_etat` and `exoAccrePresent` (PDF-only fields) |
| `period-encoding` and the 050 / 060 notes | the period is sent as integers (the exchange is checked) and is not refused |
| `pay-060-*` | the `iban` / `rum` rule on 060: neither, IBAN only, RUM only |
| `sepa-075` | an account without SEPA mandate: empty list or 404; whether the IBAN is anonymised |
| `mandate-030`, `sepa-070`, `sepa-071`, `estimate-040`, `error-codes` | the business codes not published (birth-date mismatch, unknown RUM, no mandate...) |

Every error code returned is checked against `Infrastructure\Urssaf\Exception\ApiErrorCode`: an unknown code fails the
test, and is to be catalogued. Once a point is settled, update the SDK if needed and remove it from §5.

A recording PSR-18 decorator (`SandboxHttpClient`) keeps the structure of each exchange (JSON types of the keys, never the
values; the SIRET and RUM of the path of 071 are masked) so that the notes are factual.

### CI

The `contract` job of `.github/workflows/ci.yml` runs only on a manual run (Actions > CI > Run workflow > `contract`, and
`contract_writes` for the write tests), in the `urssaf-sandbox` environment (restricted to `main`): secrets
`URSSAF_SANDBOX_CLIENT_ID` / `URSSAF_SANDBOX_CLIENT_SECRET`, variables for the others. The observations are uploaded as
an artifact.

## Benchmarks

`make bench` runs PHPBench (`tests/Benchmark`, installed in `tools/phpbench`, out of the package dependencies) on the wire
encoding / decoding and the validation. On a pull request the CI runs the benchmarks of the branch against the base commit
and fails on a regression of more than 20 %.

## Gates

`make ci` runs, in this order: `cs`, `rector`, `phpstan` (level max), `mago`, `deptrac`, `composer-checks`, `hadolint`,
`guard` (forbidden word, SPDX headers), `coverage` (100 % of lines, methods and classes of `src`, from `unit` + `functional`)
and `infection` (100 % MSI and covered MSI, no skipped mutant). `make coverage-branches` (Xdebug) requires 96 % of the
branches. `tests/` is analysed by cs, rector, phpstan and mago like `src/`, the contract suite included.
