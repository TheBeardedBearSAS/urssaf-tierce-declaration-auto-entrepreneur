<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;

/**
 * Estimate of the contributions of a period (method 040): the amounts and the period they were computed for.
 */
final readonly class Estimate
{
    private function __construct(
        private ContributionAmounts $amounts,
        private DeclarationPeriod $period,
    ) {}

    public static function of(ContributionAmounts $amounts, DeclarationPeriod $period): self
    {
        return new self($amounts, $period);
    }

    public function amounts(): ContributionAmounts
    {
        return $this->amounts;
    }

    public function period(): DeclarationPeriod
    {
        return $this->period;
    }
}
