# Roadmap

Intended direction for the next twelve months (November 2026 to November 2027). It is a statement of intent, not a
commitment; it is reviewed at each minor release. Proposals are welcome in GitHub issues.

## Planned

- **Complete the API coverage.** Implement the nine operations of the URSSAF *API Tierce Déclaration
  auto-entrepreneur* (account lookup, notification and cancellation of the third-party declaration mandate, contribution
  estimate, turnover declaration, SEPA payment order, registration, revocation and listing of SEPA mandates), with a
  framework-free core, a Symfony bundle and a Doctrine integration. The package is currently a skeleton: see the
  [README](README.md).
- **Release 1.0.** Validate the behaviour against the URSSAF sandbox, stabilise the public API, then release `1.0.0`.
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
- A shared library extracted from the sibling third-party declaration packages (CESU, PAJE).
- Official bridges for other frameworks than Symfony: the core works without a framework.
- Features the URSSAF API does not offer: a status endpoint, idempotency keys or a reconciliation lookup. The package
  never resends a payment order (`POST /payer`) on its own.
