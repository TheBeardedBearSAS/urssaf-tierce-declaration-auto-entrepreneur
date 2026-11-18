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
use ReflectionMethod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class IbanTest extends TestCase
{
    public function testItIsKeptVerbatim(): void
    {
        $iban = Iban::fromString(' fr76 3000 ');

        static::assertSame(' fr76 3000 ', $iban->value());
        static::assertSame(11, $iban->length());
    }

    public function testPaymentOrderIbanHasNoBoundNorPattern(): void
    {
        static::assertSame(100, Iban::fromString(str_repeat('x', 100))->length());
        static::assertSame(1, Iban::fromString('x')->length());
    }

    public function testBlankIsRefused(): void
    {
        static::assertSame('iban.empty', DomainAssertions::assertViolationCode('iban.empty', static fn(): Iban => Iban::fromString(''))->violationCode());
        static::assertSame('iban.empty', DomainAssertions::assertViolationCode('iban.empty', static fn(): Iban => Iban::fromString('   '))->violationCode());
        static::assertSame('iban.empty', DomainAssertions::assertViolationCode('iban.empty', static fn(): Iban => Iban::forSepaMandate(''))->violationCode());
        static::assertSame('iban.empty', DomainAssertions::assertViolationCode('iban.empty', static fn(): Iban => Iban::forSepaMandate(' '))->violationCode());
    }

    public function testSepaMandateBound(): void
    {
        static::assertSame(34, Iban::forSepaMandate(str_repeat('é', 34))->length());
        static::assertSame(SepaSamples::IBAN, Iban::forSepaMandate(SepaSamples::IBAN)->value());
        $exception = DomainAssertions::assertViolationCode('iban.too_long', static fn(): Iban => Iban::forSepaMandate(str_repeat('é', 35)));
        static::assertSame(['max_length' => 34], $exception->params());
    }

    public function testMasking(): void
    {
        static::assertSame('FR76…0185', Iban::fromString(SepaSamples::IBAN)->masked());
        static::assertSame('1234…6789', Iban::fromString('123456789')->masked());
        static::assertSame('***', Iban::fromString('12345678')->masked());
        static::assertSame('***', Iban::fromString('1')->masked());
        static::assertSame('éééé…éééé', Iban::fromString(str_repeat('é', 9))->masked());
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $iban = Iban::fromString(SepaSamples::IBAN);

        static::assertSame(['value' => 'FR76…0185'], $iban->__debugInfo());
        static::assertStringNotContainsString(SepaSamples::IBAN, print_r($iban, true));
        static::assertNotContains('__toString', get_class_methods($iban));
    }

    public function testEquality(): void
    {
        static::assertTrue(Iban::fromString('A')->equals(Iban::fromString('A')));
        static::assertFalse(Iban::fromString('A')->equals(Iban::fromString('B')));
    }

    public function testTheValueIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(Iban::class, 'fromString', ['value']);
        DomainAssertions::assertSensitiveParameters(Iban::class, 'forSepaMandate', ['value']);
        DomainAssertions::assertSensitiveParameters(Iban::class, '__construct', ['value']);
        static::assertTrue((new ReflectionMethod(Iban::class, '__construct'))->isPrivate());
    }
}
