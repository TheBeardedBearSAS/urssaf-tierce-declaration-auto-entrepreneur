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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;

/**
 * Read model of the contribution amounts of an estimate (040) or of a declaration (050), in whole euros.

 */
final readonly class ContributionAmountsView
{
    private function __construct(
        public int $total,
        public ?int $sales,
        public ?int $furnishedRental,
        public ?int $commercialServices,
        public ?int $otherServices,
        public ?int $cipav,
        public ?int $vocationalTraining,
        public ?int $acreRegularisation,
        public ?int $deduction,
        public LiberatoryContributionsView $liberatory,
        public ChamberFeesView $chamberFees,
    ) {}

    public static function fromAmounts(ContributionAmounts $amounts): self
    {
        return new self(
            $amounts->total()->toInt(),
            $amounts->sales()?->toInt(),
            $amounts->furnishedRental()?->toInt(),
            $amounts->commercialServices()?->toInt(),
            $amounts->otherServices()?->toInt(),
            $amounts->cipav()?->toInt(),
            $amounts->vocationalTraining()?->toInt(),
            $amounts->acreRegularisation()?->toInt(),
            $amounts->deduction()?->toInt(),
            LiberatoryContributionsView::fromContributions($amounts->liberatory()),
            ChamberFeesView::fromFees($amounts->chamberFees()),
        );
    }
}
