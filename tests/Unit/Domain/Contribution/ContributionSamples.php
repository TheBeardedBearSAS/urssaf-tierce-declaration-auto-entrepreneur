<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Contribution;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

/**
 * Fictitious amounts, whole euros (api-reference §2).
 */
final class ContributionSamples
{
    public static function euros(int $amount): Euros
    {
        return Euros::of($amount);
    }

    public static function amounts(int $total): ContributionAmounts
    {
        return ContributionAmounts::of(
            Euros::of($total),
            Euros::of(100),
            Euros::of(200),
            Euros::of(300),
            Euros::of(400),
            Euros::of(500),
            Euros::of(600),
            LiberatoryContributions::of(Euros::of(1), Euros::of(2), Euros::of(3), Euros::of(4), Euros::of(5)),
            ChamberFees::of(Euros::of(6), Euros::of(7), Euros::of(8), Euros::of(9)),
            Euros::of(-10),
            Euros::of(-20),
        );
    }
}
