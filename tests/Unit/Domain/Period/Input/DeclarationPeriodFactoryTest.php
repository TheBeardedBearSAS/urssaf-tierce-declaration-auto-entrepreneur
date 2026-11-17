<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Period\Input;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Input\DeclarationPeriodFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class DeclarationPeriodFactoryTest extends TestCase
{
    public function testMonthlyPeriod(): void
    {
        $period = (new DeclarationPeriodFactory())->fromInput(['year' => 2019, 'month' => 12]);

        static::assertInstanceOf(MonthlyPeriod::class, $period);
        static::assertSame('2019-12', $period->toString());
        static::assertSame([], DomainAssertions::summary((new DeclarationPeriodFactory())->validate(['year' => 2019, 'month' => 12])));
    }

    public function testQuarterlyPeriod(): void
    {
        $period = (new DeclarationPeriodFactory())->fromInput(['year' => '2019', 'quarter' => '3']);

        static::assertInstanceOf(QuarterlyPeriod::class, $period);
        static::assertSame('2019-Q3', $period->toString());
    }

    public function testBoundsAreAccepted(): void
    {
        $factory = new DeclarationPeriodFactory();

        static::assertSame('0001-01', $factory->fromInput(['year' => 1, 'month' => 1])->toString());
        static::assertSame('9999-12', $factory->fromInput(['year' => 9999, 'month' => 12])->toString());
        static::assertSame('9999-Q4', $factory->fromInput(['year' => 9999, 'quarter' => 4])->toString());
    }

    public function testMissingFieldsAreReported(): void
    {
        static::assertSame(
            ['error year year.required', 'error  period.month_or_quarter_required'],
            DomainAssertions::summary((new DeclarationPeriodFactory())->validate([])),
        );
        static::assertSame(
            ['error  period.month_or_quarter_required'],
            DomainAssertions::summary((new DeclarationPeriodFactory())->validate(['year' => 2019])),
        );
        static::assertSame(
            ['error year year.required'],
            DomainAssertions::summary((new DeclarationPeriodFactory())->validate(['month' => 1])),
        );
    }

    public function testMonthAndQuarterTogetherAreRefused(): void
    {
        static::assertSame(
            ['error  period.month_and_quarter'],
            DomainAssertions::summary((new DeclarationPeriodFactory())->validate(['year' => 2019, 'month' => 1, 'quarter' => 1])),
        );
    }

    public function testEveryRangeViolationIsReportedAtOnce(): void
    {
        $factory = new DeclarationPeriodFactory();

        static::assertSame(
            ['error year period.year_out_of_range', 'error month period.month_out_of_range'],
            DomainAssertions::summary($factory->validate(['year' => 0, 'month' => 13])),
        );
        static::assertSame(
            ['error year period.year_out_of_range', 'error quarter period.quarter_out_of_range'],
            DomainAssertions::summary($factory->validate(['year' => 10_000, 'quarter' => 5])),
        );
        static::assertSame(['error month period.month_out_of_range'], DomainAssertions::summary($factory->validate(['year' => 2019, 'month' => 0])));
        static::assertSame(['error quarter period.quarter_out_of_range'], DomainAssertions::summary($factory->validate(['year' => 2019, 'quarter' => 0])));
        static::assertSame(['error year period.year_out_of_range'], DomainAssertions::summary($factory->validate(['year' => 0, 'month' => 1])));
        static::assertSame(['error year period.year_out_of_range'], DomainAssertions::summary($factory->validate(['year' => 10_000, 'month' => 12])));
    }

    public function testAnInvalidTypeIsReportedAndNeverTriggersTheRequiredNumber(): void
    {
        $factory = new DeclarationPeriodFactory();

        static::assertSame(['error month month.invalid_type'], DomainAssertions::summary($factory->validate(['year' => 2019, 'month' => 'x'])));
        static::assertSame(['error quarter quarter.invalid_type'], DomainAssertions::summary($factory->validate(['year' => 2019, 'quarter' => 1.5])));
        static::assertSame(['error year year.invalid_type'], DomainAssertions::summary($factory->validate(['year' => [], 'month' => 1])));
    }

    public function testFromInputThrowsEveryViolation(): void
    {
        try {
            (new DeclarationPeriodFactory())->fromInput(['year' => 0, 'month' => 13]);
            static::fail('An invalid period must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error year period.year_out_of_range', 'error month period.month_out_of_range'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testRawInputIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(DeclarationPeriodFactory::class, 'validate', ['input']);
        DomainAssertions::assertSensitiveParameters(DeclarationPeriodFactory::class, 'fromInput', ['input']);
    }
}
