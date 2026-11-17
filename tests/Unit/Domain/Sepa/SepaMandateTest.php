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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Creditor;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;

final class SepaMandateTest extends TestCase
{
    public function testAccessors(): void
    {
        $rum = Rum::fromString(SepaSamples::RUM);
        $siret = Siret::fromString('12345678901234');
        $creditor = new Creditor('URSSAF', null, null, null, null, null, null);
        $mandate = new SepaMandate($rum, 'BANQUE', 'FR76XXXXXXXXXXXXXXXXXXX0185', SepaSamples::HOLDER, $siret, $creditor);

        static::assertSame($rum, $mandate->rum());
        static::assertSame('BANQUE', $mandate->bankName());
        static::assertSame('FR76XXXXXXXXXXXXXXXXXXX0185', $mandate->iban());
        static::assertSame(SepaSamples::HOLDER, $mandate->accountHolder());
        static::assertSame($siret, $mandate->siret());
        static::assertSame($creditor, $mandate->creditor());
    }

    public function testEveryFieldIsOptional(): void
    {
        $mandate = new SepaMandate(null, null, null, null, null, null);

        static::assertNull($mandate->rum());
        static::assertNull($mandate->bankName());
        static::assertNull($mandate->iban());
        static::assertNull($mandate->accountHolder());
        static::assertNull($mandate->siret());
        static::assertNull($mandate->creditor());
        static::assertNull($mandate->__debugInfo()['iban']);
        static::assertNull($mandate->__debugInfo()['accountHolder']);
    }

    public function testIbanAndHolderAreMaskedEntirelyWhenDumped(): void
    {
        $mandate = new SepaMandate(Rum::fromString(SepaSamples::RUM), 'BANQUE', 'FR76XXXXXXXXXXXXXXXXXXX0185', SepaSamples::HOLDER, null, null);
        $dump = $mandate->__debugInfo();

        static::assertSame('***', $dump['iban']);
        static::assertSame('***', $dump['accountHolder']);
        static::assertSame('BANQUE', $dump['bankName']);
        static::assertSame(SepaSamples::RUM, $dump['rum']?->value());
        static::assertNull($dump['siret']);
        static::assertNull($dump['creditor']);

        $siret = Siret::fromString('12345678901234');
        $creditor = new Creditor('URSSAF', null, null, null, null, null, null);
        $full = (new SepaMandate(null, null, null, null, $siret, $creditor))->__debugInfo();
        static::assertSame($siret, $full['siret']);
        static::assertSame($creditor, $full['creditor']);
        static::assertStringNotContainsString('LEFORESTIER', print_r($mandate, true));
        static::assertStringNotContainsString('0185', print_r($mandate, true));
    }
}
