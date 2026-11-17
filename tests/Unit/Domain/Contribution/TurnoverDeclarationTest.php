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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;

final class TurnoverDeclarationTest extends TestCase
{
    public function testAccessors(): void
    {
        $period = QuarterlyPeriod::of(2019, 3);
        $turnover = Turnover::of(ContributionSamples::euros(1500), null, null, null, null);
        $declaration = TurnoverDeclaration::of($period, $turnover);

        static::assertSame($period, $declaration->period());
        static::assertSame($turnover, $declaration->turnover());
    }
}
