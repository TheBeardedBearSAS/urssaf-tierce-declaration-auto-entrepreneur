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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Input\PaymentOrderFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\NewSepaMandateFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\SepaRevocationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * ADR 0010 item 4: a stack trace must redact every bank datum and every raw input of this lot.
 */
final class SensitiveParametersTest extends TestCase
{
    public function testSepaMandateReadModel(): void
    {
        DomainAssertions::assertSensitiveParameters(SepaMandate::class, '__construct', ['iban', 'accountHolder']);
    }

    public function testNewSepaMandate(): void
    {
        DomainAssertions::assertSensitiveParameters(NewSepaMandate::class, 'of', ['bic', 'iban', 'holder', 'address']);
        DomainAssertions::assertSensitiveParameters(NewSepaMandate::class, '__construct', ['bic', 'iban', 'holder', 'address']);
    }

    public function testPaymentOrder(): void
    {
        DomainAssertions::assertSensitiveParameters(PaymentOrder::class, 'of', ['iban']);
        DomainAssertions::assertSensitiveParameters(PaymentOrder::class, '__construct', ['iban']);
    }

    public function testRawInputOfEveryFactoryAboutBankData(): void
    {
        foreach ([NewSepaMandateFactory::class, SepaRevocationFactory::class, PaymentOrderFactory::class] as $factory) {
            DomainAssertions::assertSensitiveParameters($factory, 'validate', ['input']);
            DomainAssertions::assertSensitiveParameters($factory, 'fromInput', ['input']);
        }
    }
}
