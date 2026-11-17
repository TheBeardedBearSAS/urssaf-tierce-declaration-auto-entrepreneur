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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class DeclarationPeriodsTest extends TestCase
{
    public function testFromPartsBuildsAMonthlyPeriod(): void
    {
        $period = DeclarationPeriods::fromParts(Periodicity::Monthly, 2019, 12);

        static::assertInstanceOf(MonthlyPeriod::class, $period);
        static::assertSame('2019-12', $period->toString());
    }

    public function testFromPartsBuildsAQuarterlyPeriod(): void
    {
        $period = DeclarationPeriods::fromParts(Periodicity::Quarterly, 2019, 3);

        static::assertInstanceOf(QuarterlyPeriod::class, $period);
        static::assertSame('2019-Q3', $period->toString());
    }

    public function testFromPartsRejectsInvalidParts(): void
    {
        $month = DomainAssertions::assertViolationCode('period.month_out_of_range', static fn(): DeclarationPeriod => DeclarationPeriods::fromParts(Periodicity::Monthly, 2019, 13));
        $quarter = DomainAssertions::assertViolationCode('period.quarter_out_of_range', static fn(): DeclarationPeriod => DeclarationPeriods::fromParts(Periodicity::Quarterly, 2019, 5));

        static::assertSame(['min' => 1, 'max' => 12], $month->params());
        static::assertSame(['min' => 1, 'max' => 4], $quarter->params());
    }

    public function testNumberOf(): void
    {
        static::assertSame(12, DeclarationPeriods::numberOf(MonthlyPeriod::of(2019, 12)));
        static::assertSame(1, DeclarationPeriods::numberOf(MonthlyPeriod::of(2019, 1)));
        static::assertSame(3, DeclarationPeriods::numberOf(QuarterlyPeriod::of(2019, 3)));
        static::assertSame(4, DeclarationPeriods::numberOf(QuarterlyPeriod::of(2019, 4)));
    }

    public function testNumberOfAnUnsupportedPeriodKind(): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'declaration_period.unsupported',
            static fn(): int => DeclarationPeriods::numberOf(new UnsupportedPeriod()),
        );

        static::assertSame('The declaration period kind is not supported.', $exception->getMessage());
    }

    public function testPartsRoundTrip(): void
    {
        foreach ([MonthlyPeriod::of(2020, 2), QuarterlyPeriod::of(2020, 2)] as $period) {
            $rebuilt = DeclarationPeriods::fromParts($period->periodicity(), $period->year(), DeclarationPeriods::numberOf($period));

            static::assertTrue($period->equals($rebuilt));
        }
    }
}
