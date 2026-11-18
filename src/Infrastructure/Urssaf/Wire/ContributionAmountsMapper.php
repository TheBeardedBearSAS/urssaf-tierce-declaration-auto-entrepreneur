<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the `MontantsAe` object of 040 and 050 (union of the prd and sb variants, ADR 0006). Only `montantTotal` is
 * required; every other amount is optional and an absent one stays `null`, never `0` (ADR 0007).
 */
final class ContributionAmountsMapper
{
    /**
     * @throws UnexpectedUrssafResponse when `montantTotal` is missing or an amount is not a whole euro amount
     */
    public static function fromWire(Payload $montants): ContributionAmounts
    {
        return ContributionAmounts::of(
            EurosEncoding::decode($montants, 'montantTotal'),
            EurosEncoding::decodeOptional($montants, 'montantVente'),
            EurosEncoding::decodeOptional($montants, 'montantLocation'),
            EurosEncoding::decodeOptional($montants, 'montantPrestationService'),
            EurosEncoding::decodeOptional($montants, 'montantPrestationAutres'),
            EurosEncoding::decodeOptional($montants, 'montantCipav'),
            EurosEncoding::decodeOptional($montants, 'montantFormationProfessionnelle'),
            LiberatoryContributions::of(
                EurosEncoding::decodeOptional($montants, 'montantVenteVL'),
                EurosEncoding::decodeOptional($montants, 'montantLocationVL'),
                EurosEncoding::decodeOptional($montants, 'montantPrestationServiceVL'),
                EurosEncoding::decodeOptional($montants, 'montantPrestationAutresVL'),
                EurosEncoding::decodeOptional($montants, 'montantCipavVL'),
            ),
            ChamberFees::of(
                EurosEncoding::decodeOptional($montants, 'taxeCMAVente'),
                EurosEncoding::decodeOptional($montants, 'taxeCMAService'),
                EurosEncoding::decodeOptional($montants, 'taxeCCIVente'),
                EurosEncoding::decodeOptional($montants, 'taxeCCIService'),
            ),
            EurosEncoding::decodeOptional($montants, 'montantRegulAcre'),
            EurosEncoding::decodeOptional($montants, 'montantDeduction'),
        );
    }
}
