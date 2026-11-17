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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class AccountHolderTest extends TestCase
{
    public function testItIsKeptVerbatim(): void
    {
        static::assertSame(SepaSamples::HOLDER, AccountHolder::fromString(SepaSamples::HOLDER)->value());
        static::assertSame(' a ', AccountHolder::fromString(' a ')->value());
    }

    public function testLengthBounds(): void
    {
        static::assertSame(80, mb_strlen(AccountHolder::fromString(str_repeat('é', 80))->value()));
        $exception = DomainAssertions::assertViolationCode('account_holder.too_long', static fn(): AccountHolder => AccountHolder::fromString(str_repeat('é', 81)));
        static::assertSame(['max_length' => 80], $exception->params());
    }

    public function testBlankIsRefused(): void
    {
        static::assertSame('account_holder.empty', DomainAssertions::assertViolationCode('account_holder.empty', static fn(): AccountHolder => AccountHolder::fromString(''))->violationCode());
        static::assertSame('account_holder.empty', DomainAssertions::assertViolationCode('account_holder.empty', static fn(): AccountHolder => AccountHolder::fromString(' '))->violationCode());
    }

    public function testEquality(): void
    {
        static::assertTrue(AccountHolder::fromString('A')->equals(AccountHolder::fromString('A')));
        static::assertFalse(AccountHolder::fromString('A')->equals(AccountHolder::fromString('B')));
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $holder = AccountHolder::fromString(SepaSamples::HOLDER);

        static::assertSame(['value' => '***'], $holder->__debugInfo());
        static::assertStringNotContainsString('LEFORESTIER', print_r($holder, true));
        static::assertNotContains('__toString', get_class_methods($holder));
    }

    public function testTheValueIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(AccountHolder::class, 'fromString', ['value']);
        DomainAssertions::assertSensitiveParameters(AccountHolder::class, '__construct', ['value']);
    }
}
