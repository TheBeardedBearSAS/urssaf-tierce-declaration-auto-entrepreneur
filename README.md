# URSSAF "API Tierce Déclaration auto-entrepreneur" for PHP

<!-- the-bearded-bear:badges:start -->
[![CI](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur/actions/workflows/ci.yml?query=branch%3Amain) [![Coverage](https://codecov.io/gh/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur/branch/main/graph/badge.svg)](https://app.codecov.io/gh/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur) [![Mutation score](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FTheBeardedBearSAS%2Furssaf-tierce-declaration-auto-entrepreneur%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur/main) [![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur) [![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur&metric=sqale_rating)](https://sonarcloud.io/summary/overall?id=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur) [![Reliability](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur&metric=reliability_rating)](https://sonarcloud.io/summary/overall?id=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur) [![Security](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur&metric=security_rating)](https://sonarcloud.io/summary/overall?id=TheBeardedBearSAS_urssaf-tierce-declaration-auto-entrepreneur) [![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur/badge)](https://scorecard.dev/viewer/?uri=github.com/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon.dist) [![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
<!-- the-bearded-bear:badges:end -->

`thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur` — client, domain model and Symfony integration for the
URSSAF API that lets a platform, on behalf of an auto-entrepreneur, declare turnover, get the contribution amounts and
order their payment (third-party declaration, *tierce déclaration*).

Namespace: `TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur`.

Developed and maintained by [The Bearded Bear](https://the-bearded-bear.com).

> [!IMPORTANT]
> **Unofficial package.** This project is an independent open-source implementation of a public API. It is not
> developed, endorsed, certified or supported by URSSAF, and it is not affiliated with URSSAF in any way. "URSSAF" is
> used only to name the API this package talks to. Using this package does not replace the URSSAF habilitation
> process; the URSSAF documentation remains the authority on the API, and you remain responsible for the data your
> application sends to URSSAF.

> [!NOTE]
> **Status: implemented, not yet released.** The nine operations, the local workflows, the simulator, the standalone
> builder, the Symfony bundle and the Doctrine bridge are implemented and covered by the quality gates (`make ci`). The
> package is **not yet published on Packagist** and has no tagged version. The live sandbox contract tests are written but
> have not been run against the URSSAF sandbox yet: until they have, the points of
> [docs/api-reference.md](docs/api-reference.md) §5 that only the sandbox can settle (response shapes of production
> and sandbox, optional fields, unpublished error codes) are handled by tolerant decoding, not confirmed.

## Scope

| Operation | Endpoint |
|---|---|
| Look up an account (mandate present, periodicity) | `GET /comptes` |
| Notify / cancel a third-party declaration mandate | `POST /mandats`, `DELETE /mandats` |
| Estimate the contributions | `POST /estimer` |
| Declare the turnover | `POST /declarer` |
| Order the SEPA payment | `POST /payer` |
| Register / revoke a SEPA mandate | `POST /sepa/mandats`, `DELETE /sepa/mandats/{siret}/{rum}` |
| List the SEPA mandates of an account | `GET /sepa/mandats/rechercher` |

The API uses OAuth2 client credentials, scope `tiersdecl.ae`, base URL `https://api.urssaf.fr/tiersdecl/v1/ae` (sandbox:
`https://api-edi.urssaf.fr/tiersdecl/v1/ae`). Restricted API: subscription through DataPass.

The production contract (1.5.1) is ahead of the PDF and of the sandbox contract (1.5.0); the differences are listed in
[docs/api-reference.md](docs/api-reference.md) §2.10.

## Features

- The nine operations of the API: account lookup (020), mandate notification (030) and cancellation (031), contribution
  estimate (040), turnover declaration (050), SEPA payment order (060), SEPA mandate registration (070), revocation (071)
  and listing (075).
- OAuth2 client-credentials authentication with a pluggable access-token cache (PSR-6, PSR-16 or in memory); a single
  replay after a 401, never after another failure.
- Typed domain model: `Siret`, declaration periods as a sum type (monthly or quarterly), amounts in whole euros (no
  float anywhere), and the production (1.5.1) and sandbox (1.5.0) contracts tolerated by one model
  ([ADR 0006](docs/adr/0006-two-contracts-one-model.md), [ADR 0007](docs/adr/0007-whole-euros.md)).
- Local validation limited to the published bounds, reported as a list of violations with stable codes; local guards with
  an opt-out for the rules the API does not publish ([ADR 0011](docs/adr/0011-guards-and-opt-outs.md)).
- No duplicate side effect: a payment order (060) is claimed locally before the call and is never resent; any other write
  whose effect is unspecified is resent only when you list it (`retry_safe_writes`); an ambiguous outcome is reported
  as `outcome_unknown` and resolved by an operator ([ADR 0003](docs/adr/0003-retry-and-unknown-outcome.md)).
- Local lifecycles of accounts, mandates, declarations and payments, reconciliation of stale submissions, requeue after
  invalid credentials ([docs/workflow.md](docs/workflow.md)).
- Multi-tenant: credentials resolved per tenant through a `CredentialsProvider`; in-process rate limiter for the quota of
  300 requests per minute per API client, HTTP 429 reported with `Retry-After`.
- Personal and bank data: IBAN, BIC, holder and address are never stored or logged; the birth date has its own
  replaceable column type; account erasure (GDPR) ([ADR 0010](docs/adr/0010-personal-data.md),
  [ADR 0017](docs/adr/0017-account-erasure.md)).
- Integration events (`Contract\Event\*`, `urssaf.tdae.*`) written to an outbox in the transaction of each state change and
  relayed at least once ([docs/integration-events.md](docs/integration-events.md)).
- Symfony bundle: configuration, 20 `urssaf:tdae:*` console commands, Messenger account sync, Scheduler, locks,
  diagnostics, translations (en, fr) and a Flex recipe; Doctrine bridge (ORM 3 / DBAL 4).
- `Testing\SimulatedAeGateway`: a simulated URSSAF platform to test a host application without network.

## Requirements

PHP 8.2+ with `ext-filter`, `ext-json` and `ext-mbstring`; a PSR-18 HTTP client and PSR-17 factories. Optional (`suggest`):
Symfony 6.4 / 7.x for the bundle, `doctrine/orm` and `doctrine/dbal` for the Doctrine bridge.

## Installation

```bash
composer require thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur
```

The package is not on Packagist yet: until its first release, declare the GitHub repository in the `repositories` of your
`composer.json` (type `vcs`). With Symfony Flex, the recipe registers the bundle and adds the `URSSAF_TDAE_*` variables
once it is published in `symfony/recipes-contrib`; otherwise follow *Quick start (Symfony)* below.

## Quick start (standalone)

Without a framework, the builder wires every use case from a PSR-18 client, PSR-17 factories and your credentials.
Persistence is in memory by default (tests and prototypes): use `withDoctrine()` for anything else, see
[Persistence](#persistence). [docs/standalone.md](docs/standalone.md) lists the options and
[docs/workflow.md](docs/workflow.md) explains the lifecycles. All values below are fictitious.

```php
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone\UrssafTierceDeclarationAutoEntrepreneurBuilder;

// $httpClient is your PSR-18 client, $psr17Factory a PSR-17 request and stream factory.
$urssaf = UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
    new HttpTransport($httpClient, $psr17Factory, $psr17Factory),
    new InMemoryCredentialsProvider(['tenant-1' => Credentials::of('my-client-id', 'my-client-secret')]),
    ApiConfiguration::forEnvironment(Environment::Sandbox),
)->build();

$tenant = TenantId::fromString('tenant-1');
$siret = Siret::fromString('73282932000074');
$birthDate = CalendarDate::fromString('1975-02-23');

// Notify the mandate the auto-entrepreneur gave to your platform (030) and record it locally.
$mandate = $urssaf->notifyMandate()(new NotifyMandate($tenant, $siret, $birthDate));

$state = $mandate->state; // "notified"
```

Estimating contributions (`estimateContributions()`), declaring turnover (`declareTurnover()`), paying (`payContributions()`),
the SEPA mandates, resolving an unknown outcome and the safety crons are covered in
[docs/standalone.md](docs/standalone.md) and [docs/workflow.md](docs/workflow.md). In tests,
`Testing\SimulatedAeGateway` replaces the HTTP gateway and never contacts URSSAF.

## Quick start (Symfony)

With Symfony Flex the recipe registers the bundle, copies the configuration and adds the environment variables;
otherwise register `TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\UrssafTierceDeclarationAutoEntrepreneurBundle`
in `config/bundles.php` and create the file below. Every option is described in [docs/symfony.md](docs/symfony.md).

```yaml
# config/packages/urssaf_tierce_declaration_auto_entrepreneur.yaml
urssaf_tierce_declaration_auto_entrepreneur:
    environment: '%env(URSSAF_TDAE_ENV)%'      # sandbox | production
    credentials:
        default:
            client_id: '%env(URSSAF_TDAE_CLIENT_ID)%'
            client_secret: '%env(URSSAF_TDAE_CLIENT_SECRET)%'
    # Persistence: Doctrine by default (when doctrine/orm is installed). Use a DEDICATED entity manager (docs/doctrine.md),
    # then generate the tables with doctrine:migrations:diff. Without Doctrine give your own repositories ("custom"
    # driver, docs/symfony.md); "in_memory" loses every record at the end of the process.
    # persistence: { driver: doctrine, entity_manager: urssaf_tdae }
```

Check the installation, track an account and declare a turnover (the commands never take a birth date, an IBAN or an
amount as a plain argument: they come from a hidden prompt or a JSON file, all values here are fictitious):

```bash
php bin/console urssaf:tdae:check
php bin/console urssaf:tdae:account:sync 73282932000074 --with-birth-date
echo '{"period": {"year": 2026, "month": 10}, "turnover": {"sales": 1000}}' > declaration.json
php bin/console urssaf:tdae:declare 73282932000074 --payload=declaration.json
```

## Persistence

Mandates, declarations and payments are tracked locally: the claim of a record before each submission is what prevents
a double declaration or payment. The Doctrine bridge (ORM 3 / DBAL 4, tables prefixed `urssaf_tdae_`, no migration
shipped, a dedicated entity manager recommended) persists them: see [docs/doctrine.md](docs/doctrine.md). Doctrine is
optional (`suggest`); without it use the `custom` driver (your own repositories) or, for tests only, `in_memory`. No IBAN,
BIC, holder or address is ever stored.

## Documentation

- [docs/api-reference.md](docs/api-reference.md) — technical reference (single source of truth for the package).
- [docs/symfony.md](docs/symfony.md) — Symfony bundle: Flex recipe, configuration, console commands, Messenger, Scheduler, locks.
- [docs/standalone.md](docs/standalone.md) — wiring without a framework, scheduling, testing with the simulator.
- [docs/doctrine.md](docs/doctrine.md) — Doctrine persistence: tables, types, dedicated entity manager, concurrency, personal data, migrations.
- [docs/testing.md](docs/testing.md) — suites, fakes, contract tests against the sandbox, benchmarks, quality gates.
- [docs/workflow.md](docs/workflow.md) — lifecycles, re-entry and settlement tables, unknown outcomes, guards, erasure.
- [docs/integration-events.md](docs/integration-events.md) — public integration events and the outbox.
- [docs/adr/](docs/adr/README.md) — architecture decision records (inherited architecture and its adaptations).
- [docs/security/assurance-case.md](docs/security/assurance-case.md) — security assurance case (threat model, countermeasures, residual risks).
- [docs/roadmap.md](docs/roadmap.md) — implementation plan (done) and what remains before the release.
- [docs/openapi/](docs/openapi/) — official OpenAPI contracts (production and sandbox) from the URSSAF API portal.
- `docs/reference/` — official URSSAF documentation: not versioned (URSSAF documents), download it from the links in [docs/api-reference.md](docs/api-reference.md) into this local, git-ignored folder.
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/td-ae

## Development

Development only needs Docker: every `make` target runs in a container.

```sh
make install     # build the image, install dependencies
make ci          # full quality gate: cs, rector, phpstan, mago, deptrac, composer checks, hadolint, guard, coverage, infection
make test        # unit + functional tests
make test-contract  # live sandbox contract tests (opt-in, skipped without credentials)
make bench       # benchmarks (PHPBench, tests/Benchmark)
make help        # every target
```

`make ci PHP_VERSION=8.4` runs the gate on another PHP version.

Once the repository is public, `main` publishes its quality proofs, linked from the badges at the top of this file: the
coverage report on Codecov, the full mutation report on the Stryker Dashboard, the SonarQube Cloud quality gate and the
OpenSSF Scorecard. The benchmarks (`tests/Benchmark`, PHPBench in `tools/phpbench`, out of the package dependencies)
run on every pull request against the base commit, and the job fails on a regression of more than 20 %.

### Contract tests

`make test-contract` checks the SDK against the real URSSAF sandbox. It is opt-in: without credentials every test is
skipped (exit 0), and it is not part of `make ci`, of the coverage or of the mutation gates. Export the variables in your
shell (they are forwarded to the container, never written to a file):

| Variable | Required | Use |
|---|---|---|
| `URSSAF_SANDBOX_CLIENT_ID`, `URSSAF_SANDBOX_CLIENT_SECRET` | yes | Sandbox OAuth2 client credentials. |
| `URSSAF_SANDBOX_BASE_URI` | no | Override of the sandbox host. |
| `URSSAF_SANDBOX_SIRET` | for most tests | SIRET of a sandbox test account (020, 040, 075 and the writes). |
| `URSSAF_SANDBOX_BIRTH_DATE` | for the writes | Its birth date, `YYYY-MM-DD` (sent by 030, 031; every write test needs it). |
| `URSSAF_SANDBOX_WRITE` | no | `1` runs the tests that write sandbox state (030, 031, 050, 060, 070, 071) and **e-mail the auto-entrepreneur**. |

No SIRET, birth date or bank detail of a real person is ever in the code. The tests print non-failing notes (response
shape, optional fields, error codes) in `var/contract/observations.log`; see [docs/testing.md](docs/testing.md). In CI the
`contract` job is manual (workflow dispatch, environment `urssaf-sandbox`).

## Versioning

Semantic Versioning. `SpecVersion::CURRENT` holds the version of the official URSSAF documentation implemented.

<!-- the-bearded-bear:start -->
## Other URSSAF packages

The same architecture, quality gate and conventions, one package per URSSAF API (overview: [the-bearded-bear.com/open-source/urssaf](https://the-bearded-bear.com/open-source/urssaf)):

| API | Package | Status |
|---|---|---|
| Tiers de prestations (immediate tax-credit advance) | [`thebeardedbear/urssaf-tiers-prestations`](https://github.com/TheBeardedBearSAS/urssaf-tiers-prestations) | Released (Packagist) |
| Attestations Vérification Code Sécurité (certificate verification) | [`thebeardedbear/urssaf-attestations-verification-code-securite`](https://github.com/TheBeardedBearSAS/urssaf-attestations-verification-code-securite) | Released (Packagist) |
| DPAE (pre-employment declaration) | [`thebeardedbear/urssaf-dpae`](https://github.com/TheBeardedBearSAS/urssaf-dpae) | Coming soon |
| Mobilité internationale (posting of workers abroad) | [`thebeardedbear/urssaf-mobilite-internationale`](https://github.com/TheBeardedBearSAS/urssaf-mobilite-internationale) | Coming soon |
| Plateforme numérique (withholding of contributions by digital platforms) | [`thebeardedbear/urssaf-plateforme-numerique`](https://github.com/TheBeardedBearSAS/urssaf-plateforme-numerique) | Released (Packagist) |
| Tierce Déclaration CESU | [`thebeardedbear/urssaf-tierce-declaration-cesu`](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-cesu) | Released (Packagist) |
| Tierce Déclaration PAJE | [`thebeardedbear/urssaf-tierce-declaration-paje`](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-paje) | Released (Packagist) |

## Professional support

This package is developed and maintained by [The Bearded Bear](https://the-bearded-bear.com).
If you need help with an URSSAF integration, The Bearded Bear can help with:

- the URSSAF habilitation and homologation process (subscription, sandbox tests, move to production);
- integrating the package into your application (Symfony, Doctrine or framework-free);
- support with a guaranteed response time, including the follow-up of URSSAF API changes;
- custom developments on top of the package.

Project page and integration offer: [the-bearded-bear.com/open-source/urssaf-tierce-declaration-auto-entrepreneur](https://the-bearded-bear.com/open-source/urssaf-tierce-declaration-auto-entrepreneur).
<!-- the-bearded-bear:end -->

## Contributing and security

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in
[SECURITY.md](SECURITY.md), never in a public issue.

## License

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.

The API specification summarised in [docs/api-reference.md](docs/api-reference.md) belongs to URSSAF; see the sources
listed there.
