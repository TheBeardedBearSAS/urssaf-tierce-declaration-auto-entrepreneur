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

> 🚧 **Work in progress.** The domain, the HTTP gateway, the use cases, the simulator, the standalone builder, the
> Symfony bundle and the Doctrine bridge are implemented; the contract tests follow [docs/roadmap.md](docs/roadmap.md).

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

OAuth2 client credentials, scope `tiersdecl.ae`, base URL `https://api.urssaf.fr/tiersdecl/v1/ae` (sandbox:
`https://api-edi.urssaf.fr/tiersdecl/v1/ae`). Restricted API: subscription through DataPass.

The production contract (1.5.1) is ahead of the PDF and of the sandbox contract (1.5.0); the differences are listed in
[docs/api-reference.md](docs/api-reference.md) §2.10.

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
- [docs/workflow.md](docs/workflow.md) — lifecycles, re-entry and settlement tables, unknown outcomes, guards, erasure.
- [docs/integration-events.md](docs/integration-events.md) — public integration events and the outbox.
- [docs/adr/](docs/adr/README.md) — architecture decision records (inherited architecture and its adaptations).
- [docs/roadmap.md](docs/roadmap.md) — planned architecture and order of work.
- [docs/openapi/](docs/openapi/) — official OpenAPI contracts (production and sandbox) from the URSSAF API portal.
- `docs/reference/` — official URSSAF documentation: not versioned (URSSAF documents), download it from the links in [docs/api-reference.md](docs/api-reference.md) into this local, git-ignored folder.
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/td-ae

## Requirements

PHP 8.2+. Development only needs Docker: every `make` target runs in a container.

## Development

```sh
make install     # build the image, install dependencies
make ci          # full quality gate: cs, rector, phpstan, mago, deptrac, composer checks, hadolint, guard, coverage, infection
make test        # unit + functional tests
make bench       # benchmarks (PHPBench, tests/Benchmark), added with the implementation
make help        # every target
```

`make ci PHP_VERSION=8.4` runs the gate on another PHP version.

Once the repository is public, `main` publishes its quality proofs, linked from the badges at the top of this file: the
coverage report on Codecov, the full mutation report on the Stryker Dashboard, the SonarQube Cloud quality gate and the
OpenSSF Scorecard. The benchmarks (`tests/Benchmark`, PHPBench in `tools/phpbench`, out of the package dependencies)
run on every pull request against the base commit, and the job fails on a regression of more than 20 %.

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
