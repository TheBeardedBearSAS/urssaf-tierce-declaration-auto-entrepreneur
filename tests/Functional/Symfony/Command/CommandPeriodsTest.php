<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;

final class CommandPeriodsTest extends TestCase
{
    public function testAMonthlyPeriod(): void
    {
        $period = CommandPeriods::fromOption('2026-10');

        static::assertSame(Periodicity::Monthly, $period->periodicity());
        static::assertSame(2026, $period->year());
        static::assertSame(10, DeclarationPeriods::numberOf($period));
    }

    public function testALeadingZeroMonthIsADecimalNumber(): void
    {
        static::assertSame(8, DeclarationPeriods::numberOf(CommandPeriods::fromOption('2026-08')));
    }

    public function testAQuarterlyPeriod(): void
    {
        $period = CommandPeriods::fromOption('2026-T3');

        static::assertSame(Periodicity::Quarterly, $period->periodicity());
        static::assertSame(2026, $period->year());
        static::assertSame(3, DeclarationPeriods::numberOf($period));
    }

    #[DataProvider('malformed')]
    public function testAnotherSyntaxIsRefused(string $value): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The --period option must be YYYY-MM (monthly) or YYYY-Tn (quarterly, n from 1 to 4).');

        CommandPeriods::fromOption($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'a month without its zero' => ['2026-1'];
        yield 'a year of two digits' => ['26-10'];
        yield 'a slash' => ['2026/10'];
        yield 'a leading space' => [' 2026-10'];
        yield 'a trailing line feed' => ["2026-10\n"];
        yield 'a trailing line feed on a quarter' => ["2026-T3\n"];
        yield 'a lower case quarter' => ['2026-t3'];
        yield 'two digits for a quarter' => ['2026-T10'];
        yield 'text before' => ['x2026-10'];
        yield 'text before a quarter' => ['x2026-T1'];
    }

    #[DataProvider('outOfRange')]
    public function testAValueOutOfRangeIsRefusedByThePeriodFactory(string $value): void
    {
        $this->expectException(ValidationFailed::class);

        CommandPeriods::fromOption($value);
    }

    #[DataProvider('zeroYears')]
    public function testALeadingZeroYearIsReadAsANumberAndRefusedAsOutOfRange(string $value): void
    {
        try {
            CommandPeriods::fromOption($value);
            static::fail('A year of zero must be refused.');
        } catch (ValidationFailed $failure) {
            $codes = array_map(static fn(Violation $violation): string => $violation->code(), $failure->result()->errors());

            static::assertNotContains('year.invalid_type', $codes);
            static::assertNotSame([], $codes);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroYears(): iterable
    {
        yield 'monthly' => ['0000-10'];
        yield 'quarterly' => ['0000-T1'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'month 13' => ['2026-13'];
        yield 'month 00' => ['2026-00'];
        yield 'quarter 5' => ['2026-T5'];
        yield 'quarter 0' => ['2026-T0'];
        yield 'year 0000' => ['0000-10'];
    }
}
