<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Money;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class EurosTest extends TestCase
{
    public function testItKeepsTheInteger(): void
    {
        static::assertSame(1678, Euros::of(1678)->toInt());
        static::assertSame(0, Euros::of(0)->toInt());
    }

    public function testTheInt32BoundsAreAccepted(): void
    {
        static::assertSame(2_147_483_647, Euros::of(2_147_483_647)->toInt());
        static::assertSame(-2_147_483_648, Euros::of(-2_147_483_648)->toInt());
    }

    public function testOutsideTheInt32RangeIsRefused(): void
    {
        $exception = DomainAssertions::assertViolationCode('euros.out_of_range', static fn(): Euros => Euros::of(2_147_483_648));
        static::assertSame(['min' => -2_147_483_648, 'max' => 2_147_483_647], $exception->params());
        static::assertSame('euros.out_of_range', DomainAssertions::assertViolationCode('euros.out_of_range', static fn(): Euros => Euros::of(-2_147_483_649))->violationCode());
    }

    public function testNegativity(): void
    {
        static::assertTrue(Euros::of(-1)->isNegative());
        static::assertFalse(Euros::of(0)->isNegative());
        static::assertFalse(Euros::of(1)->isNegative());
    }

    public function testEquality(): void
    {
        static::assertTrue(Euros::of(5)->equals(Euros::of(5)));
        static::assertFalse(Euros::of(5)->equals(Euros::of(6)));
    }

    public function testOptionalAmountsAreComparedWithAbsenceDistinctFromZero(): void
    {
        static::assertTrue(Euros::same(null, null));
        static::assertFalse(Euros::same(null, Euros::of(0)));
        static::assertFalse(Euros::same(Euros::of(0), null));
        static::assertTrue(Euros::same(Euros::of(3), Euros::of(3)));
        static::assertFalse(Euros::same(Euros::of(3), Euros::of(4)));
    }
}
