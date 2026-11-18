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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class TurnoverFactoryTest extends TestCase
{
    public function testFromInput(): void
    {
        $turnover = (new TurnoverFactory())->fromInput([
            'sales' => 1,
            'furnished_rental' => '2',
            'commercial_services' => 3,
            'other_services' => 0,
            'cipav_receipts' => 5,
        ]);

        static::assertSame(1, $turnover->sales()?->toInt());
        static::assertSame(2, $turnover->furnishedRental()?->toInt());
        static::assertSame(3, $turnover->commercialServices()?->toInt());
        static::assertSame(0, $turnover->otherServices()?->toInt());
        static::assertSame(5, $turnover->cipavReceipts()?->toInt());
        static::assertSame([], DomainAssertions::summary((new TurnoverFactory())->validate(['sales' => 1])));
    }

    public function testAnEmptyMapIsValid(): void
    {
        $turnover = (new TurnoverFactory())->fromInput([]);

        static::assertNull($turnover->sales());
        static::assertNull($turnover->furnishedRental());
        static::assertNull($turnover->commercialServices());
        static::assertNull($turnover->otherServices());
        static::assertNull($turnover->cipavReceipts());
    }

    public function testEveryNegativeLineIsReportedAtItsInputKey(): void
    {
        static::assertSame(
            [
                'error sales turnover.negative',
                'error furnished_rental turnover.negative',
                'error commercial_services turnover.negative',
                'error other_services turnover.negative',
                'error cipav_receipts turnover.negative',
            ],
            DomainAssertions::summary((new TurnoverFactory())->validate([
                'sales' => -1,
                'furnished_rental' => -1,
                'commercial_services' => -1,
                'other_services' => -1,
                'cipav_receipts' => -1,
            ])),
        );
    }

    public function testOutOfRangeAndInvalidTypesAreReported(): void
    {
        static::assertSame(
            [
                'error sales euros.out_of_range',
                'error furnished_rental furnished_rental.invalid_type',
                'error other_services turnover.negative',
            ],
            DomainAssertions::summary((new TurnoverFactory())->validate([
                'sales' => 2_147_483_648,
                'furnished_rental' => 1.5,
                'other_services' => -1,
            ])),
        );
        static::assertSame(['error sales sales.invalid_type'], DomainAssertions::summary((new TurnoverFactory())->validate(['sales' => 10.0])));
    }

    public function testFromInputThrowsEveryViolation(): void
    {
        try {
            (new TurnoverFactory())->fromInput(['sales' => -1, 'cipav_receipts' => 'x']);
            static::fail('An invalid turnover must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error cipav_receipts cipav_receipts.invalid_type', 'error sales turnover.negative'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testRawInputIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(TurnoverFactory::class, 'validate', ['input']);
        DomainAssertions::assertSensitiveParameters(TurnoverFactory::class, 'fromInput', ['input']);
    }
}
