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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

final class PaymentResultTest extends TestCase
{
    public function testAcceptedPayment(): void
    {
        $result = PaymentSamples::result();

        static::assertSame('12345678', $result->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $result->paymentId());
        static::assertSame('2026-11-20', $result->dueDate()?->toString());
        static::assertSame('2026-10', $result->period()?->toString());
        static::assertFalse($result->isResolution());
    }

    public function testThePaymentIdentifierIsOptional(): void
    {
        $result = PaymentResult::of(Certificate::fromString('12345678'), null, CalendarDate::fromString('2026-11-20'), MonthlyPeriod::of(2026, 10));

        static::assertNull($result->paymentId());
        static::assertFalse($result->isResolution());
    }

    public function testAnOperatorResolutionKnowsTheCertificateOnly(): void
    {
        $result = PaymentResult::resolved(Certificate::fromString('87654321'));

        static::assertSame('87654321', $result->certificate()->value());
        static::assertNull($result->paymentId());
        static::assertNull($result->dueDate());
        static::assertNull($result->period());
        static::assertTrue($result->isResolution());
    }

    public function testEquality(): void
    {
        $reference = PaymentSamples::result();
        $date = CalendarDate::fromString('2026-11-20');
        $certificate = Certificate::fromString('12345678');
        $period = MonthlyPeriod::of(2026, 10);

        static::assertTrue($reference->equals(PaymentResult::of($certificate, 'W1dl9W2Oa4R6ab3UlQAqxF2', $date, $period)));
        static::assertFalse($reference->equals(PaymentResult::of(Certificate::fromString('12345679'), 'W1dl9W2Oa4R6ab3UlQAqxF2', $date, $period)));
        static::assertFalse($reference->equals(PaymentResult::of($certificate, 'other', $date, $period)));
        static::assertFalse($reference->equals(PaymentResult::of($certificate, null, $date, $period)));
        static::assertFalse($reference->equals(PaymentResult::of($certificate, 'W1dl9W2Oa4R6ab3UlQAqxF2', CalendarDate::fromString('2026-11-21'), $period)));
        static::assertFalse($reference->equals(PaymentResult::of($certificate, 'W1dl9W2Oa4R6ab3UlQAqxF2', $date, MonthlyPeriod::of(2026, 11))));
        static::assertFalse($reference->equals(PaymentResult::of($certificate, 'W1dl9W2Oa4R6ab3UlQAqxF2', $date, QuarterlyPeriod::of(2026, 4))));
        static::assertFalse($reference->equals(PaymentResult::resolved($certificate)));
        static::assertFalse(PaymentResult::resolved($certificate)->equals($reference));
        static::assertTrue(PaymentResult::resolved($certificate)->equals(PaymentResult::resolved(Certificate::fromString('12345678'))));
    }
}
