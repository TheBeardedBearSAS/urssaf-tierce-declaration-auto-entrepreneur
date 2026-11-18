# Roadmap

Intended direction for the next twelve months (November 2026 to November 2027). It is a statement of intent, not a
commitment; it is reviewed at each minor release. Proposals are welcome in GitHub issues.

## Planned

- **Validate against the URSSAF sandbox.** The nine operations of the URSSAF *API Tierce Déclaration auto-entrepreneur*
  are implemented (framework-free core, Symfony bundle, Doctrine integration, simulator), but the package has not been
  released and the live contract tests (`make test-contract`) have not run against the sandbox yet. Run them, settle the
  open points they are meant to settle (the response shapes of production and sandbox, optional fields, unpublished
  error codes, see [docs/api-reference.md](docs/api-reference.md) §5), and adapt the package where an answer differs.
- **Release 1.0.** Publish the first release on Packagist, stabilise the public API, then release `1.0.0`.
  From 1.0 on, breaking changes only happen in major versions, as required by [Semantic Versioning](https://semver.org/).
- **Follow the URSSAF API.** Track each new version of the URSSAF swagger and technical documentation (the production
  contract 1.5.1 is ahead of the sandbox contract 1.5.0) and release the matching changes, with upgrade notes in the
  [changelog](CHANGELOG.md).
- **Keep up with the platform.** Support new PHP versions (8.5 and later) and the new Symfony and Doctrine versions
  as they are released, and drop the versions that reach their end of life in a minor release, announced in the
  changelog.
- **Keep the quality and security bar.** 100 % line coverage and 100 % MSI, branch coverage of at least 96 %, PHPStan
  at level max, signed releases, and the OpenSSF Best Practices criteria.

## Not planned

- Other URSSAF APIs: each one gets its own package (see *Other URSSAF packages* in the [README](README.md)).
- A shared library extracted from the sibling third-party declaration packages (CESU, PAJE): the three share the
  `/tiersdecl/v1` path and the mandate model, but each package stays self-contained.
- Official bridges for other frameworks than Symfony: the core works without a framework.
- Features the URSSAF API does not offer: a status endpoint, idempotency keys or a reconciliation lookup. The package
  never resends a payment order (`POST /payer`) on its own.
