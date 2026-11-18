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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;

/**
 * Read model of the chamber fees (CMA and CCI), in whole euros.
 */
final readonly class ChamberFeesView
{
    private function __construct(
        public ?int $cmaSales,
        public ?int $cmaServices,
        public ?int $cciSales,
        public ?int $cciServices,
    ) {}

    public static function fromFees(ChamberFees $fees): self
    {
        return new self(
            $fees->cmaSales()?->toInt(),
            $fees->cmaServices()?->toInt(),
            $fees->cciSales()?->toInt(),
            $fees->cciServices()?->toInt(),
        );
    }
}
