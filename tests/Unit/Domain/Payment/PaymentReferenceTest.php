<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;

final class PaymentReferenceTest extends TestCase
{
    public function testKeepsTheValueVerbatim(): void
    {
        $reference = PaymentReference::fromString(' Payment 42 ');

        static::assertSame(' Payment 42 ', $reference->value());
        static::assertSame(' Payment 42 ', (string) $reference);
    }

    public function testAcceptsExactlyTheMaximumLength(): void
    {
        $value = str_repeat('a', 191);

        static::assertSame($value, PaymentReference::fromString($value)->value());
    }

    public function testCountsCharactersNotBytes(): void
    {
        $value = str_repeat('é', 191);

        static::assertSame($value, PaymentReference::fromString($value)->value());
    }

    public function testRejectsAValueLongerThanTheMaximum(): void
    {
        $exception = PaymentSamples::assertViolationException(
            'payment_reference.too_long',
            static fn(): PaymentReference => PaymentReference::fromString(str_repeat('a', 192)),
        );

        static::assertInstanceOf(InvalidValue::class, $exception);
        static::assertSame(['max_length' => 191], $exception->params());
    }

    public function testRejectsBlankValues(): void
    {
        foreach (['', ' ', "\t\n"] as $blank) {
            PaymentSamples::assertViolation('payment_reference.empty', static fn(): PaymentReference => PaymentReference::fromString($blank));
        }
    }

    public function testEquality(): void
    {
        static::assertTrue(PaymentReference::fromString('a')->equals(PaymentReference::fromString('a')));
        static::assertFalse(PaymentReference::fromString('a')->equals(PaymentReference::fromString('b')));
        static::assertFalse(PaymentReference::fromString('a')->equals(PaymentReference::fromString('A')));
    }
}
