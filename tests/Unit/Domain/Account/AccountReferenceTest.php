<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Account;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class AccountReferenceTest extends TestCase
{
    public function testAccessors(): void
    {
        $reference = AccountReference::of(AccountSamples::siret(), AccountSamples::birthDate());

        static::assertSame('12345678901234', $reference->siret()->toString());
        static::assertSame('1975-10-01', $reference->birthDate()->toString());
    }

    public function testEquality(): void
    {
        $reference = AccountReference::of(AccountSamples::siret(), AccountSamples::birthDate());

        static::assertTrue($reference->equals(AccountReference::of(AccountSamples::siret(), AccountSamples::birthDate())));
        static::assertFalse($reference->equals(AccountReference::of(Siret::fromString('12345678901235'), AccountSamples::birthDate())));
        static::assertFalse($reference->equals(AccountReference::of(AccountSamples::siret(), CalendarDate::fromString('1975-10-02'))));
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $reference = AccountReference::of(AccountSamples::siret(), AccountSamples::birthDate());

        static::assertSame(['siret' => '**********1234', 'birthDate' => '***'], $reference->__debugInfo());
    }

    public function testTheBirthDateIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(AccountReference::class, 'of', ['birthDate']);
        DomainAssertions::assertSensitiveParameters(AccountReference::class, '__construct', ['birthDate']);
        static::assertTrue((new ReflectionMethod(AccountReference::class, '__construct'))->isPrivate());
    }
}
