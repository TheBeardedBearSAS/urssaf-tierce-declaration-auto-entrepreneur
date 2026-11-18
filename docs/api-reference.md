# URSSAF "API Tierce Déclaration auto-entrepreneur" — technical reference

This document is the single source of truth used by the package for enums, validation rules and wire fixtures.
It summarises the official specification:

- Swagger contract, production: **v1.5.1** (`docs/openapi/td-ae-prd.json`, host `api.urssaf.fr`)
- Swagger contract, sandbox: **v1.5.0** (`docs/openapi/td-ae-sb.json`, host `api-edi.urssaf.fr`) — **the two contracts
  differ**, see §2.10 and §5
- Technical documentation PDF: **v1.5.0** (2024-09-24), file name `…_v1.5.0.pdf`
  (`docs/reference/ACOSS-Documentation-API-TDAEV1_v1.5.0.pdf`)
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/td-ae (`apiId` `td-ae`)
- PDF: https://cedito-medias.urssaf.fr/medias/PortailAPI/files/documentationAPIs/TDAE/ACOSS-Documentation-API-TDAEV1_v1.5.0.pdf

The package constant `SpecVersion::CURRENT` must equal `1.5.0`. Rule shared by the URSSAF packages: `SpecVersion` is
the version in the **file name of the published PDF** (the most detailed source), falling back to the Swagger version
when the PDF is not versioned. Here the PDF (1.5.0) and the sandbox Swagger (1.5.0) agree, while the **production
Swagger (1.5.1) is ahead of the PDF**: the PDF has no 1.5.1 revision entry (its history stops at 1.5.0, 2024-09-24).
Field names, codes and wire values below are reproduced verbatim from the specification (they are French by design);
everything else in the package is English. Statements marked *inferred from the example* are not specified, and
"not published" means neither the contract nor the PDF says anything.

> Status: implemented (not yet released); the live sandbox contract tests ([testing.md](testing.md)) still have to run, see §5.
> §1 to §3 summarise the official specification; §4 records the decisions taken by the package, each linked to its
> architecture decision record ([adr/](adr/README.md)); §5 lists what stays open. Implementation plan: [roadmap.md](roadmap.md).

> **Source and rights.** The specification, the field names, the codes and the official JSON examples quoted here come
> from the URSSAF documents listed above and remain URSSAF's property. They are reproduced only so the package can
> interoperate with the API, and they are not covered by this package's MIT license. In case of discrepancy, the
> official URSSAF documentation prevails. The official documents themselves are not versioned: download them into
> `docs/reference/`, a local git-ignored folder. This package is not affiliated with URSSAF.

---

## 1. General

| Topic | Value |
|---|---|
| Purpose | On behalf of an auto-entrepreneur (AE, *micro-entrepreneur*): look up the account, notify / cancel a third-party declaration mandate (*mandat de tierce déclaration*), estimate the social contributions from turnover figures, declare the turnover, order the SEPA payment of the contributions, and manage the SEPA mandates used for that payment. |
| Access | Catalogue: restricted offer, subscription through DataPass. PDF §2.1: "offre API à accès restreint", access request validated by ACOSS. Credentials (client id / client secret) issued after approval. |
| Security | OAuth2 **client credentials** (`securityHeader`, flow `application`) |
| Token endpoint | `POST https://api.urssaf.fr/api/oauth/v1/token` (form-urlencoded: `grant_type=client_credentials`, `client_id`, `client_secret`, `scope`). The sandbox Swagger declares the same production `tokenUrl`; the sandbox token host is not published (see §5). |
| Scope | `tiersdecl.ae` ("Accès à l'API Tierce Déclaration auto-entrepreneur") |
| Production base URL | `https://api.urssaf.fr/tiersdecl/v1/ae` |
| Sandbox base URL | `https://api-edi.urssaf.fr/tiersdecl/v1/ae` (host from the sandbox Swagger; must stay configurable) |
| Content type | `application/json` (request and response). Parameters of the mandate operations are in the query string (no body). |
| Headers | None declared in either contract. Not published: `x-request-id` / `request.id` / `recouv.application.id` as used by other URSSAF APIs. |
| HTTP errors | 200, 204 (SEPA revocation), 400, 401, 403, 404 and 409 (SEPA operations only), 429 (quota, PDF), 500 |

Package side (already in place): `Infrastructure\Urssaf\Environment` is an enum (`Sandbox` => `https://api-edi.urssaf.fr`,
`Production` => `https://api.urssaf.fr`, method `baseUri()`); `Infrastructure\Urssaf\UrssafEndpoints` holds
`TOKEN_PATH = /api/oauth/v1/token`, `TOKEN_SCOPE = tiersdecl.ae` and `API_BASE_PATH = /tiersdecl/v1/ae`. Full URL =
`Environment::baseUri()` + `API_BASE_PATH` + operation path; the token URL is `Environment::baseUri()` + `TOKEN_PATH`.

Operations are numbered `API0xx` in the contract (`summary`): 020 account, 030/031 mandate, 040 estimate, 050 declare,
060 pay, 070/071/075 SEPA mandates.

### 1.1 Quotas (HTTP 429 when exceeded)

PDF §2.5: throttling of **300 requests per minute**, on two levels: a maximum quota per API (service availability) and a
quota per API client. HTTP 429 ("Too many requests") when exceeded. No per-operation limit, no `Retry-After` / rate
headers, and no 429 response in the Swagger: all *not published*.

### 1.2 Known limitations (official)

- PDF §1.2: **no multi-platform**: an AE cannot mandate several platforms as third-party declarant at the same time
  (error `SIRET_DEJA_MANDATE`, §2.2).
- The **SIRET is the identifier to use**: `siret` and `nir` are both optional in the contract, but the PDF states
  "Le SIRET est nécessaire au bon fonctionnement du service" and flags `nir` as *déprécié*. Sending both is rejected
  (`ERR_PARAM_MULTIPLES`); sending none gives `PARAM_INVALIDE`.
- Amounts are **whole numbers, no decimals** (cents are not counted). Rounding rule (PDF §3.5.1, DGFiP): up when the
  decimals are >= 0.50 (1458,53 and 1458,50 become 1459), down when < 0.50 (2365,47 becomes 2365).
- The declaration period must be **open** to process a declaration (`APPEL_INCONNU` otherwise, §2.5).
- Several successive declarations on the same account and the same (open) period: **the last one cancels and replaces
  the previous one** (PDF §3.5.1).
- **First declaration after the start of activity**: a minimum of 90 days must elapse between the start date and the first
  declaration, depending on the chosen periodicity (PDF §3.5.1, tables reproduced in §3.5).
- Automatic e-mails to the AE are triggered by: mandate notification (030), mandate cancellation (031), declaration
  (050), payment (060), SEPA mandate registration (070, once validated) and revocation (071, once revoked).
- The platform **must** call API 071 to revoke a SEPA mandate as soon as the user asks for it (PDF §3.8.1).
- Estimation (040): "pour accéder à ce service, le SIRET doit être mandaté par la plateforme"; it relies on the
  characteristics of the last contribution call (*appel de cotisations*) created for the AE, or on default values.
  Example (PDF): in April one can estimate March (monthly) or Q1; in May, April (monthly) or Q1.

### 1.3 Error payload

AE operations (020, 030, 031, 040, 050, 060) declare a single `Erreur` object (HTTP 400, schema `Erreur`:
`code`, `message`, `description`, all strings), as in the PDF examples:

```json
{ "code": "CPTE_INCONNU", "message": "Compte inconnu", "description": "Aucun compte Auto-Entrepreneur actif trouvé avec ces paramètres" }
```

SEPA mandate operations (070, 071, 075) declare an **array** of `ErreurType` (same three fields) for 400, 401, 404,
409 and 500, while the PDF examples for the same operations show a single object. The package must accept both shapes
(object or list). Technical error (500):
`{"code":"ERR_TECHNIQUE","message":"Erreur technique","description":"Une erreur système a empêché la récupération des informations sur la période demandée"}`.

401 / 403 / 500 of the AE operations are declared **without body** in the Swagger ("Token d'authentification absent ou
invalide", "Authentification refusée", "Erreur technique"); the PDF shows a body only for 500. Business error codes are
free strings (not an enum in the contract): the catalogue is in §3.4. Fixtures: `error_*.json`.

---

## 2. Operations

Common to the AE operations 020, 030, 031, 040, 050, 060 — **query parameters identifying the account**:

| Field | Type | Req. | Rules |
|---|---|---|---|
| `siret` | string | no (contract) / yes in practice (PDF) | max 14 chars (no `minLength` here). SIRET of the AE, e.g. `12345678901234` |
| `nir` | string | no | max 13 chars. *Déprécié* (PDF). Not to be combined with `siret` (`ERR_PARAM_MULTIPLES`). A NIR has 13 digits (15 with its key): format not published for these operations |

### Prerequisites and flow

1. **Mandate (third-party declaration)** — 030 `POST /mandats` records that the platform holds a mandate from the AE.
   The `dateNaissance` sent must match the birth date known by URSSAF (supplied when the activity was created).
   `GET /comptes` (020) tells whether a mandate is present (`mandatPresent`) and the declarative periodicity (`M`/`T`).
   Estimate, declare and pay all require the mandate (`MDT_INCONNU` otherwise); declare/estimate must fall in the period
   covered by the mandate (`MDT_HORS_PERIODE`). 031 `DELETE /mandats` cancels it.
2. **SEPA mandate** — required to *pay* by direct debit: 070 `POST /sepa/mandats` registers one for the account
   (response carries the `rum`, the unique mandate reference, and the URSSAF creditor), 075
   `GET /sepa/mandats/rechercher` lists them (IBAN anonymised), 071 revokes one (effective date at least tomorrow). Whether
   060 *requires* a registered mandate is not stated: `iban` and `rum` are both optional in `InputTelepaiement`, and the
   rule combining them is *not published* (the PDF example sends both).
3. **Estimate → declare → pay**: 040 `/estimer` computes the contributions (`montants`) from the turnover (`ca`), without
   recording anything (the `periode` is returned, derived by the system); 050 `/declarer` records the turnover for an
   explicit `periode` (open) and returns the amounts due, the due date, the `certificat` and the `idDeclaration`; 060
   `/payer` initialises the SEPA payment for a `montant` and a `periode` and returns `certificat`, `dateExigibilite`
   and `idTelepaiement`. The contract does not link 060 to an `idDeclaration`: the link is the account + `periode`.

Value actually due after a declaration (PDF §3.5.3): `montantTotal + montantRegulAcre + montantDeduction`.

### 2.1 Look up an account — `GET /comptes`

`operationId`: `rechercherCompteAE` (API020 "Récupération de compte URSSAF AE"). Finds an AE account from its SIRET (or
NIR) and returns the status of its enablement to the third-party declaration services and its declarative periodicity.

Query parameters: `siret`, `nir` (common table above). Example:
`GET https://api.urssaf.fr/tiersdecl/v1/ae/comptes?siret=12345678901234`.

Response 200 (`Compte`, "Compte trouvé"):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `mandatPresent` | boolean | yes | Whether a third-party declaration mandate exists for this account **and the calling platform** |
| `periodicite` | string | yes | max 1 char, enum `M` (monthly) / `T` (quarterly), see §3.1 |
| `date_etat` | string | PDF only | Affiliation date (start of activity), format **`DD/MM/YYYY`**. **Absent from both Swagger contracts** (see below) |
| `exoAccrePresent` | boolean | PDF only | `true` when the AE benefits from the ACRE exemption. **Absent from both Swagger contracts** |

**Discrepancy**: the PDF (v1.3.4 change log: "Ajout des nouvelles données retournées pour la méthode 020") documents and
exemplifies `date_etat` and `exoAccrePresent`; neither Swagger (prd 1.5.1, sb 1.5.0) declares them. The package maps them
as optional (see §4 and §5). Note `date_etat` is the only snake_case field of the API.

Errors (HTTP 400, schema `Erreur`): `PARAM_INVALIDE` (e.g. "Le paramètre SIRET ne doit pas dépasser 14 caractères"),
`CPTE_INCONNU` (no active AE account), `ABN_INCONNU` (account exists but no active enabled user; "ce cas doit être
rarissime"). 401, 403, 500 without body (500 body in the PDF: `ERR_TECHNIQUE`).

Fixtures: `account_response.json` (the PDF example, including the two PDF-only fields), `error_param_invalide.json`,
`error_cpte_inconnu.json`, `error_abn_inconnu.json`, `error_technique.json`.

### 2.2 Notify a mandate — `POST /mandats`

`operationId`: `enregistrerMandatAE` (API030 "Notification de mandat de tierce déclaration"). Notifies URSSAF that the
platform obtained a third-party declaration mandate for this individual. Triggers an automatic e-mail to the AE. No body.

Query parameters: `siret`, `nir` (common table) and:

| Field | Type | Req. | Rules |
|---|---|---|---|
| `dateNaissance` | string (`date`) | yes | Birth date of the AE, `yyyy-MM-dd` (e.g. `1975-10-01`). Checked by URSSAF against the birth date it holds |

Example: `POST …/ae/mandats?siret=12345678901234&dateNaissance=1975-10-01`.

Response 200 ("Mandat ajouté"): **no body** (PDF: "N/A", "http code 200"). The Swagger lists no `consumes` for this POST
(it does for the DELETE, without a body).

Errors (HTTP 400): `PARAM_INVALIDE` (e.g. NIR not numeric), `SIRET_DEJA_MANDATE` ("Il existe déjà un autre établissement
mandataire pour ce SIRET": another platform already holds the mandate, no multi-platform, §1.2), the Swagger also says
"Compte inconnu" (`CPTE_INCONNU`, §3.4). The birth-date mismatch error code is *not published*.

Fixtures: `error_siret_deja_mandate.json`, `error_param_invalide.json`.

### 2.3 Cancel a mandate — `DELETE /mandats`

`operationId`: `annulerMandatAE` (API031 "Annulation de mandat de tierce déclaration"). Notifies URSSAF that the mandate
was cancelled; triggers an automatic e-mail to the AE. Same query parameters as §2.2 (`siret`, `nir`, `dateNaissance`
required, same birth-date check).

Example: `DELETE …/ae/mandats?siret=12345678901234&dateNaissance=1975-10-01`.

Response 200 ("Mandat annulé", no body).

Errors (HTTP 400): `PARAM_INVALIDE` (the PDF example is garbled: it shows a second `description`, "Aucun mandat à supprimer
correspondant à vos données d'entrée", which suggests a "no mandate to delete" case whose code is *not published*),
`CPTE_INCONNU`.

Fixtures: `error_cpte_inconnu.json`, `error_param_invalide.json`.

### 2.4 Estimate contributions — `POST /estimer`

`operationId`: `estimerCotisationsAE` (API040 "Estimation des cotisations sociales"). Estimates the social contributions
due to URSSAF for the account and the turnover figures supplied. Requires the mandate (§ Prerequisites).

Query parameters: `siret`, `nir` (common table). Body `ca` (`InputEstimation`, required):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `ca` | `CaAe` | yes | Turnover figures, see below |

`CaAe` (all fields optional, `number` with `format: int32`, whole euros, §1.2; shared with 050):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `caVente` | number (int32) | no | CA *Vente Marchandises* |
| `caLocation` | number (int32) | no | CA *Location de meublé de tourisme classé* |
| `caPrestationService` | number (int32) | no | CA *Prestations de services commerciales ou artisanales* |
| `caPrestationAutres` | number (int32) | no | CA *Autres prestations de service*, for non-regulated liberal professions (not CIPAV) and for craftsmen / traders doing secondary-activity BNC services |
| `recettesCipav` | number (int32) | no | *Recettes relevant de la CIPAV* (liberal professions only, "uniquement pour les PL") |

Example body (PDF): `{"ca": {"caVente": 1000, "caLocation": 2000, "caPrestationService": 3000, "caPrestationAutres": 4000, "recettesCipav": 0}}`.
Non-numeric turnover is rejected with `PARAM_INVALIDE` ("Le paramètre caVente n'est pas une valeur numérique").
The `date` field of the former `InputEstimation` (expected collection date) is **deprecated and no longer used** since
PDF 1.5.0 (change log: "Suppression de la date transmise dans les données d'entrée ainsi que la date d'exigibilité en
retour") and is **absent from both Swagger contracts**: the package never sends it.

Response 200 ("Calcul effectué"). **Production Swagger (1.5.1)**: `OutputEstimations`, an object wrapping one optional
property named `OutputDeclaration` (sic) that references `OutputEstimation`. **Sandbox Swagger (1.5.0) and PDF**:
`OutputEstimation` directly, as in the example. Which shape the production service actually returns is *not
published* (§2.10, §5); the package must accept both (§4). `OutputEstimation`:

| Field | Type | Req. | Rules |
|---|---|---|---|
| `montants` | `MontantsAe` | sb + PDF: yes / prd: no | Computed amounts, see `MontantsAe` below |
| `periode` | `Periode` | sb + PDF: yes / prd: no | Period the estimate relates to (derived by the system), see §2.5 |

`MontantsAe` (all `number`, `format: int32`; only `montantTotal` required; the example omits the other optional fields
when not applicable and sends the amounts as plain integers):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `montantTotal` | number (int32) | yes | Swagger title: "Montant total à payer (montant total de la déclaration + montant régularisation Acre − montant Déduction)". PDF §3.4.3 title: "Montant total de la déclaration"; PDF §3.5.3: "somme des champs montantX" and real amount due = `montantTotal + montantRegulAcre + montantDeduction`. **The Swagger and the PDF contradict each other** on whether `montantTotal` already includes the regularisation and deduction (§5) |
| `montantVente` | number (int32) | no | Contribution on `caVente` |
| `montantVenteVL` | number (int32) | **prd only** | Same, *versement libératoire* (income-tax lump-sum option) |
| `montantLocation` | number (int32) | no | On `caLocation` |
| `montantLocationVL` | number (int32) | **prd only** | Same, *versement libératoire* |
| `montantPrestationService` | number (int32) | no | On `caPrestationService` |
| `montantPrestationServiceVL` | number (int32) | **prd only** | Same, *versement libératoire* |
| `montantPrestationAutres` | number (int32) | no | On `caPrestationAutres` |
| `montantPrestationAutresVL` | number (int32) | **prd only** | Same, *versement libératoire* |
| `montantFormationProfessionnelle` | number (int32) | no | Vocational training contribution |
| `montantCipav` | number (int32) | no | On `recettesCipav` (liberal professions only) |
| `montantCipavVL` | number (int32) | **prd only** | Same, *versement libératoire* |
| `taxeCMAVente` | number (int32) | no | CMA chamber fee on sales (artisans liable to it only) |
| `taxeCMAService` | number (int32) | no | CMA chamber fee on services (artisans liable to it only) |
| `taxeCCIVente` | number (int32) | no | CCI chamber fee on sales (traders liable to it only) |
| `taxeCCIService` | number (int32) | no | CCI chamber fee on services (traders liable to it only) |
| `montantRegulAcre` | number (int32) | no | Regularisation after exceeding the ACRE ceiling on the previous period |
| `montantDeduction` | number (int32) | no | Amount to deduct |

Errors (HTTP 400): `PARAM_INVALIDE` (no SIRET and no NIR; NIR not numeric; non-numeric turnover; the PDF also lists
three stale examples about a `date` parameter), `ERR_PARAM_MULTIPLES` ("Un seul paramètre dois être valorisé" [sic]),
`CPTE_INCONNU`, `MDT_INCONNU` (no mandate), `MDT_HORS_PERIODE` (mandate signed after the due date of the requested
period). Business code catalogue: §3.4.

Fixtures: `estimate_request.json`, `estimate_response.json` (PDF example; the PDF text lost the closing quote of
`"trimestre" : "1"`, restored; it shows `annee`/`trimestre` as **strings** while the contract types them `integer`),
`error_param_multiples.json`, `error_mdt_inconnu.json`, `error_mdt_hors_periode.json`.

### 2.5 Declare turnover — `POST /declarer`

`operationId`: `declarerCotisationsAE` (API050 "Télédéclaration de chiffres d'affaires"). Declares the turnover for the AE
and returns the contributions due for the period. Requires the mandate and an **open** period; the last declaration on an
open period replaces the previous one (§1.2). Triggers an automatic e-mail to the AE.

Query parameters: `siret`, `nir` (common table). Body `ca` (`InputDeclaration`, required):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `ca` | `CaAe` | yes | Turnover figures, see §2.4 (the PDF titles `caPrestationService` "CA Prestations de service commercial ou artisanale" here) |
| `periode` | `Periode` | yes | Declaration period |

`Periode` ("période déclarative mensuelle ou trimestrielle, les attributs trimestre et mois ne peuvent être renseignés
simultanément"; shared with 040 output, 060 input and output):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `annee` | integer (int32) | yes | Year, example `2019` |
| `trimestre` | integer (int32) | no | Quarter number `[1..4]`, example `1`; "saisi uniquement si période trimestrielle" |
| `mois` | integer (int32) | no | Month number `[1..12]`, example `12`; "saisi uniquement si période mensuelle" |

The mutual exclusion and the ranges are stated in prose only (no `minimum`/`maximum`/`enum` in the Swagger). The period must
match the account periodicity (`periodicite`, §2.1): otherwise `PERIODICITE_INVALIDE`. The PDF examples send the three
fields as **strings** (`"annee" : "2019"`); the contract says integers. The curl example of the PDF has lost the colon
after `"annee"` (extraction/typing artefact), restored in the fixture.

Example body (PDF): `{"ca": {…as §2.4…}, "periode": {"annee": "2019", "trimestre": "3"}}`.

Response 200 ("Déclaration effectuée"). **Production Swagger (1.5.1)**: `OutputDeclarations`, an object wrapping one
optional `OutputDeclaration` property. **Sandbox Swagger (1.5.0) and PDF**: `OutputDeclaration` directly (§2.10).
`OutputDeclaration`:

| Field | Type | Req. | Rules |
|---|---|---|---|
| `montants` | `MontantsAe` | yes | Amounts due for the period (§2.4) |
| `dateExigibilite` | string (`date`) | yes | Due date, ISO `YYYY-MM-DD` (example `2019-04-30`; PDF response example `2020-01-31`) |
| `certificat` | string | yes | URSSAF certificate number of the declaration, pattern `^\d{8}$` (8 digits). Identifier of the declaration/payment summary shown on www.autoentrepreneur.urssaf.fr; **stays identical when the declaration changes** |
| `idDeclaration` | string | yes | max 23 chars (example `W1dl9W2Oa4R6ab3UlQAqxF3`, 23 chars). Technical identifier unique to each declaration or payment; **changes when the payment or declaration piece is modified** |

Errors (HTTP 400): `PARAM_INVALIDE` (no SIRET/NIR, non-numeric turnover, absent / incomplete / invalid period; the PDF
also carries a stale "Le paramètre date n'est pas renseigné"), `ERR_PARAM_MULTIPLES`, `CPTE_INCONNU`, `MDT_INCONNU`,
`APPEL_INCONNU` ("Période non ouverte pour le compte de votre demande"), `PERIODICITE_INVALIDE`, `MDT_HORS_PERIODE`.

Early-activity schedule (PDF §3.5.1, images): see §3.5.

Fixtures: `declare_request.json`, `declare_response.json`, `error_appel_inconnu.json`, `error_periodicite_invalide.json`,
`error_mdt_inconnu.json`, `error_mdt_hors_periode.json`.

### 2.6 Pay contributions — `POST /payer`

`operationId`: `payerCotisationsAE` (API060 "Télépaiement SEPA"). Initialises a SEPA payment by URSSAF on the IBAN passed
as parameter; triggers an automatic e-mail to the AE.

Query parameters: `siret`, `nir` (common table). Body `paiement` (`InputTelepaiement`, required):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `montant` | number (int32) | yes | Amount to pay, whole euros |
| `iban` | string | no | IBAN to debit. No length or pattern here (the SEPA mandate schema allows max 34) |
| `rum` | string | no | Unique SEPA mandate reference, max 35 chars, example `T1234567891234567891234568912345678` |
| `periode` | `Periode` | yes | Period paid, see §2.5 |

Example (PDF): `{"montant": 88, "iban": "FR7630001007941234567890185", "rum": "T1234567891234567891234568912345678", "periode": {"annee": "2019", "mois": "12"}}`.

Response 200 ("Paiement enregistré"). **Production Swagger (1.5.1)**: `OutputTelepaiements` wrapping one optional
`OutputTelepaiement` property; **sandbox Swagger and PDF**: `OutputTelepaiement` directly (§2.10).

| Field | Type | Req. | Rules |
|---|---|---|---|
| `periode` | `Periode` | yes | Period paid |
| `dateExigibilite` | string (`date`) | yes | Due date of the payment, ISO date (PDF example `2020-01-31`) |
| `certificat` | string | yes | URSSAF certificate number of the payment, `^\d{8}$` |
| `idTelepaiement` | string | no | max 23 chars (example `W1dl9W2Oa4R6ab3UlQAqxF2`) |

Errors (HTTP 400): `PARAM_INVALIDE` (e.g. "Le paramètre iban n'est pas valide"), `CPTE_INCONNU`, `MDT_INCONNU`. Whether
`ERR_PARAM_MULTIPLES`, `APPEL_INCONNU`, `PERIODICITE_INVALIDE` and `MDT_HORS_PERIODE` also apply is *not published*.
Idempotency of the payment, duplicate detection, minimum/maximum amount and the relation between `montant` and the
declared amount are *not published* (§4.5).

Fixtures: `pay_request.json`, `pay_response.json`, `error_param_invalide.json`, `error_cpte_inconnu.json`,
`error_mdt_inconnu.json`.

### 2.7 Register a SEPA mandate — `POST /sepa/mandats`

`operationId`: `post-mandat-by-compte` (API070 "Enregistrement d'un mandat SEPA pour un compte identifié par son siret ou
nir"). Registers a SEPA mandate for an account; an automatic e-mail is sent to the AE once the mandate is validated. The
account is identified by `siret` / `nir` **in the body** (no query parameter on this operation).

Body (`MandatSepaToAdd`, declared `required: false` as a whole in the contract, while the fields below are required):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `bic` | string | yes | max 11 chars, example `ABCDEFGHIJK` ("Identifiant international de la banque") |
| `iban` | string | yes | max 34 chars, example `FR0012345123451234567891A12` ("Identifiant international du compte bancaire") |
| `titulaireCompte` | string | yes | max 80 chars, example `Maxime LEFORESTIER` (account holder) |
| `adresse1` | string | yes | max 32 chars, example `13 boulevard vauban` |
| `adresse2` | string | no | max 32 chars |
| `codePostal` | string | yes | max 5 chars, example `59000` |
| `pays` | string | yes | max 80 chars, example `FRANCE` (country name, not a code) |
| `ville` | string | yes | max 26 chars, example `LILLE` |
| `siret` | string | no | exactly 14 chars (`minLength` = `maxLength` = 14), example `12345678912345`. The PDF says the mandate is registered for an account "identifié par son siret" |
| `nir` | string | no | 13 to 15 chars, example `180107500500623`. *Déprécié* |

Whether `siret` or `nir` is mandatory is not expressed (both optional in the schema); the package requires `siret`.
No pattern is published for `bic`, `iban`, `codePostal`.

Response 200 ("OK - mandat SEPA ajouté"): `MandatSepa` (fields all optional in the contract; "objet mandat SEPA retourné par
l'API lors d'un ajout ou d'une demande de consultation de mandat SEPA pour un compte"):

| Field | Type | Req. | Rules |
|---|---|---|---|
| `rum` | string | no | Unique mandate reference, max 35 chars, example `T1234567891234567891234568912345678` |
| `nomBanque` | string | no | Bank name (the PDF example literally returns `"string"`) |
| `iban` | string | no | max 34. **Anonymised** on search (075), PDF example returns it in clear on add |
| `titulaireCompte` | string | no | max 80 |
| `siret` | string | no | 14 chars |
| `creancier` | `Creancier` | no | URSSAF creditor (added in PDF 1.4.0, "Evolution de l'API pour retourner les informations du créancier") |

`Creancier` (all optional): `nom` (string, "Nom de l'Urssaf", example `URSSAF de BAYONNE`), `ics` (string, creditor
identifier, example `FR47ZZZ142239`), `adresse1`, `adresse2` (string, max 32), `codePostal` (max 5), `pays` (max 80),
`ville` (max 26). The PDF spells the keys `addresse1`/`addresse2` in its description table but the example and the
Swagger use `adresse1`/`adresse2`. The example returns `"ville": "Rennes cedex 9 "` (trailing space): trim.

Errors: 400 (functional), 401 ("erreur de droit d'accès - Non Autorisé"), 404, 409 ("Ressource déjà existante"), 500, all
`ErreurType[]` in the Swagger. Codes in the PDF: `ERREUR_MANDAT_ALREADYEXIST` (409: mandate already active for this SIRET and
IBAN), `ERREUR_IBAN_INVALIDE` (400), `BANQUE_INCONNUE` (400, bank not in the referentials), `ERREUR_RIB_INVALIDE` (400, IBAN
too short / too long), generic `ERR_TECH` (400), `ERR_TECHNIQUE` (500). The PDF gives a payload only for `ERR_TECH`,
`ERREUR_MANDAT_ALREADYEXIST` and `ERR_TECHNIQUE`; for the three others only the bare code is documented.

Fixtures: `sepa_mandate_add_request.json`, `sepa_mandate_response.json`, `error_sepa_generic.json`,
`error_sepa_mandate_already_exists.json`.

### 2.8 Revoke a SEPA mandate — `DELETE /sepa/mandats/{siret}/{rum}`

`operationId`: `delete-mandat-by-compte` (API071 "Révocation d'un mandat SEPA d'un compte"). Revokes a SEPA mandate from the
account SIRET and the mandate RUM; the effective date is at least **today + 1**. An automatic e-mail is sent to the AE once
revoked. The platform must call it as soon as the user asks (§1.2).

| Field | Type | Req. | Rules |
|---|---|---|---|
| `siret` (path) | string | yes | "siret identifiant un compte", exactly 14 chars |
| `rum` (path) | string | yes | "identifiant unique du mandat SEPA", max 35 chars |
| `dateEffet` (query) | string (`date`) | no | Effective date of the revocation, `yyyy-mm-dd`, minimum = today + 1. Default when omitted: *not published* |

Example: `DELETE …/ae/sepa/mandats/12345678912345/T1234567891234567891234568912345678?dateEffet=2020-07-20`. The PDF prints the
endpoint as `DELETE …/ae/sepa/mandats` (path parameters omitted); the Swagger and the example are authoritative.

Response **204** ("OK - mandat SEPA révoqué", no body). Errors: 400, 401, 404, 500 (`ErreurType[]`); PDF: `ERR_TECH` (400),
`ERR_TECHNIQUE` (500). Business error codes for an unknown RUM / past date are *not published*.

Fixtures: `error_sepa_generic.json`, `error_technique.json`.

### 2.9 List the SEPA mandates of an account — `GET /sepa/mandats/rechercher`

`operationId`: `get-mandats-by-compte` (API075 "Récupération de la liste des mandats SEPA rattachés à un compte à partir
de son siret ou nir").

| Field | Type | Req. | Rules |
|---|---|---|---|
| `siret` (query) | string | no | exactly 14 chars; "siret rattaché au compte permettant la recherche de mandats SEPA" |
| `nir` (query) | string | no | 13 to 15 chars. *Déprécié* (PDF). Note: `maxLength` is 13 on the other operations |

Example: `GET …/ae/sepa/mandats/rechercher?siret=12345678912345`.

Response 200: array of `MandatSepa` (§2.7), "liste des mandats SEPA avec iban anonymisé" (the PDF example returns the IBAN in clear).
Behaviour when the account has no mandate (empty array vs 404): *not published*. Errors: 400, 401, 404, 500 (`ErreurType[]`);
PDF: `ERR_TECH` (400), `ERR_TECHNIQUE` (500).

Fixtures: `sepa_mandates_search_response.json` (the PDF example misses a comma after `"siret"`, restored),
`error_sepa_generic.json`.

### 2.10 Differences between the production (1.5.1) and sandbox (1.5.0) contracts

Obtained by a structural diff of `td-ae-prd.json` and `td-ae-sb.json` (every key/value; all 8 paths, parameters, status
codes, schemas and security definitions not listed here are identical). **Package consequence**: the models must be the
union of both and the mappers tolerant (§4.2, §4.3).

| # | Where | Production 1.5.1 | Sandbox 1.5.0 |
|---|---|---|---|
| 1 | `info.version` | `1.5.1` | `1.5.0` |
| 2 | `host` | `api.urssaf.fr` | `api-edi.urssaf.fr` |
| 3 | `MontantsAe` | has 5 extra *versement libératoire* fields: `montantVenteVL`, `montantLocationVL`, `montantPrestationServiceVL`, `montantPrestationAutresVL`, `montantCipavVL` (number, int32) | none of them |
| 4 | Response schema of `POST /estimer` | `OutputEstimations`: object with one optional property named `OutputDeclaration` (sic) → `OutputEstimation` | `OutputEstimation` directly |
| 5 | Response schema of `POST /declarer` | `OutputDeclarations`: object with one optional property `OutputDeclaration` → `OutputDeclaration` | `OutputDeclaration` directly |
| 6 | Response schema of `POST /payer` | `OutputTelepaiements`: object with one optional property `OutputTelepaiement` → `OutputTelepaiement` | `OutputTelepaiement` directly |
| 7 | `OutputEstimation.required` | none | `["montants", "periode"]` |
| 8 | Operation tags (documentation only) | `Compte` (020, 030, 031), `Cotisations` (040, 050, 060), `Mandats SEPA` (070, 071, 075) | `ANNABEL-BE`, `DECLTI`, `TELEDEP-BE` |
| 9 | `MontantsAe.montantRegulAcre.title` | no trailing space | trailing space (cosmetic) |

Items 4 to 6 look like a code-generation artefact (wrapper types named after the item); the production 1.5.1 (not covered by the PDF) may
or may not wrap the real JSON. The PDF 1.5.0 examples are unwrapped and match the sandbox. `securityDefinitions` (including
the production `tokenUrl`) and `info.description` are identical.

---

## 3. Reference values

### 3.1 Periodicity (`Compte.periodicite`)

| Code | Meaning |
|---|---|
| `M` | Monthly declarations (*mensuelle*) |
| `T` | Quarterly declarations (*trimestrielle*) |

### 3.2 Period (`Periode`)

Monthly period: `annee` + `mois` (1-12). Quarterly period: `annee` + `trimestre` (1-4). Never both (§2.5).

### 3.3 Amount fields

`CaAe` field → `MontantsAe` fields (§2.4):

| Turnover (`CaAe`) | Contribution | *Versement libératoire* (prd only) | Chamber fees |
|---|---|---|---|
| `caVente` | `montantVente` | `montantVenteVL` | `taxeCMAVente`, `taxeCCIVente` |
| `caLocation` | `montantLocation` | `montantLocationVL` | — |
| `caPrestationService` | `montantPrestationService` | `montantPrestationServiceVL` | `taxeCMAService`, `taxeCCIService` |
| `caPrestationAutres` | `montantPrestationAutres` | `montantPrestationAutresVL` | — |
| `recettesCipav` | `montantCipav` | `montantCipavVL` | — |

Other amounts: `montantTotal`, `montantFormationProfessionnelle`, `montantRegulAcre`, `montantDeduction`. The mapping of
chamber fees to turnover lines is *inferred from the field names*.

### 3.4 Error catalogue

Codes appear in the PDF as examples (free strings in the contract). HTTP status as documented.

| Code | HTTP | Operations | Meaning |
|---|---|---|---|
| `PARAM_INVALIDE` | 400 | all AE operations | One of the request parameters is invalid (missing SIRET/NIR, too long, not numeric, bad date, bad IBAN, missing/incomplete/invalid period, non-numeric turnover) |
| `ERR_PARAM_MULTIPLES` | 400 | 040, 050 | `nir` and `siret` both set; "Un seul paramètre dois être valorisé" [sic] |
| `CPTE_INCONNU` | 400 | 020, 031, 040, 050, 060 (Swagger: all) | No active AE account for these parameters |
| `ABN_INCONNU` | 400 | 020 | Account exists but no active enabled user |
| `SIRET_DEJA_MANDATE` | 400 | 030 | Another platform already holds the mandate for this SIRET |
| `MDT_INCONNU` | 400 | 040, 050, 060 | No third-party declaration mandate for the account |
| `MDT_HORS_PERIODE` | 400 | 040, 050 | Mandate signed after the due date of the requested period ("Déclaration/Estimation hors période validité mandat") |
| `APPEL_INCONNU` | 400 | 050 | Declaration period not open |
| `PERIODICITE_INVALIDE` | 400 | 050 | Period does not match the periodicity known for the account |
| `ERREUR_MANDAT_ALREADYEXIST` | 409 | 070 | SEPA mandate already active for this SIRET and IBAN |
| `ERREUR_IBAN_INVALIDE` | 400 | 070 | Invalid IBAN |
| `ERREUR_RIB_INVALIDE` | 400 | 070 | IBAN too short or too long |
| `BANQUE_INCONNUE` | 400 | 070 | Bank unknown in the referentials |
| `ERR_TECH` | 400 | 070, 071, 075 | Generic technical/functional error ("Une erreur technique/fonctionnelle est survenue") |
| `ERR_TECHNIQUE` | 500 | all | Technical error |
| — | 401, 403 | all | Token missing/invalid, authentication refused (no body documented for AE operations) |
| — | 404 | 070, 071, 075 | Resource not found (code *not published*) |
| — | 429 | all | Quota exceeded (§1.1) |

### 3.5 First-declaration schedule after the start of activity (PDF §3.5.1)

Opening of the period is after URSSAF receives the AE's affiliation notification to social security. Reproduced from the PDF images.

First **monthly** declaration:

| Start of activity | Period opens | Due date (*date d'exigibilité*) |
|---|---|---|
| 1 to 31 January | early February | 31 May |
| 1 to 29 February | early March | 30 June |
| 1 to 31 March | early April | 31 July |
| 1 to 30 April | early May | 31 August |
| 1 to 31 May | early June | 30 September |
| 1 to 30 June | early July | 31 October |
| 1 to 31 July | early August | 30 November |
| 1 to 31 August | early September | 31 December |
| 1 to 30 September | early October | 31 January of the following year |
| 1 to 31 October | early November | 28 February of the following year |
| 1 to 30 November | early December | 31 March of the following year |
| 1 to 31 December | early January of the following year | 30 April of the following year |

First **quarterly** declaration:

| Start of activity | Period opens | Due date |
|---|---|---|
| 1 January to 31 March | early April | 31 July |
| 1 April to 30 June | early July | 31 October |
| 1 July to 30 September | early October | 31 January of the following year |
| 1 October to 31 December | early January of the following year | 30 April of the following year |

(The PDF also shows a timeline: M1 start, M2-M4 declaration of M1/M2/M3 and payment orders possible, M5 declarations and
payments of M1 to M4 possible, M6 second declaration; bank debit at the end of M5 for payments ordered in M2-M4, etc.)

---

## 4. Package decisions derived from the specification

1. **Account identification.** The package always sends `siret` (14 digits), never `nir` (deprecated); `nir` support is not
   offered, so `ERR_PARAM_MULTIPLES` cannot occur on the AE operations. For 070 the SIRET goes in the body. Decided in
   [ADR 0008](adr/0008-siret-and-account-reference.md): no SIRET checksum (none is published), the birth date is checked by URSSAF only.
2. **Two contracts, one model.** Decided in [ADR 0006](adr/0006-two-contracts-one-model.md): the models are the union of prd 1.5.1 and sb 1.5.0: the five `*VL` amounts are
   optional (absent in the sandbox), `OutputEstimation.montants`/`periode` are treated as required (PDF + sandbox) with a
   clear error when absent, `Compte.date_etat` and `exoAccrePresent` are optional (PDF only); an absent optional field is `null`, never `0`. Unknown response fields
   are ignored. `SpecVersion` stays `1.5.0`.
3. **Wrapper tolerance.** Decided in [ADR 0006](adr/0006-two-contracts-one-model.md): the 200 mappers of 040/050/060 accept both the direct object (PDF, sandbox) and the
   wrapper (`OutputEstimations` / `OutputDeclarations` / `OutputTelepaiements`, production Swagger), detected by the presence
   of the wrapper key (a wrapper that is `null`, not an object or empty is an unreadable answer); the contract test against the sandbox, then production smoke tests, settle which one is real (§5).
4. **Money.** Amounts are whole euros (`number`/`int32`, no cents, §1.2). Decided in [ADR 0007](adr/0007-whole-euros.md): a `Euros` value object over `int`
   (never `float`); the mapper rejects non-integral JSON numbers and accepts integral floats (`1678.0`). Negative values
   (`montantRegulAcre`, `montantDeduction` may deduct) are allowed on output only; inputs (turnover lines, 060 `montant`)
   must not be negative. The package does not recompute contributions or rounding (done server-side). There is **no
   `montantDue()` helper**: `montantTotal + montantRegulAcre + montantDeduction` (PDF) is not offered while the
   Swagger/PDF contradiction on `montantTotal` is open (§5); the host chooses the amount it passes to 060.
5. **Periods.** Decided in [ADR 0004](adr/0004-sum-types.md) and [ADR 0006](adr/0006-two-contracts-one-model.md): `DeclarationPeriod` sum type `MonthlyPeriod(year, month 1-12)` | `QuarterlyPeriod(year, quarter
   1-4)` making the "not both" rule unrepresentable; `Periodicity` enum `M`/`T`. Serialised as **integers** (per contract);
   the mapper reads both ints and numeric strings (PDF examples). Whether the API accepts integers as well as strings is to be
   confirmed by the contract tests (`period-encoding`). Declaring a period whose kind differs from the account periodicity is
   rejected locally only after `GET /comptes` has been read (guard with an opt-out, [ADR 0011](adr/0011-guards-and-opt-outs.md));
   otherwise the server answers `PERIODICITE_INVALIDE`.
6. **Idempotency.**
   - *Declaration (050)* converges on an open period (last wins; `certificat` stable, `idDeclaration` changes), but every
     call e-mails the AE: **decided in [ADR 0003](adr/0003-retry-and-unknown-outcome.md)**, 050 is never resent by
     default (`outcome_unknown`), resend only when the host lists it in `retry_safe_writes`. The last-wins behaviour is to be
     confirmed by the contract tests before a host lists it.
   - *Payment (060)* has **no published idempotency mechanism** (no key header, no duplicate-detection error): decided in
     [ADR 0003](adr/0003-retry-and-unknown-outcome.md) and [ADR 0009](adr/0009-local-lifecycles.md): `POST /payer` is never resent
     after a timeout / 5xx / connection error, and this is not configurable; the package raises `OutcomeUnknown`, the payment
     record goes to `outcome_unknown` and an operator checks the AE space and records the result. The record is claimed
     before the call, keyed by a host-supplied `PaymentReference`, and a second payment of a period is refused unless the
     host opts in ([ADR 0011](adr/0011-guards-and-opt-outs.md)). 429 and a local rate-limit refusal provably did not reach the
     service and stay retryable, honouring any `Retry-After`. A 401 on 060 invalidates the token but is not
     replayed: the payment is settled `invalid_credentials` and requeued by the host (ADR 0003, amendment after lot 8).
   - Mandate notification (030) is not idempotent (`SIRET_DEJA_MANDATE`); cancellation (031) and SEPA revocation (071) outcome on
     repetition not published: 030, 031, 070 and 071 follow the 050 rule (never resent unless listed in `retry_safe_writes`,
     ADR 0003). 020, 040, 075 and the token request are safe to retry.
7. **Dates.** Decided in [ADR 0001](adr/0001-date-only-encoding.md): `dateNaissance` / `dateEffet` serialised `yyyy-MM-dd`; `dateExigibilite` parsed as ISO date; `date_etat`
   parsed as `DD/MM/YYYY`; all as calendar dates (`Europe/Paris` for "today + 1" checks), not instants.
8. **Identifiers are opaque strings** ([ADR 0011](adr/0011-guards-and-opt-outs.md) item 5). `certificat` (`^\d{8}$` kept as string, leading zeros), `idDeclaration`, `idTelepaiement`
   (max 23), `rum` (max 35), `ics`. Local validation limited to the published lengths/patterns.
9. **Error mapping.** Decided in [ADR 0005](adr/0005-error-classification.md) and [ADR 0002](adr/0002-http-client-adaptations.md): one exception hierarchy keyed on the business `code` (§3.4) with the raw code preserved for unknown values;
   the parser accepts an object or a list of `{code, message, description}`; 401/403/429/500 without body map to dedicated
   exceptions by HTTP status. 409 maps to "SEPA mandate already exists".
10. **SEPA mandate requirement for payment.** Decided in [ADR 0011](adr/0011-guards-and-opt-outs.md): `iban` and `rum` are both
    optional and no combination rule or length is enforced locally (none is published); the package never requires a
    registered SEPA mandate before 060. The rule actually applied by URSSAF is to be settled by the contract tests (`pay-060-*`).
11. **Personal and bank data.** Decided in [ADR 0010](adr/0010-personal-data.md): `nir`, `dateNaissance`, `iban`, `bic`, `titulaireCompte` and addresses are personal data: never
    logged; `nir` is never handled and the IBAN, BIC, holder and address are never stored (the IBAN is masked in dumps); the
    SIRET is not secret but is kept out of log context by default (`log_siret`).
12. **Rate limiting.** Decided in [ADR 0013](adr/0013-quotas-and-rate-limit.md): HTTP 429 surfaces a typed exception exposing
    `Retry-After` when present; an in-process sliding window (300 requests/minute per tenant) is provided, on by default in
    the standalone factory and opt-in in the Symfony bundle (not enforceable across processes).

---

## 5. Open points

The package is implemented; each point below is either **open** (the specification does not say, and the package
does not guess), **handled by a decision** (the package behaves safely whatever the answer, ADR linked, the answer
itself is still unknown) or **settled by a decision** (no further information needed). "Contract tests" are the live
sandbox tests ([testing.md](testing.md)): they have not run yet, and each point they are meant to settle has a note
there. Once a point is confirmed, update the SDK if needed and remove it from this list.

### Open, to be settled by the contract tests

- **Production (1.5.1) vs sandbox (1.5.0) gap** (§2.10): extra `*VL` amounts, wrapper response types, `required` on
  `OutputEstimation`, tags. PDF is at 1.5.0 and has no 1.5.1 entry. Does production return wrapped objects
  (`{"OutputDeclaration": {...}}`) or direct objects (the sandbox note `response-shape` shows the sandbox side; production
  needs a smoke test)? Are the `*VL` amounts returned in production only for AE with the *versement libératoire* option?
  Is the sandbox going to be upgraded? Handled by [ADR 0006](adr/0006-two-contracts-one-model.md) (both shapes decoded);
  `SpecVersion` follows the PDF (1.5.0).
- **Sandbox token endpoint host** (the sandbox Swagger declares the production `tokenUrl`) and sandbox base URL, to confirm at
  onboarding: the token URL is derived from the base URI of the environment ([ADR 0002](adr/0002-http-client-adaptations.md)).
- **Period encoding on input**: integers are sent (per contract, the PDF examples use strings); that URSSAF accepts
  integers is to be confirmed (note `period-encoding`, [ADR 0006](adr/0006-two-contracts-one-model.md)).
- **`Compte.date_etat` and `exoAccrePresent`** (PDF only): whether 020 returns them (note `compte-pdf-only-fields`); both
  optional in the model ([ADR 0006](adr/0006-two-contracts-one-model.md)).
- **`iban` / `rum` on 060** (both optional): the rule URSSAF applies, whether payment requires a SEPA mandate, its relation to
  the declared amount / `idDeclaration`, behaviour on duplicate payment, min/max `montant` (notes `pay-060-*`). The package
  adds no check ([ADR 0011](adr/0011-guards-and-opt-outs.md)) and never resends 060 ([ADR 0003](adr/0003-retry-and-unknown-outcome.md)).
- **Business error codes not published**: birth-date mismatch (030/031), "no mandate to cancel" (031), unknown RUM or invalid
  `dateEffet` (071), SEPA 404 (070/071/075); default `dateEffet` when omitted. An unknown code is kept raw and classified
  `unknown` ([ADR 0005](adr/0005-error-classification.md)); the contract tests fail on a code missing from `ApiErrorCode`.
- **Behaviour of 075** when the account has no mandate (empty list or 404); the package does not turn a 404 into an empty list
  ([ADR 0002](adr/0002-http-client-adaptations.md), note `sepa-075`); success status of 071 (204 or 200, both accepted).
- **Retry safety of 030, 031, 050, 070, 071** (repetition not published, 050 last-wins unverified): never resent unless the
  host lists the operation ([ADR 0003](adr/0003-retry-and-unknown-outcome.md)).
- Whether `ERR_PARAM_MULTIPLES`, `APPEL_INCONNU`, `PERIODICITE_INVALIDE`, `MDT_HORS_PERIODE` can occur on 060.
- Official sandbox test data (SIRET, mandates, periods) not published: needed to run the contract tests.

### Not published, handled by a decision

- **`montantTotal` meaning** (§2.4): the Swagger and the PDF contradict each other. The package exposes every amount raw and
  offers no `montantDue()` helper ([ADR 0007](adr/0007-whole-euros.md)); the host chooses the amount to pay.
- **Quota details** (per-operation limits, `Retry-After`, headers) not published; 429 absent from the Swagger. Client-side
  limiter of 300 requests/minute per tenant and a typed 429 exception ([ADR 0013](adr/0013-quotas-and-rate-limit.md)); the per-API
  level cannot be enforced locally.
- **`bic` / `iban` / `codePostal` patterns, SIRET and `nir` checksums, `Creancier.nom` / `ics` format**: not published, so
  not checked locally (only the published lengths, [ADR 0011](adr/0011-guards-and-opt-outs.md), [ADR 0008](adr/0008-siret-and-account-reference.md)); URSSAF checks them.
- **Rules applied only by the server** (open period, mandate validity window, first-declaration schedule, birth-date match,
  single platform per SIRET, IBAN checksum and bank referentials, duplicate SEPA mandate): not checked locally
  ([ADR 0011](adr/0011-guards-and-opt-outs.md) item 6).
- **Time of the revocation date** (071, minimum today + 1): checked in the business time zone with an opt-out
  ([ADR 0001](adr/0001-date-only-encoding.md), [ADR 0011](adr/0011-guards-and-opt-outs.md)).

### Settled by a decision

- **Swagger vs PDF**: PDF `InputEstimation.date` (deprecated) is not sent (040 sends `ca` only); PDF error examples for 040/050 still mention a `date`
  parameter (ignored); PDF `addresse1/2` vs Swagger `adresse1/2`: the Swagger spelling is used on the wire; PDF prints
  `DELETE /sepa/mandats` without path params: the request is `DELETE /sepa/mandats/{siret}/{rum}` with `dateEffet` in the
  query ([ADR 0002](adr/0002-http-client-adaptations.md)); SEPA errors are lists in the Swagger and single objects in the PDF:
  both are parsed ([ADR 0005](adr/0005-error-classification.md)); PDF examples send `annee`/`trimestre`/`mois` as strings while the
  Swagger says integer: both are read, integers are written ([ADR 0006](adr/0006-two-contracts-one-model.md)).
- **`nir`**: never sent or read ([ADR 0008](adr/0008-siret-and-account-reference.md)); the SIRET is always in the body of 070, so the
  "siret or nir" question is moot there.
- **Cardinality / ranges of `Periode`** (prose only): `mois` 1-12, `trimestre` 1-4, `annee` 1-9999, never both
  ([ADR 0004](adr/0004-sum-types.md)).
- **Nature of 060 duplicates**: the package cannot tell an intended second payment from a duplicate; it refuses a second
  payment of a period unless the host opts in ([ADR 0011](adr/0011-guards-and-opt-outs.md), [ADR 0009](adr/0009-local-lifecycles.md)).
- **Shared library** with CESU and PAJE (same `/tiersdecl/v1` path and mandate model): out of scope, see [ROADMAP.md](../ROADMAP.md).
