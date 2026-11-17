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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

final class LiberatoryContributionsTest extends TestCase
{
    public function testAccessorsAndSignedValues(): void
    {
        $liberatory = self::of([1, -2, 3, 4, 5]);

        static::assertSame(1, $liberatory->sales()?->toInt());
        static::assertSame(-2, $liberatory->furnishedRental()?->toInt());
        static::assertSame(3, $liberatory->commercialServices()?->toInt());
        static::assertSame(4, $liberatory->otherServices()?->toInt());
        static::assertSame(5, $liberatory->cipav()?->toInt());
    }

    public function testNone(): void
    {
        $none = LiberatoryContributions::none();

        static::assertNull($none->sales());
        static::assertNull($none->furnishedRental());
        static::assertNull($none->commercialServices());
        static::assertNull($none->otherServices());
        static::assertNull($none->cipav());
        static::assertTrue($none->equals(self::of([null, null, null, null, null])));
    }

    public function testEquality(): void
    {
        $base = [1, 2, 3, 4, 5];

        static::assertTrue(self::of($base)->equals(self::of($base)));
        foreach (array_keys($base) as $index) {
            $other = $base;
            $other[$index] = 9;
            static::assertFalse(self::of($base)->equals(self::of($other)), 'line ' . $index);
        }
        static::assertFalse(self::of([0, 2, 3, 4, 5])->equals(self::of([null, 2, 3, 4, 5])));
    }

    /**
     * @param list<int|null> $lines
     */
    private static function of(array $lines): LiberatoryContributions
    {
        $euros = static fn(?int $amount): ?Euros => $amount === null ? null : ContributionSamples::euros($amount);

        return LiberatoryContributions::of($euros($lines[0]), $euros($lines[1]), $euros($lines[2]), $euros($lines[3]), $euros($lines[4]));
    }
}
