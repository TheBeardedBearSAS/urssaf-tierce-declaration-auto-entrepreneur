<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;

/**
 * Read model of an estimate (method 040): the amounts and the period URSSAF estimated them for (`periodicity` is `M` or
 * `T`, the period number is the month or the quarter).
 */
final readonly class EstimateView
{
    private function __construct(
        public ContributionAmountsView $amounts,
        public string $periodicity,
        public int $periodYear,
        public int $periodNumber,
    ) {}

    public static function fromEstimate(Estimate $estimate): self
    {
        $period = $estimate->period();

        return new self(
            ContributionAmountsView::fromAmounts($estimate->amounts()),
            $period->periodicity()->value,
            $period->year(),
            DeclarationPeriods::numberOf($period),
        );
    }
}
