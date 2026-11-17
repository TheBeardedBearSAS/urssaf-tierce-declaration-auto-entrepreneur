<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Contribution\Input;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverDeclarationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class TurnoverDeclarationFactoryTest extends TestCase
{
    public function testFromInput(): void
    {
        $declaration = (new TurnoverDeclarationFactory())->fromInput([
            'period' => ['year' => 2019, 'quarter' => 3],
            'turnover' => ['sales' => 1500, 'furnished_rental' => 0],
        ]);

        static::assertSame('2019-Q3', $declaration->period()->toString());
        static::assertSame(1500, $declaration->turnover()->sales()?->toInt());
        static::assertSame(0, $declaration->turnover()->furnishedRental()?->toInt());
        static::assertNull($declaration->turnover()->cipavReceipts());
    }

    public function testAnEmptyTurnoverIsValid(): void
    {
        $input = ['period' => ['year' => 2019, 'month' => 12], 'turnover' => []];

        static::assertSame([], DomainAssertions::summary((new TurnoverDeclarationFactory())->validate($input)));
        static::assertNull((new TurnoverDeclarationFactory())->fromInput($input)->turnover()->sales());
    }

    public function testMissingPartsAreReported(): void
    {
        static::assertSame(
            ['error period.year year.required', 'error period period.month_or_quarter_required', 'error turnover turnover.required'],
            DomainAssertions::summary((new TurnoverDeclarationFactory())->validate([])),
        );
        static::assertSame(
            ['error turnover turnover.required'],
            DomainAssertions::summary((new TurnoverDeclarationFactory())->validate(['period' => ['year' => 2019, 'month' => 1], 'turnover' => null])),
        );
    }

    public function testNestedViolationsAreReportedAtTheirPaths(): void
    {
        static::assertSame(
            [
                'error period.month period.month_out_of_range',
                'error turnover.sales euros.out_of_range',
                'error turnover.furnished_rental turnover.negative',
            ],
            DomainAssertions::summary((new TurnoverDeclarationFactory())->validate([
                'period' => ['year' => 2019, 'month' => 13],
                'turnover' => ['furnished_rental' => -5, 'sales' => 2_147_483_648],
            ])),
        );
    }

    public function testAnInvalidTurnoverTypeIsReportedWithoutRequired(): void
    {
        static::assertSame(
            ['error turnover turnover.invalid_type'],
            DomainAssertions::summary((new TurnoverDeclarationFactory())->validate(['period' => ['year' => 2019, 'month' => 1], 'turnover' => 'x'])),
        );
        static::assertSame(
            ['error period period.invalid_type'],
            DomainAssertions::summary((new TurnoverDeclarationFactory())->validate(['period' => 'x', 'turnover' => []])),
        );
    }

    public function testAnInvalidPartIsEnoughToFail(): void
    {
        try {
            (new TurnoverDeclarationFactory())->fromInput(['period' => ['year' => 2019, 'month' => 1], 'turnover' => ['sales' => -1]]);
            static::fail('An invalid declaration must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error turnover.sales turnover.negative'], DomainAssertions::summary($failed->result()));
        }
        try {
            (new TurnoverDeclarationFactory())->fromInput(['period' => ['year' => 0, 'month' => 1], 'turnover' => []]);
            static::fail('An invalid declaration must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error period.year period.year_out_of_range'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testRawInputIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(TurnoverDeclarationFactory::class, 'validate', ['input']);
        DomainAssertions::assertSensitiveParameters(TurnoverDeclarationFactory::class, 'fromInput', ['input']);
    }
}
