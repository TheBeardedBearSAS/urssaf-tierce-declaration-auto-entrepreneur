# ADR 0005 — Classification of URSSAF errors

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

AE errors carry a stable business `code` (free strings in the contract, catalogue in api-reference §3.4), unlike the
CESU `ERR_METIER` that had to be classified by text. The payload is a single `Erreur` object for 020 – 060, and an
array of `ErreurType` for 070 / 071 / 075 in the Swagger while the PDF examples of the same operations show a single
object (§1.3, §5). 401 / 403 / 500 of the AE operations are declared without body (§1.3); 404 (SEPA) has no published
code; 409 is "Ressource déjà existante" (`ERREUR_MANDAT_ALREADYEXIST`, §2.7). Several codes of 030 / 031 / 071 are
*not published* (birth-date mismatch, no mandate to cancel, unknown RUM, invalid `dateEffet`, §5).

## Decision

- `Wire\ErrorPayloadParser` accepts a single object with `code`, a list of such objects, and the envelope
  `{"errors": [...]}` inherited from the reference parser (not documented for AE, harmless). Codes are normalised:
  trimmed and inner whitespace removed (inherited: the PDF breaks codes across lines); case is kept. Entries without a
  code are dropped. `parseBody()` is lenient (unreadable body → no errors; non-object entries of a list skipped) and
  is used for every error status, 400 included: a 400 is a definitive rejection whatever its body, so an unreadable
  400 body is an `UrssafRequestRejected` of kind `unknown` (on 060 `rejected`, never `outcome_unknown`).
  `parsePayload()` (strict) serves tests and fixtures only.
- `Infrastructure\Urssaf\Exception\ApiErrorCode` lists every code of §3.4 verbatim: `PARAM_INVALIDE`,
  `ERR_PARAM_MULTIPLES`, `CPTE_INCONNU`, `ABN_INCONNU`, `SIRET_DEJA_MANDATE`, `MDT_INCONNU`, `MDT_HORS_PERIODE`,
  `APPEL_INCONNU`, `PERIODICITE_INVALIDE`, `ERREUR_MANDAT_ALREADYEXIST`, `ERREUR_IBAN_INVALIDE`,
  `ERREUR_RIB_INVALIDE`, `BANQUE_INCONNUE`, `ERR_TECH`, `ERR_TECHNIQUE`, with `fromCode(): ?self` after
  normalisation. An unknown code is kept raw next to a `null` enum; no code is invented for the unpublished cases.
- `Wire\ErrorClassifier` maps to the port enum `Application\Port\RejectionKind` (backed, wire values used in stored
  errors and events), listed in priority order (the first kind found among the errors of a response wins):

  | Case (value) | Source |
  |---|---|
  | `SepaMandateAlreadyExists` (`sepa_mandate_already_exists`) | `ERREUR_MANDAT_ALREADYEXIST`, or HTTP 409 on 070 **even without body** |
  | `MandateHeldElsewhere` (`mandate_held_elsewhere`) | `SIRET_DEJA_MANDATE` |
  | `AccountUnknown` (`account_unknown`) | `CPTE_INCONNU` |
  | `NoActiveUser` (`no_active_user`) | `ABN_INCONNU` |
  | `MandateMissing` (`mandate_missing`) | `MDT_INCONNU` |
  | `MandateOutOfPeriod` (`mandate_out_of_period`) | `MDT_HORS_PERIODE` |
  | `PeriodNotOpen` (`period_not_open`) | `APPEL_INCONNU` |
  | `PeriodicityMismatch` (`periodicity_mismatch`) | `PERIODICITE_INVALIDE` |
  | `InvalidBankDetails` (`invalid_bank_details`) | `ERREUR_IBAN_INVALIDE`, `ERREUR_RIB_INVALIDE` |
  | `UnknownBank` (`unknown_bank`) | `BANQUE_INCONNUE` |
  | `InvalidParameter` (`invalid_parameter`) | `PARAM_INVALIDE`, `ERR_PARAM_MULTIPLES` |
  | `NotFound` (`not_found`) | HTTP 404 on 070 / 071 / 075, whatever its body |
  | `Technical` (`technical`) | `ERR_TECH` (400), `ERR_TECHNIQUE` |
  | `Unknown` (`unknown`) | any other code, or a 400 without a readable code |

- Classification is by code first; the HTTP status decides only where no code is published (409, 404) or where there
  is no body (401 / 403 → `UrssafAuthenticationFailed`, 429 / 5xx → [ADR 0003](0003-retry-and-unknown-outcome.md)).
  A 400 is never retried, `ERR_TECH` included: §3.4 calls it "technique/fonctionnelle", nothing says that the same
  request may later succeed.
- `UrssafRequestRejected` exposes `kind(): RejectionKind`, `errors(): list<UrssafError>` (raw code, nullable
  `ApiErrorCode`, message, description) and `hasCode(ApiErrorCode)`. Descriptions and messages are kept on the error
  objects but never put in exception messages or logs (they may echo SIRET or bank values, [ADR 0010](0010-personal-data.md)).
- `ERR_PARAM_MULTIPLES` is catalogued but cannot be produced by the package, which never sends `nir`
  ([ADR 0008](0008-siret-and-account-reference.md)).

## Consequences

- The Application never compares a wire string; adding a code (for instance once the sandbox shows the birth-date
  mismatch of 030 / 031) is an Infrastructure change: a new `ApiErrorCode` case and, when needed, a new
  `RejectionKind` by amendment.
- Until then those unpublished cases surface as `invalid_parameter` (when URSSAF sends `PARAM_INVALIDE`) or `unknown`
  with the raw code available to the host.
