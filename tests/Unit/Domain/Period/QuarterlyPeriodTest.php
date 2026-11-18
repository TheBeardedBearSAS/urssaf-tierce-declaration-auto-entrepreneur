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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class QuarterlyPeriodTest extends TestCase
{
    public function testAccessors(): void
    {
        $period = QuarterlyPeriod::of(2019, 3);

        static::assertSame(2019, $period->year());
        static::assertSame(3, $period->quarter());
        static::assertSame(Periodicity::Quarterly, $period->periodicity());
        static::assertSame('2019-Q3', $period->toString());
        static::assertSame('0099-Q1', QuarterlyPeriod::of(99, 1)->toString());
    }

    public function testBounds(): void
    {
        static::assertSame(1, QuarterlyPeriod::of(1, 1)->quarter());
        static::assertSame(4, QuarterlyPeriod::of(9999, 4)->quarter());
        static::assertSame(9999, QuarterlyPeriod::of(9999, 4)->year());
    }

    /**
     * @return iterable<string, array{int, int, string, array<string, int>}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'year zero' => [0, 1, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
        yield 'year 10000' => [10_000, 1, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
        yield 'quarter zero' => [2019, 0, 'period.quarter_out_of_range', ['min' => 1, 'max' => 4]];
        yield 'quarter 5' => [2019, 5, 'period.quarter_out_of_range', ['min' => 1, 'max' => 4]];
        yield 'both invalid: year first' => [10_000, 5, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
    }

    /**
     * @param array<string, int> $params
     */
    #[DataProvider('provideInvalid')]
    public function testOutOfRange(int $year, int $quarter, string $code, array $params): void
    {
        $exception = DomainAssertions::assertViolationCode($code, static fn(): QuarterlyPeriod => QuarterlyPeriod::of($year, $quarter));

        static::assertSame($params, $exception->params());
    }

    public function testMessages(): void
    {
        $year = DomainAssertions::assertViolationCode('period.year_out_of_range', static fn(): QuarterlyPeriod => QuarterlyPeriod::of(0, 1));
        $quarter = DomainAssertions::assertViolationCode('period.quarter_out_of_range', static fn(): QuarterlyPeriod => QuarterlyPeriod::of(2019, 5));

        static::assertSame('Invalid period: the year must be between 1 and 9999.', $year->getMessage());
        static::assertSame('Invalid period: the quarter must be between 1 and 4.', $quarter->getMessage());
    }

    public function testEquality(): void
    {
        $period = QuarterlyPeriod::of(2019, 3);

        static::assertTrue($period->equals(QuarterlyPeriod::of(2019, 3)));
        static::assertFalse($period->equals(QuarterlyPeriod::of(2019, 2)));
        static::assertFalse($period->equals(QuarterlyPeriod::of(2018, 3)));
        static::assertFalse($period->equals(MonthlyPeriod::of(2019, 3)));
    }
}
