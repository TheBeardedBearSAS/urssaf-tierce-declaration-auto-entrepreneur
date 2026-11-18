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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;

final class EstimateTest extends TestCase
{
    public function testAccessors(): void
    {
        $amounts = ContributionSamples::amounts(1678);
        $period = MonthlyPeriod::of(2019, 12);
        $estimate = Estimate::of($amounts, $period);

        static::assertSame($amounts, $estimate->amounts());
        static::assertSame($period, $estimate->period());
    }
}
