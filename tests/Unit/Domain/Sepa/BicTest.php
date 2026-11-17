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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class BicTest extends TestCase
{
    public function testItIsKeptVerbatim(): void
    {
        static::assertSame(SepaSamples::BIC, Bic::fromString(SepaSamples::BIC)->value());
        static::assertSame(' abc ', Bic::fromString(' abc ')->value());
    }

    public function testLengthBounds(): void
    {
        static::assertSame(11, mb_strlen(Bic::fromString(str_repeat('é', 11))->value()));
        $exception = DomainAssertions::assertViolationCode('bic.too_long', static fn(): Bic => Bic::fromString(str_repeat('é', 12)));
        static::assertSame(['max_length' => 11], $exception->params());
    }

    public function testBlankIsRefused(): void
    {
        static::assertSame('bic.empty', DomainAssertions::assertViolationCode('bic.empty', static fn(): Bic => Bic::fromString(''))->violationCode());
        static::assertSame('bic.empty', DomainAssertions::assertViolationCode('bic.empty', static fn(): Bic => Bic::fromString(' '))->violationCode());
    }

    public function testEquality(): void
    {
        static::assertTrue(Bic::fromString('A')->equals(Bic::fromString('A')));
        static::assertFalse(Bic::fromString('A')->equals(Bic::fromString('B')));
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $bic = Bic::fromString(SepaSamples::BIC);

        static::assertSame(['value' => '***'], $bic->__debugInfo());
        static::assertStringNotContainsString(SepaSamples::BIC, print_r($bic, true));
        static::assertNotContains('__toString', get_class_methods($bic));
    }

    public function testTheValueIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(Bic::class, 'fromString', ['value']);
        DomainAssertions::assertSensitiveParameters(Bic::class, '__construct', ['value']);
    }
}
