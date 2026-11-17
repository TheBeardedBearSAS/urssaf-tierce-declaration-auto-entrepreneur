# ADR 0006 — Two contracts, one model

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference and the sibling packages each target one contract. The AE API publishes two Swagger contracts that differ
(api-reference §2.10): production 1.5.1 and sandbox 1.5.0. The PDF (1.5.0) matches the sandbox and has no 1.5.1 entry
(header, §5). Differences that reach the model:

- `MontantsAe` has five *versement libératoire* amounts in production only: `montantVenteVL`, `montantLocationVL`,
  `montantPrestationServiceVL`, `montantPrestationAutresVL`, `montantCipavVL` (§2.4, §2.10 item 3);
- the 200 of 040, 050 and 060 is wrapped in production (`OutputEstimations`, `OutputDeclarations`,
  `OutputTelepaiements`, each with one optional property) and direct in the sandbox and the PDF (§2.10 items 4–6).
  The 040 wrapper property is named `OutputDeclaration` (sic), like the 050 one; the 060 one is `OutputTelepaiement`;
- `OutputEstimation.montants` and `periode` are required in the sandbox and the PDF, optional in production (item 7);
- `Compte.date_etat` and `exoAccrePresent` appear in the PDF only, in neither contract (§2.1);
- the PDF examples send `annee` / `mois` / `trimestre` as strings, the contracts as integers (§2.4, §2.5);
- the 070 example returns `Creancier.ville` with a trailing space (§2.7).

Which shape production really returns is an open point (§5), settled only by the contract test then production smoke
tests (§4 decision 3).

## Decision

- **One model, the union.** `Domain\Contribution\ContributionAmounts` (`MontantsAe`) has a required `total` and
  optional (`?Euros`) fields for every other amount, the five `*VL` ones included; an absent field is `null`, never
  `0`. `Domain\Account\AccountStatus` (020) has `mandatePresent`, `periodicity` (required) and `?CalendarDate
  affiliationDate` (`date_etat`, [ADR 0001](0001-date-only-encoding.md)), `?bool acreExemption` (`exoAccrePresent`),
  both `null` when absent.
- **Estimate required fields.** `Domain\Contribution\Estimate` requires `amounts` and `period` (PDF + sandbox). When
  either is absent, `EstimateResponseMapper` throws `UnexpectedUrssafResponse` naming the missing field
  (`montants` / `periode`); 040 is safe ([ADR 0003](0003-retry-and-unknown-outcome.md)), so the host sees a clear
  error rather than an empty estimate.
- **Wrapper tolerance.** The 200 mappers of 040, 050 and 060 detect the shape by the presence of the wrapper key at
  the top level of the object: `OutputDeclaration` for 040 and 050, `OutputTelepaiement` for 060. Present: its value
  must be an object and is mapped; absent: the top-level object itself is mapped. A wrapper key whose value is
  `null`, not an object, or an empty wrapper object `{}` is `UnexpectedUrssafResponse` (on 050 / 060 an unknown
  outcome, ADR 0003). The detected shape is not exposed to the domain; the gateway reports it to the logger at debug
  level (`wrapped` / `direct`, no payload) so that the contract test can assert it.
- **Periods.** `Wire\PeriodMapper` reads `annee`, `mois`, `trimestre` as an int or a numeric string of digits only
  (`"2019"`, `"3"`; no sign, no decimal, no space), through `Payload`; it always writes integers, per contract
  ([ADR 0004](0004-sum-types.md)). Whether URSSAF also accepts integers on input is confirmed by the contract test
  (§4 decision 5); a change to strings would be a mapper change only.
- **Text fields.** `Creancier.ville` is trimmed when read (§2.7); an empty result is `null`. Every other response
  string is kept as received (identifiers are opaque, §4 decision 8).
- **Unknown fields** of any response are ignored, so a production field absent from both contracts never breaks a
  read.
- `SpecVersion::CURRENT` stays `1.5.0` (the PDF version, §4 decision 2). Wire fixtures exist for both shapes:
  `*_response.json` (PDF, direct) and `*_response_wrapped.json` (built from the same values in the production shape).

## Consequences

- Production can be used before the open point is closed, without configuration.
- When the contract test settles the real shapes, the tolerance stays unless the lead decides otherwise by amendment:
  removing it is a mapper change with no impact on the domain.
- Fields that only one contract or only the PDF defines are nullable in the domain; hosts must not read `null` as
  "zero" or "false".
