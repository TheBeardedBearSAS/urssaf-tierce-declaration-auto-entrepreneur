# ADR 0007 — Whole euros instead of cents

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference models money as `Domain\Shared\Money`, a non-negative amount in **cents** read from and written as
decimal numbers (`MoneyEncoding`, `JsonDecimal`). Every AE amount is a whole number of euros (api-reference §1.2):
`CaAe` (turnover input of 040 / 050), `MontantsAe` (output of 040 / 050), `InputTelepaiement.montant` (input of 060)
are all `number` with `format: int32` (§2.4, §2.6, §3.3). Rounding to the euro is done by the AE / URSSAF (PDF §3.5.1,
DGFiP rule, §1.2). `montantRegulAcre` and `montantDeduction` may deduct, so outputs may be negative (§4 decision 4).
The meaning of `montantTotal` is contradictory: the Swagger says it already includes the ACRE regularisation and the
deduction, the PDF says the amount due is `montantTotal + montantRegulAcre + montantDeduction` (§2.4, §5).

## Decision

- **Deviation from the reference:** `Domain\Money\Euros` replaces `Money`. It is a `final readonly` value object over a
  **signed** `int` within the int32 range (`-2147483648 … 2147483647`), named constructor `Euros::of(int)`, accessor
  `toInt()`, `equals()`, `isNegative()`. No cents, no decimal string, no `float` anywhere in the domain
  (ADR 0000 item 3). No arithmetic is offered (nothing in the package needs it).
- **Inputs are non-negative.** `Domain\Contribution\Turnover` (the five optional `CaAe` lines: `sales` `caVente`,
  `furnishedRental` `caLocation`, `commercialServices` `caPrestationService`, `otherServices` `caPrestationAutres`,
  `cipavReceipts` `recettesCipav`) refuses a negative line (`turnover.negative`) and a value above int32 max
  (`euros.out_of_range`); the input factory collects every violation (`ValidationFailed`). An absent line is not sent;
  `0` is sent as `0`. The 060 `montant` follows the same bounds (`payment.amount_negative`): no minimum, maximum or
  relation to the declared amount is published (§2.6, §5), so none is added, `0` included.
- **Wire.** `JsonEncoder` writes `Euros` as a JSON integer (it already refuses floats). `Wire\EurosDecoding` reads an
  amount through `Payload`: a JSON integer, or an integral float (`1678.0`, as some JSON producers emit) converted
  exactly; a non-integral number (`1678.5`), a non-finite value, a value outside int32, a string or any other type is
  `UnexpectedUrssafResponse` (on 050 / 060 an unknown outcome, [ADR 0003](0003-retry-and-unknown-outcome.md)).
  Amount strings are not tolerated: unlike periods ([ADR 0006](0006-two-contracts-one-model.md)), every example sends
  amounts as plain integers.
- **No recomputation.** The package never computes contributions, rates, rounding or totals: `ContributionAmounts`
  exposes every `MontantsAe` field raw (`total()` = `montantTotal`, `acreRegularisation()`, `deduction()`, …). There
  is **no `amountDue()` / `montantDue()` helper** while the §5 contradiction on `montantTotal` is open; the host
  chooses the reading and the amount it passes to `PayContributions`. Adding the helper later is an amendment once
  the contract test or URSSAF settles the meaning.
- Integration events and views carry amounts as `int` euros (field names end in `Euros`, for instance
  `totalEuros`), never as decimal strings ([ADR 0014](0014-integration-events.md)); the Doctrine bridge stores them
  as `INTEGER` ([ADR 0016](0016-doctrine-bridge.md)).

## Consequences

- No `Money` / `Decimal` / `JsonDecimal` classes in this package; a reader coming from the reference must not expect
  cents.
- A host that keeps cents rounds them itself with the DGFiP rule (§1.2) before building a `Turnover`.
- If URSSAF ever returns decimals, the decoder refuses them loudly instead of truncating them.

## Amendment (2026-11-18, lead-accepted, lot 2)

- The decoder/encoder is `Wire\EurosEncoding`.
