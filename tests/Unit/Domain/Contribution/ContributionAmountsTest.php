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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

final class ContributionAmountsTest extends TestCase
{
    public function testAccessors(): void
    {
        $amounts = ContributionSamples::amounts(1678);

        static::assertSame(1678, $amounts->total()->toInt());
        static::assertSame(100, $amounts->sales()?->toInt());
        static::assertSame(200, $amounts->furnishedRental()?->toInt());
        static::assertSame(300, $amounts->commercialServices()?->toInt());
        static::assertSame(400, $amounts->otherServices()?->toInt());
        static::assertSame(500, $amounts->cipav()?->toInt());
        static::assertSame(600, $amounts->vocationalTraining()?->toInt());
        static::assertSame(5, $amounts->liberatory()->cipav()?->toInt());
        static::assertSame(9, $amounts->chamberFees()->cciServices()?->toInt());
        static::assertSame(-10, $amounts->acreRegularisation()?->toInt());
        static::assertSame(-20, $amounts->deduction()?->toInt());
    }

    public function testEquality(): void
    {
        static::assertTrue(ContributionSamples::amounts(1)->equals(ContributionSamples::amounts(1)));
        static::assertFalse(ContributionSamples::amounts(1)->equals(ContributionSamples::amounts(2)));

        foreach (['sales', 'furnishedRental', 'commercialServices', 'otherServices', 'cipav', 'vocationalTraining', 'acreRegularisation', 'deduction'] as $field) {
            static::assertFalse(self::with([$field => 777])->equals(self::with([])), $field . ' differs');
            static::assertFalse(self::with([$field => null])->equals(self::with([])), $field . ' absent');
            static::assertTrue(self::with([$field => null])->equals(self::with([$field => null])), $field . ' both absent');
        }
        static::assertFalse(self::build([], LiberatoryContributions::none(), self::fees())->equals(self::with([])));
        static::assertFalse(self::build([], self::liberatory(), ChamberFees::none())->equals(self::with([])));
    }

    public function testOptionalAmountsAreComparedWithAbsence(): void
    {
        static::assertTrue(ContributionAmounts::same(null, null));
        static::assertFalse(ContributionAmounts::same(null, ContributionSamples::amounts(1)));
        static::assertFalse(ContributionAmounts::same(ContributionSamples::amounts(1), null));
        static::assertTrue(ContributionAmounts::same(ContributionSamples::amounts(1), ContributionSamples::amounts(1)));
        static::assertFalse(ContributionAmounts::same(ContributionSamples::amounts(1), ContributionSamples::amounts(2)));
    }

    private static function euros(int|null $value): ?Euros
    {
        return $value === null ? null : Euros::of($value);
    }

    private static function liberatory(): LiberatoryContributions
    {
        return LiberatoryContributions::of(Euros::of(1), Euros::of(2), Euros::of(3), Euros::of(4), Euros::of(5));
    }

    private static function fees(): ChamberFees
    {
        return ChamberFees::of(Euros::of(6), Euros::of(7), Euros::of(8), Euros::of(9));
    }

    /**
     * @param array<string, int|null> $override
     */
    private static function with(array $override): ContributionAmounts
    {
        return self::build($override, self::liberatory(), self::fees());
    }

    /**
     * @param array<string, int|null> $override
     */
    private static function build(array $override, LiberatoryContributions $liberatory, ChamberFees $fees): ContributionAmounts
    {
        $lines = array_merge([
            'sales' => 100,
            'furnishedRental' => 200,
            'commercialServices' => 300,
            'otherServices' => 400,
            'cipav' => 500,
            'vocationalTraining' => 600,
            'acreRegularisation' => -10,
            'deduction' => -20,
        ], $override);

        return ContributionAmounts::of(
            Euros::of(1678),
            self::euros($lines['sales']),
            self::euros($lines['furnishedRental']),
            self::euros($lines['commercialServices']),
            self::euros($lines['otherServices']),
            self::euros($lines['cipav']),
            self::euros($lines['vocationalTraining']),
            $liberatory,
            $fees,
            self::euros($lines['acreRegularisation']),
            self::euros($lines['deduction']),
        );
    }
}
