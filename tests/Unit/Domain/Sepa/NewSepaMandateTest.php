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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class NewSepaMandateTest extends TestCase
{
    public function testAccessors(): void
    {
        $mandate = SepaSamples::newMandate();

        static::assertSame(SepaSamples::BIC, $mandate->bic()->value());
        static::assertSame(SepaSamples::IBAN, $mandate->iban()->value());
        static::assertSame(SepaSamples::HOLDER, $mandate->holder()->value());
        static::assertSame('LILLE', $mandate->address()->city());
    }

    public function testAnIbanTooLongForMethod070IsRefused(): void
    {
        $exception = DomainAssertions::assertViolationCode('iban.too_long', static fn(): NewSepaMandate => NewSepaMandate::of(
            Bic::fromString(SepaSamples::BIC),
            Iban::fromString(str_repeat('1', 35)),
            AccountHolder::fromString(SepaSamples::HOLDER),
            SepaSamples::address(),
        ));
        static::assertSame(['max_length' => 34], $exception->params());
    }

    public function testAnIbanOfTheMaximumLengthIsAccepted(): void
    {
        $mandate = NewSepaMandate::of(
            Bic::fromString(SepaSamples::BIC),
            Iban::fromString(str_repeat('1', 34)),
            AccountHolder::fromString(SepaSamples::HOLDER),
            SepaSamples::address(),
        );

        static::assertSame(34, $mandate->iban()->length());
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $dump = SepaSamples::newMandate()->__debugInfo();

        static::assertSame(['bic' => '***', 'iban' => '***', 'holder' => '***', 'address' => '***'], $dump);
        static::assertStringNotContainsString(SepaSamples::IBAN, print_r(SepaSamples::newMandate(), true));
    }
}
