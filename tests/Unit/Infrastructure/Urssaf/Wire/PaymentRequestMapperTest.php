<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

final class PaymentRequestMapperTest extends TestCase
{
    public function testTheBodyMatchesTheOfficialRequest(): void
    {
        WireBodyAssertions::assertMatchesFixture('pay_request', PaymentRequestMapper::body(WireSamples::paymentOrder()));
    }

    public function testWithoutAnIbanOnlyTheRumIsSent(): void
    {
        $order = PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(88), null, Rum::fromString(WireSamples::RUM));

        static::assertSame(
            '{"montant":88,"rum":"' . WireSamples::RUM . '","periode":{"annee":2019,"mois":12}}',
            JsonEncoder::encode(PaymentRequestMapper::body($order)),
        );
    }

    public function testWithoutARumOnlyTheIbanIsSent(): void
    {
        $order = PaymentOrder::of(
            MonthlyPeriod::of(2019, 12),
            Euros::of(88),
            Iban::fromString(WireSamples::PAYMENT_IBAN),
            null,
        );

        static::assertSame(
            '{"montant":88,"iban":"' . WireSamples::PAYMENT_IBAN . '","periode":{"annee":2019,"mois":12}}',
            JsonEncoder::encode(PaymentRequestMapper::body($order)),
        );
    }

    public function testWithNeitherOnlyTheAmountAndThePeriodAreSent(): void
    {
        $order = PaymentOrder::of(MonthlyPeriod::of(2019, 12), Euros::of(0), null, null);

        static::assertSame(
            '{"montant":0,"periode":{"annee":2019,"mois":12}}',
            JsonEncoder::encode(PaymentRequestMapper::body($order)),
        );
    }
}
