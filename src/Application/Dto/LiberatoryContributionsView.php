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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;

/**
 * Read model of the contributions paid through the liberatory tax option, in whole euros.
 */
final readonly class LiberatoryContributionsView
{
    private function __construct(
        public ?int $sales,
        public ?int $furnishedRental,
        public ?int $commercialServices,
        public ?int $otherServices,
        public ?int $cipav,
    ) {}

    public static function fromContributions(LiberatoryContributions $contributions): self
    {
        return new self(
            $contributions->sales()?->toInt(),
            $contributions->furnishedRental()?->toInt(),
            $contributions->commercialServices()?->toInt(),
            $contributions->otherServices()?->toInt(),
            $contributions->cipav()?->toInt(),
        );
    }
}
