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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;

final class PeriodicityTest extends TestCase
{
    public function testCodes(): void
    {
        static::assertSame('M', Periodicity::Monthly->value);
        static::assertSame('T', Periodicity::Quarterly->value);
    }

    public function testFromCode(): void
    {
        static::assertSame(Periodicity::Monthly, Periodicity::fromCode('M'));
        static::assertSame(Periodicity::Quarterly, Periodicity::fromCode('T'));
    }

    public function testUnknownCodesAreNull(): void
    {
        foreach (['', 'm', 't', ' M', 'T ', 'A', 'Q'] as $code) {
            static::assertNull(Periodicity::fromCode($code));
        }
    }
}
