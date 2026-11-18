# Implementation roadmap

Specification: [api-reference.md](api-reference.md). Architecture, conventions and quality gates are the same as in
`thebeardedbear/urssaf-tiers-prestations` (hexagonal layers enforced by Deptrac, PSR-only runtime, Symfony and
Doctrine bridges, 100 % coverage and MSI).

## Status

Implemented: the domain, the wire mappers, the HTTP gateway, the use cases ([workflow.md](workflow.md)), the in-memory
persistence, the outbox, the `Testing\SimulatedAeGateway` simulator, the standalone builder
([standalone.md](standalone.md)) and the Symfony bundle ([symfony.md](symfony.md): configuration, `urssaf:tdae:*`
commands, Messenger, Scheduler, locks, translations, Flex recipe) and the Doctrine bridge (lot 6,
[doctrine.md](doctrine.md): mapping, repositories, outbox, statistics, `withDoctrine()`, the bundle's `doctrine`
driver). The contract tests are written (`tests/Contract`); they still have to run against the sandbox.

## Already in place (skeleton)

- `SpecVersion::CURRENT` (`1.5.0`, the version of the PDF; production Swagger is 1.5.1, see api-reference §2.10).
- `Infrastructure\Urssaf\Environment` (Sandbox `https://api-edi.urssaf.fr` / Production `https://api.urssaf.fr`) and
  `Infrastructure\Urssaf\UrssafEndpoints` (token path, scope `tiersdecl.ae`, base path `/tiersdecl/v1/ae`).
- Wire fixtures `tests/Fixtures/wire/*.json` (official examples, api-reference §2).

## Domain (`src/Domain`)

- `Account\Siret` (value object: 14 digits), `Account\Account` (mandate present, `Periodicity`, optional affiliation date and
  ACRE flag, both PDF-only), `Account\Periodicity` (enum `M`/`T`).
- `Period\DeclarationPeriod` (sum type `MonthlyPeriod|QuarterlyPeriod`, "not both" unrepresentable; api-reference §4.5).
- `Money\Euros` (whole euros, `int`; api-reference §4.4), `Contribution\TurnoverDeclaration` (the five `CaAe` lines),
  `Contribution\ContributionAmounts` (`MontantsAe`: union of prd and sb, `*VL` optional).
- `Contribution\Estimate`, `Contribution\Declaration` (`certificat`, `idDeclaration`, due date, amounts),
  `Contribution\Payment` (`certificat`, `idTelepaiement`, due date, period).
- `Mandate\BirthDate`/`Mandate\Mandate` (third-party declaration), `Sepa\SepaMandate` (RUM, IBAN, holder, creditor),
  `Sepa\Rum`, `Sepa\Creditor`, `Sepa\NewSepaMandate`, `Sepa\Iban`/`Bic` (minimal validation, never logged).
- `Domain\Exception\*` (one per business code of api-reference §3.4, raw code preserved for unknown ones).

## Application (`src/Application`)

- Port `Port\Urssaf\TierceDeclarationGateway` (`findAccount`, `notifyMandate`, `cancelMandate`, `estimate`, `declare`, `pay`,
  `registerSepaMandate`, `revokeSepaMandate`, `findSepaMandates`).
- Ports `CredentialsProvider`, `TokenStore` (same contracts as Tiers de prestations); optional tracking ports
  `DeclarationRepository`, `PaymentRepository`, `MandateRepository` (implemented by the Doctrine bridge).
- Commands/queries and handlers: `FindAccount`, `NotifyMandate`, `CancelMandate`, `EstimateContributions`,
  `DeclareTurnover`, `PayContributions`, `RegisterSepaMandate`, `RevokeSepaMandate`, `ListSepaMandates`.
  `PayContributions` records the attempt **before** the call and never retries after an unknown outcome (api-reference §4.6).

## Infrastructure (`src/Infrastructure`)

- `Urssaf\Http\OAuth2TokenProvider`, `JsonApiClient`, `RetryAfter` (reuse the Tiers de prestations design).
- `Urssaf\UrssafHttpGateway` + `Urssaf\Wire\*` mappers: direct **and** wrapped responses of 040/050/060, ints and numeric
  strings for periods, `date_etat` as `DD/MM/YYYY`, account-in-query vs account-in-body (070), 204 without body.
- `Urssaf\Exception\ApiErrorCode` (api-reference §3.4), error payload parser (single object or list).
- `ApiConfiguration` (environment + overridable base URI).

## Bridges

- Symfony bundle `UrssafTierceDeclarationAutoEntrepreneurBundle` (alias `urssaf_tierce_declaration_auto_entrepreneur`):
  configuration (credentials, environment, base URI), HTTP client, token cache pool, console commands (account lookup,
  estimate, declare, pay, SEPA mandates).
- Doctrine bridge: mapping and repositories to track mandates, declarations and payments (SIRET, period, amounts,
  `certificat`, `idDeclaration`/`idTelepaiement`, status, timestamps), needed to reconcile payments whose outcome is unknown.

## Runtime dependencies to add with the code

`ext-json`, `ext-mbstring`, `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/clock`, `psr/log`,
`psr/simple-cache` (or `psr/cache`), plus the matching `suggest` entries (Symfony and Doctrine bridges).

## Tests

- Unit: value objects, mappers against `tests/Fixtures/wire/*.json`, handlers with fake gateway, error parser.
- Functional: Symfony bundle wiring, Doctrine mapping.
- Contract (`make test-contract`, manual CI job): sandbox credentials `URSSAF_SANDBOX_CLIENT_ID/SECRET`, optional
  `URSSAF_SANDBOX_BASE_URI`, `URSSAF_SANDBOX_SIRET`, `URSSAF_SANDBOX_BIRTH_DATE` (test data to obtain at onboarding) and
  `URSSAF_SANDBOX_WRITE=1` for the tests that write. First goals: settle the
  prd/sb response shape, `Compte` PDF-only fields, string vs integer periods, `iban`/`rum` rule on `/payer`.

## Out of scope

CESU, PAJE and auto-entrepreneur share `/tiersdecl/v1` and the mandate model: a shared library could be extracted later; not
done here.

## Order of work

1. Domain value objects (`Siret`, `Euros`, `DeclarationPeriod`, amounts).
2. Wire mappers + fixtures (both response shapes).
3. HTTP gateway (OAuth2, errors, 429 handling).
4. Application commands/queries (payment: no auto-retry).
5. Symfony bridge (done), then Doctrine tracking (done).
6. Contract tests (done: `tests/Contract`, `docs/testing.md`), then run them against the sandbox and remove the settled open points from `api-reference.md`.
