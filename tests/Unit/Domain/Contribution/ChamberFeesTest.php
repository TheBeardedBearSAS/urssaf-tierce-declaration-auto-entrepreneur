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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

final class ChamberFeesTest extends TestCase
{
    public function testAccessors(): void
    {
        $fees = self::of([1, 2, 3, 4]);

        static::assertSame(1, $fees->cmaSales()?->toInt());
        static::assertSame(2, $fees->cmaServices()?->toInt());
        static::assertSame(3, $fees->cciSales()?->toInt());
        static::assertSame(4, $fees->cciServices()?->toInt());
    }

    public function testNone(): void
    {
        $none = ChamberFees::none();

        static::assertNull($none->cmaSales());
        static::assertNull($none->cmaServices());
        static::assertNull($none->cciSales());
        static::assertNull($none->cciServices());
        static::assertTrue($none->equals(self::of([null, null, null, null])));
    }

    public function testEquality(): void
    {
        $base = [1, 2, 3, 4];

        static::assertTrue(self::of($base)->equals(self::of($base)));
        foreach (array_keys($base) as $index) {
            $other = $base;
            $other[$index] = 9;
            static::assertFalse(self::of($base)->equals(self::of($other)), 'fee ' . $index);
        }
        static::assertFalse(self::of([0, 2, 3, 4])->equals(self::of([null, 2, 3, 4])));
    }

    /**
     * @param list<int|null> $fees
     */
    private static function of(array $fees): ChamberFees
    {
        $euros = static fn(?int $amount): ?Euros => $amount === null ? null : ContributionSamples::euros($amount);

        return ChamberFees::of($euros($fees[0]), $euros($fees[1]), $euros($fees[2]), $euros($fees[3]));
    }
}
