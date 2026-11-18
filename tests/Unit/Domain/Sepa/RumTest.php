<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class RumTest extends TestCase
{
    public function testItIsKeptVerbatim(): void
    {
        $rum = Rum::fromString(SepaSamples::RUM);

        static::assertSame(SepaSamples::RUM, $rum->value());
        static::assertSame(SepaSamples::RUM, (string) $rum);
        static::assertSame(' a ', Rum::fromString(' a ')->value());
    }

    public function testLengthBounds(): void
    {
        static::assertSame(35, mb_strlen(Rum::fromString(str_repeat('é', 35))->value()));
        $exception = DomainAssertions::assertViolationCode('rum.too_long', static fn(): Rum => Rum::fromString(str_repeat('é', 36)));
        static::assertSame(['max_length' => 35], $exception->params());
        static::assertSame(1, mb_strlen(Rum::fromString('T')->value()));
    }

    public function testBlankIsRefused(): void
    {
        static::assertSame('rum.empty', DomainAssertions::assertViolationCode('rum.empty', static fn(): Rum => Rum::fromString(''))->violationCode());
        static::assertSame('rum.empty', DomainAssertions::assertViolationCode('rum.empty', static fn(): Rum => Rum::fromString('  '))->violationCode());
    }

    public function testEquality(): void
    {
        static::assertTrue(Rum::fromString('A')->equals(Rum::fromString('A')));
        static::assertFalse(Rum::fromString('A')->equals(Rum::fromString('B')));
    }
}
