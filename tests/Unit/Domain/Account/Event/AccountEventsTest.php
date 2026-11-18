<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Account\Event;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

final class AccountEventsTest extends TestCase
{
    public function testAccountSynced(): void
    {
        $event = new AccountSynced(
            AccountId::fromString('account-1'),
            TenantId::fromString('tenant-1'),
            false,
            true,
            Periodicity::Monthly,
            Periodicity::Quarterly,
            Instant::fromIso8601('2026-11-15T10:00:00Z'),
        );

        static::assertSame('account-1', $event->accountId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertFalse($event->previousMandatePresent());
        static::assertTrue($event->mandatePresent());
        static::assertSame(Periodicity::Monthly, $event->previousPeriodicity());
        static::assertSame(Periodicity::Quarterly, $event->periodicity());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testAccountForgotten(): void
    {
        $event = new AccountForgotten(
            AccountId::fromString('account-1'),
            TenantId::fromString('tenant-1'),
            [MandateId::fromString('mandate-1')],
            [DeclarationRecordId::fromString('declaration-1')],
            [PaymentRecordId::fromString('payment-1')],
            Instant::fromIso8601('2026-11-15T10:00:00Z'),
        );

        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('mandate-1', $event->mandateIds()[0]->value());
        static::assertSame('declaration-1', $event->declarationRecordIds()[0]->value());
        static::assertSame('payment-1', $event->paymentRecordIds()[0]->value());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testAccountForgottenWithoutATrackedAccount(): void
    {
        $event = new AccountForgotten(null, TenantId::fromString('tenant-1'), [], [], [], Instant::fromIso8601('2026-11-15T10:00:00Z'));

        static::assertNull($event->accountId());
        static::assertSame([], $event->mandateIds());
        static::assertSame([], $event->declarationRecordIds());
        static::assertSame([], $event->paymentRecordIds());
    }
}
