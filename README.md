# URSSAF "API Tierce Déclaration auto-entrepreneur" for PHP

`thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur` — client, domain model and Symfony integration for the
URSSAF API that lets a platform, on behalf of an auto-entrepreneur, declare turnover, get the contribution amounts and
order their payment (third-party declaration, *tierce déclaration*).

Namespace: `TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur`.

> [!IMPORTANT]
> **Unofficial package.** This project is an independent open-source implementation of a public API. It is not
> developed, endorsed, certified or supported by URSSAF, and it is not affiliated with URSSAF in any way. "URSSAF" is
> used only to name the API this package talks to. Using this package does not replace the URSSAF habilitation
> process; the URSSAF documentation remains the authority on the API, and you remain responsible for the data your
> application sends to URSSAF.

> 🚧 **Skeleton — not implemented yet.** This repository currently contains the tooling and the specification only.
> The implementation follows [docs/roadmap.md](docs/roadmap.md).

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

## Documentation

- [docs/api-reference.md](docs/api-reference.md) — technical reference (single source of truth for the package).
- [docs/roadmap.md](docs/roadmap.md) — planned architecture and order of work.
- [docs/openapi/](docs/openapi/) — official OpenAPI contracts (production and sandbox) from the URSSAF API portal.
- `docs/reference/` — official URSSAF documentation: not versioned (URSSAF documents), download it from the links in [docs/api-reference.md](docs/api-reference.md) into this local, git-ignored folder.
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/td-ae

## Requirements

PHP 8.2+. Development only needs Docker: every `make` target runs in a container.

## Development

```sh
make install     # build the image, install dependencies
make ci          # full quality gate: cs, rector, phpstan, mago, deptrac, composer checks, guard, coverage, infection
make test        # unit + functional tests
make help        # every target
```

`make ci PHP_VERSION=8.4` runs the gate on another PHP version.

## Versioning

Semantic Versioning. `SpecVersion::CURRENT` holds the version of the official URSSAF documentation implemented.

## Contributing and security

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in
[SECURITY.md](SECURITY.md), never in a public issue.

## License

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.

The API specification summarised in [docs/api-reference.md](docs/api-reference.md) belongs to URSSAF; see the sources
listed there.
