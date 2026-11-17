<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\Input;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Input\PaymentOrderFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;

final class PaymentOrderFactoryTest extends TestCase
{
    public function testFromInput(): void
    {
        $order = (new PaymentOrderFactory())->fromInput([
            'period' => ['year' => 2019, 'month' => 12],
            'amount' => 1678,
            'iban' => SepaSamples::IBAN,
            'rum' => SepaSamples::RUM,
        ]);

        static::assertSame('2019-12', $order->period()->toString());
        static::assertSame(1678, $order->amount()->toInt());
        static::assertSame(SepaSamples::IBAN, $order->iban()?->value());
        static::assertSame(SepaSamples::RUM, $order->rum()?->value());
    }

    public function testIbanAndRumAreOptionalAndZeroIsAccepted(): void
    {
        $input = ['period' => ['year' => 2019, 'quarter' => 4], 'amount' => 0];
        $order = (new PaymentOrderFactory())->fromInput($input);

        static::assertNull($order->iban());
        static::assertNull($order->rum());
        static::assertSame(0, $order->amount()->toInt());
        static::assertSame([], DomainAssertions::summary((new PaymentOrderFactory())->validate($input)));
    }

    public function testMissingPartsAreReported(): void
    {
        static::assertSame(
            ['error period.year year.required', 'error period period.month_or_quarter_required', 'error amount amount.required'],
            DomainAssertions::summary((new PaymentOrderFactory())->validate([])),
        );
    }

    public function testAnInvalidIbanOrRumIsReported(): void
    {
        static::assertSame(
            ['error rum rum.too_long'],
            DomainAssertions::summary((new PaymentOrderFactory())->validate([
                'period' => ['year' => 2019, 'month' => 12],
                'amount' => 1,
                'rum' => str_repeat('1', 36),
            ])),
        );
        static::assertSame(
            ['error iban iban.empty'],
            DomainAssertions::summary((new PaymentOrderFactory())->validate([
                'period' => ['year' => 2019, 'month' => 12],
                'amount' => 1,
                'iban' => '   ',
            ])),
        );
    }

    public function testAnInvalidOptionalFieldAloneIsEnoughToRefuseTheOrder(): void
    {
        try {
            (new PaymentOrderFactory())->fromInput(['period' => ['year' => 2019, 'month' => 12], 'amount' => 1, 'iban' => '   ']);
            static::fail('An invalid payment order must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error iban iban.empty'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testNegativeOrOutOfRangeAmountsAreReported(): void
    {
        $period = ['year' => 2019, 'month' => 12];

        static::assertSame(
            ['error amount payment.amount_negative'],
            DomainAssertions::summary((new PaymentOrderFactory())->validate(['period' => $period, 'amount' => -1])),
        );
        static::assertSame(
            ['error amount euros.out_of_range'],
            DomainAssertions::summary((new PaymentOrderFactory())->validate(['period' => $period, 'amount' => 2_147_483_648])),
        );
        static::assertSame(
            ['error amount amount.invalid_type'],
            DomainAssertions::summary((new PaymentOrderFactory())->validate(['period' => $period, 'amount' => 12.5])),
        );
    }

    public function testEveryViolationIsReportedAtOnce(): void
    {
        try {
            (new PaymentOrderFactory())->fromInput(['period' => ['year' => 0, 'month' => 13], 'amount' => -1, 'rum' => '']);
            static::fail('An invalid order must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(
                ['error period.year period.year_out_of_range', 'error period.month period.month_out_of_range'],
                DomainAssertions::summary($failed->result()),
            );
        }
    }

    public function testNoRawValueAppearsInTheViolations(): void
    {
        $result = (new PaymentOrderFactory())->validate([
            'period' => ['year' => 2019, 'month' => 12],
            'amount' => 1,
            'iban' => 'SECRET-IBAN',
            'rum' => 'SECRET-' . str_repeat('1', 36),
        ]);

        static::assertNotSame([], $result->all());
        foreach ($result->all() as $violation) {
            static::assertStringNotContainsString('SECRET', $violation->message());
        }
    }

    public function testRawInputIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(PaymentOrderFactory::class, 'validate', ['input']);
        DomainAssertions::assertSensitiveParameters(PaymentOrderFactory::class, 'fromInput', ['input']);
    }
}
