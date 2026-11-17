# ADR 0010 — Personal and bank data

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

An auto-entrepreneur is a natural person: the data the package handles are personal data of that person
(api-reference §4.11). The requests carry the SIRET (every AE operation, in the query string; 070 in the body, 071 in
the path), the birth date (`dateNaissance`, query string of 030 / 031, §2.2, §2.3), the turnover and the contributions
(§2.4 to §2.6), and the bank data of the SEPA operations: `iban` (060 body, §2.6; 070 body and response, §2.7; 075
response, "anonymised" but returned in clear by the PDF example, §2.9), `bic`, `titulaireCompte`, `adresse1`,
`adresse2`, `codePostal`, `ville`, `pays` (§2.7). The `nir` exists in the contract but is deprecated and never sent
(§4.1, ADR 0008). The reference documents encryption at rest as a DBAL type override; CESU and PAJE minimise what they
store (PAJE ADR 0007). ADR 0000 item 3 already requires masking and `#[SensitiveParameter]`.

## Decision

1. **Never handled.** No NIR value object, input, column or wire field is produced: the request mappers never write
   `nir`, and no response mapper reads it (`MandatSepa` declares no `nir`, §2.7; an unexpected one is ignored as
   any unknown field, ADR 0006).
2. **Never persisted, never logged.** `Iban`, `Bic`, the account holder and the postal address of a SEPA mandate
   (`NewSepaMandate`, `SepaMandateAddress`), whether they come from the host (060, 070) or from URSSAF (070, 075
   responses). They live only in the command, the wire DTO and the returned read model of the call.
   - `PaymentRecord` keeps the `Rum` when the payment used one, never the IBAN, not even hashed: its
     `PaymentFingerprint` only says whether an IBAN was given (ADR 0009). 060 is never resent (ADR 0003); a host
     that calls `PayContributions` again passes the IBAN again.
   - SEPA mandates have no local aggregate (ADR 0009): 070 / 071 / 075 are pass-through, and only the `Rum` reaches
     the outbox (ADR 0014).
3. **Persisted.**
   - The birth date, optional, on the `AeAccount` (given to `TrackAccount`, `NotifyMandate` or `CancelMandate`,
     ADR 0009): 031 needs it after 030 (§2.3). It is a `CalendarDate` (ADR 0000) stored through its own DBAL type
     `urssaf_tdae_birth_date` so that a host can override that type alone to encrypt it (ADR 0016).
   - The SIRET (it is the account key, ADR 0008), the period, the `DeclarationFingerprint` (a SHA-256 that includes
     the turnover lines, which are not stored in clear) and the `DeclarationResult` amounts of a declaration record,
     the amount and period of a payment record, `certificat`, `idDeclaration`, `idTelepaiement`, URSSAF error
     codes, and URSSAF `message` / `description` (they may echo host values, e.g. "Le paramètre iban n'est pas
     valide", §2.6, so they are treated as personal data: masked, never logged). Retention is the host's policy.
4. **Masked.** `Iban::__debugInfo()` and `Iban::masked()` (console output) show the country code and the last four
   characters only (`FR76…0185`); `Bic`, the holder, the address, `NewSepaMandate`, `SepaMandate` (read model of
   070 / 075) and every URSSAF `message` / `description` carrier (`SubmissionError` and its views) are masked
   entirely. None of them has `__toString`. Every parameter carrying a birth date, an IBAN, a BIC, a holder, an
   address line or an URSSAF message is `#[SensitiveParameter]`, in the factories and the
   private constructors (a `CalendarDate` is not masked on its own; reflection tests assert the attribute).
   `Siret::__debugInfo()` shows the last four digits only (ADR 0008); amounts and periods are not masked.
5. **Exception messages** carry the violation code, the operation number, the HTTP status and the field path, never a
   value (ADR 0000 item 3). A violation parameter never holds a SIRET, a birth date or bank data.
6. **Transport errors are sanitised.** The query string holds the SIRET and the birth date (030 / 031) and the path
   holds the SIRET and the RUM (071), so a PSR-18 failure is carried by the internal `TransportError` of ADR 0002,
   which keeps the class name of the foreign exception only, never its message, the URI or a body. Neither the query
   string nor the path is logged.
7. **Logs.** The gateway logs the operation number, the HTTP status, the error codes and the duration. The SIRET is not
   secret but is left out of the log context by default (§4.11); `ApiConfiguration::logSiret()` (bundle
   `log_siret`, default `false`) adds it for hosts that need it.
8. **Events and outbox:** identifiers, periods, states and codes only (ADR 0014).

## Consequences

- A payment ordered with an IBAN cannot be replayed from storage, nor a declaration (the host keeps its turnover and
  calls again with the same input, ADR 0009); a SEPA mandate cannot be rebuilt from storage.
- A Doctrine test asserts that no column is named after `iban`, `bic`, `titulaire`, `adresse`, `code_postal`, `ville`,
  `pays`, `nir` or a turnover line, that `birth_date` exists only on the account table, and that no row of a full
  scenario holds the fictitious IBAN, BIC, holder or address used by the test.
- Fixtures and tests use only the official example values of api-reference §2 or fictitious ones.
- Erasure of what is stored: ADR 0017.
