# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project skeleton: Docker tooling, quality gates (`make ci`) and GitHub Actions CI, identical to the other URSSAF
  packages.
- Specification: official OpenAPI contracts (`docs/openapi/`), official documentation (links in `docs/api-reference.md`, local copy in the git-ignored `docs/reference/`), technical
  reference (`docs/api-reference.md`) and implementation roadmap (`docs/roadmap.md`).
- Signed releases (workflow `release.yml`): each GitHub release carries the distribution archive, its `SHA256SUMS`
  and a SLSA build provenance attestation (`*.intoto.jsonl`, Sigstore); see *Verifying a release* in SECURITY.md.
- CodeQL analysis of the GitHub Actions workflows (workflow `codeql.yml`) on every pull request and push to `main`.
- Architecture decision records (`docs/adr/`): ADR 0000 states the architecture inherited from
  `thebeardedbear/urssaf-tiers-prestations` and lists the ADRs that adapt it to this API.
- Open-source (silver) tooling and documents: Developer Certificate of Origin sign-off (`bin/check-dco`, CI job `dco`),
  SPDX licence headers (`bin/check-spdx`, part of `make guard`), branch coverage of at least 96 %
  (`make coverage-branches`), `GOVERNANCE.md`, `CODE_OF_CONDUCT.md`, `ROADMAP.md` and the security assurance case
  (`docs/security/assurance-case.md`, in progress).
- Domain model: `Siret`, `DeclarationPeriod` (monthly or quarterly), `Euros` (whole euros), turnover and contribution
  amounts (union of the production 1.5.1 and sandbox 1.5.0 contracts), SEPA mandate value objects, and the `AeAccount`,
  `Mandate`, `DeclarationRecord` and `PaymentRecord` aggregates with their lifecycles.
- Wire mappers for the nine operations (020 to 075), tolerant of the production response wrapper and of string periods,
  error payload parsing (object or list) and classification by code.
- OAuth2 HTTP gateway (`UrssafHttpGateway`, `UrssafGatewayFactory`): token cache, one replay after a 401, payment
  orders (060) never resent, other writes resent only when listed in `retrySafeWrites`, rate limit of 300 requests per
  minute per tenant, PSR-6 / PSR-16 token stores.
- Public integration events (`urssaf.tdae.*`, `docs/integration-events.md`) written to an outbox in the same
  transaction as the state change and relayed at least once; in-memory repositories.
- Use cases: account tracking and sync, mandate notification / cancellation / import, estimate, turnover declaration,
  payment order (claimed before the call, never resent), SEPA mandate registration / revocation / listing, reconciliation
  and manual resolution of unknown outcomes, requeue after invalid credentials, account erasure (GDPR), workflow
  overview (`docs/workflow.md`).
- `Testing\SimulatedAeGateway` and the standalone builder and facade (`docs/standalone.md`).
- Symfony bundle `UrssafTierceDeclarationAutoEntrepreneurBundle` (`docs/symfony.md`): configuration with build-time
  rules, `urssaf:tdae:*` console commands (personal data through hidden prompts or payload files only), Messenger
  account sync, locks, scheduler, outbox sink, diagnostics, translations (en, fr) and a Flex recipe.
- Use cases, simulator, standalone: the commands and queries for accounts, mandates, declarations, payments, SEPA
  mandates, erasure and the outbox (`docs/workflow.md`), the `Testing\SimulatedAeGateway` in-memory platform for the tests
  of a host application, and the framework-free `Standalone\UrssafTierceDeclarationAutoEntrepreneurBuilder`
  (`docs/standalone.md`). Deptrac layers `Testing` and `Standalone`.
- Symfony bundle `UrssafTierceDeclarationAutoEntrepreneurBundle` (`docs/symfony.md`, ADR 0015): configuration tree and
  container wiring of the HTTP gateway, the use cases, the outbox and the lock; the 20 `urssaf:tdae:*` console commands
  (personal data only through hidden prompts and payload files); Messenger handlers for the account sync only; a
  Scheduler schedule of the safety crons; `urssaf:tdae:check`; en / fr translations of the violation codes; a
  `ViolationListConverter`; the Flex recipe (`recipe/`, `bin/build-flex-endpoint`, CI job `flex-endpoint`). The
  `doctrine` persistence driver is declared and refused at build time until the Doctrine bridge.

### Changed

- CI: verifiable quality proofs (Codecov, Stryker Dashboard, SonarQube Cloud, OpenSSF Scorecard; active once the
  repository is public), every GitHub Action pinned to a commit SHA and kept up to date by Dependabot, hadolint on the
  Dockerfile (`make hadolint`, part of `make ci`) with a non-root development image, and benchmarks with PHPBench
  (`make bench`, CI job `benchmark`, isolated in `tools/phpbench`).
- CI: the SonarQube Cloud analysis also requires the repository variable `SONARCLOUD=enabled`, set once the
  project is imported on SonarQube Cloud.

- README: maintainer (The Bearded Bear), the other URSSAF packages and professional support; composer
  `authors` now points to https://the-bearded-bear.com.

- The package is open source under the MIT license (previously proprietary). Added `CONTRIBUTING.md` and
  `SECURITY.md`, and a notice in the README stating that the package is unofficial and not affiliated with URSSAF.
