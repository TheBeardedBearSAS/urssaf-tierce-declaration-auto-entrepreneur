<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Period;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;

/**
 * Test double of a period kind this version does not know (default arm of the sum type helpers).
 */
final readonly class UnsupportedPeriod implements DeclarationPeriod
{
    public function year(): int
    {
        return 2019;
    }

    public function periodicity(): Periodicity
    {
        return Periodicity::Monthly;
    }

    public function equals(DeclarationPeriod $other): bool
    {
        return $other instanceof self;
    }

    public function toString(): string
    {
        return '2019-unsupported';
    }
}
