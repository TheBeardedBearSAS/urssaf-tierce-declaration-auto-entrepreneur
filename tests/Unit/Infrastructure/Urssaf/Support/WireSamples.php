<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandateAddress;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Domain objects matching the request fixtures of `tests/Fixtures/wire`, built from fixture values only (fictitious
 * data). Shared by the request mapper tests and the encoding benchmark.
 */
final class WireSamples
{
    public const RUM = 'T1234567891234567891234568912345678';
    public const PAYMENT_IBAN = 'FR7630001007941234567890185';

    private function __construct() {}

    public static function siret(): Siret
    {
        return Siret::fromString('12345678912345');
    }

    public static function accountReference(): AccountReference
    {
        return AccountReference::of(Siret::fromString('12345678901234'), CalendarDate::fromString('1975-10-01'));
    }

    public static function turnover(): Turnover
    {
        return Turnover::of(Euros::of(1000), Euros::of(2000), Euros::of(3000), Euros::of(4000), Euros::of(0));
    }

    public static function declaration(): TurnoverDeclaration
    {
        return TurnoverDeclaration::of(QuarterlyPeriod::of(2019, 3), self::turnover());
    }

    public static function paymentOrder(): PaymentOrder
    {
        return PaymentOrder::of(
            MonthlyPeriod::of(2019, 12),
            Euros::of(88),
            Iban::fromString(self::PAYMENT_IBAN),
            Rum::fromString(self::RUM),
        );
    }

    public static function newSepaMandate(): NewSepaMandate
    {
        return NewSepaMandate::of(
            Bic::fromString('ABCDEFGHIJK'),
            Iban::forSepaMandate('FR0012345123451234567891A12'),
            AccountHolder::fromString('Maxime LEFORESTIER'),
            SepaMandateAddress::of('13 boulevard vauban', '13 boulevard vauban', '59000', 'LILLE', 'FRANCE'),
        );
    }

    public static function revocation(): SepaRevocation
    {
        return SepaRevocation::of(self::siret(), Rum::fromString(self::RUM), CalendarDate::fromString('2020-07-20'));
    }
}
