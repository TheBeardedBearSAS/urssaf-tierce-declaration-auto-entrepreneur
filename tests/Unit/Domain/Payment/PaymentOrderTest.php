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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;

final class PaymentOrderTest extends TestCase
{
    public function testAccessors(): void
    {
        $period = MonthlyPeriod::of(2019, 12);
        $order = PaymentOrder::of($period, Euros::of(1678), Iban::fromString(SepaSamples::IBAN), Rum::fromString(SepaSamples::RUM));

        static::assertSame($period, $order->period());
        static::assertSame(1678, $order->amount()->toInt());
        static::assertSame(SepaSamples::IBAN, $order->iban()?->value());
        static::assertSame(SepaSamples::RUM, $order->rum()?->value());
        static::assertTrue($order->hasIban());
    }

    public function testIbanAndRumAreOptional(): void
    {
        $order = PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(1), null, null);

        static::assertNull($order->iban());
        static::assertNull($order->rum());
        static::assertFalse($order->hasIban());
    }

    public function testZeroIsAccepted(): void
    {
        static::assertSame(0, PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(0), null, null)->amount()->toInt());
    }

    public function testANegativeAmountIsRefused(): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'payment.amount_negative',
            static fn(): PaymentOrder => PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(-1), null, null),
        );

        static::assertSame([], $exception->params());
    }

    public function testTheIbanIsMaskedWhenDumped(): void
    {
        $order = PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(1678), Iban::fromString(SepaSamples::IBAN), Rum::fromString(SepaSamples::RUM));

        static::assertSame(
            ['period' => '2019-12', 'amount' => 1678, 'iban' => 'FR76…0185', 'rum' => SepaSamples::RUM],
            $order->__debugInfo(),
        );
        static::assertStringNotContainsString(SepaSamples::IBAN, print_r($order, true));
        $bare = PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(1), null, null)->__debugInfo();
        static::assertNull($bare['iban']);
        static::assertNull($bare['rum']);
    }
}
