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

final class MonthlyPeriodTest extends TestCase
{
    public function testAccessors(): void
    {
        $period = MonthlyPeriod::of(2019, 12);

        static::assertSame(2019, $period->year());
        static::assertSame(12, $period->month());
        static::assertSame(Periodicity::Monthly, $period->periodicity());
    }

    public function testBounds(): void
    {
        static::assertSame(1, MonthlyPeriod::of(1, 1)->month());
        static::assertSame(12, MonthlyPeriod::of(9999, 12)->month());
        static::assertSame(9999, MonthlyPeriod::of(9999, 12)->year());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideText(): iterable
    {
        yield 'december 2019' => ['2019-12', '2019-12'];
        yield 'padded year' => ['0099-01', '0099-01'];
        yield 'padded month' => ['2020-03', '2020-03'];
    }

    #[DataProvider('provideText')]
    public function testToString(string $expected, string $text): void
    {
        [$year, $month] = array_map(intval(...), explode('-', $text));

        static::assertSame($expected, MonthlyPeriod::of($year, $month)->toString());
    }

    /**
     * @return iterable<string, array{int, int, string, array<string, int>}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'year zero' => [0, 1, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
        yield 'year negative' => [-1, 1, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
        yield 'year 10000' => [10_000, 1, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
        yield 'month zero' => [2019, 0, 'period.month_out_of_range', ['min' => 1, 'max' => 12]];
        yield 'month 13' => [2019, 13, 'period.month_out_of_range', ['min' => 1, 'max' => 12]];
        yield 'both invalid: year first' => [0, 13, 'period.year_out_of_range', ['min' => 1, 'max' => 9999]];
    }

    /**
     * @param array<string, int> $params
     */
    #[DataProvider('provideInvalid')]
    public function testOutOfRange(int $year, int $month, string $code, array $params): void
    {
        $exception = DomainAssertions::assertViolationCode($code, static fn(): MonthlyPeriod => MonthlyPeriod::of($year, $month));

        static::assertSame($params, $exception->params());
    }

    public function testMessages(): void
    {
        $year = DomainAssertions::assertViolationCode('period.year_out_of_range', static fn(): MonthlyPeriod => MonthlyPeriod::of(0, 1));
        $month = DomainAssertions::assertViolationCode('period.month_out_of_range', static fn(): MonthlyPeriod => MonthlyPeriod::of(2019, 13));

        static::assertSame('Invalid period: the year must be between 1 and 9999.', $year->getMessage());
        static::assertSame('Invalid period: the month must be between 1 and 12.', $month->getMessage());
    }

    public function testEquality(): void
    {
        $period = MonthlyPeriod::of(2019, 12);

        static::assertTrue($period->equals(MonthlyPeriod::of(2019, 12)));
        static::assertFalse($period->equals(MonthlyPeriod::of(2019, 11)));
        static::assertFalse($period->equals(MonthlyPeriod::of(2018, 12)));
        static::assertFalse($period->equals(QuarterlyPeriod::of(2019, 4)));
        static::assertFalse(MonthlyPeriod::of(2019, 3)->equals(QuarterlyPeriod::of(2019, 3)));
    }
}
