<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Persistence\InMemory;

use DateInterval;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\PaymentSamples;

/**
 * Fictitious aggregates (events released) in the states the repository tests need. Every instant is a number of
 * minutes after 2026-11-15T10:00:00Z, so that the ordering of the queries is explicit.
 *
 * @mago-expect lint:too-many-methods One builder per aggregate and state.
 */
final class Records
{
    public static function tenant(string $value = 'tenant-1'): TenantId
    {
        return TenantId::fromString($value);
    }

    public static function siret(int $n = 1): Siret
    {
        return Siret::fromString(\sprintf('%014d', 12_345_678_901_230 + $n));
    }

    public static function at(int $minutes = 0): Instant
    {
        return Instant::fromDateTime(Instant::fromIso8601('2026-11-15T10:00:00Z')->toDateTimeImmutable()->add(
            new DateInterval('PT' . $minutes . 'M'),
        ));
    }

    public static function period(int $month = 10): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, $month);
    }

    public static function account(string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): AeAccount
    {
        $account = AeAccount::track(
            AccountId::fromString($id),
            self::tenant($slot->tenant),
            self::siret($slot->siret),
            AccountStatus::of(false, Periodicity::Monthly, CalendarDate::fromString('2019-12-01'), false),
            null,
            self::at($slot->minutes),
        );
        $account->releaseEvents();

        return $account;
    }

    public static function mandate(string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): Mandate
    {
        $mandate = Mandate::notify(
            MandateId::fromString($id),
            self::tenant($slot->tenant),
            self::siret($slot->siret),
            null,
            self::at($slot->minutes),
        );
        $mandate->releaseEvents();

        return $mandate;
    }

    public static function cancelledMandate(string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): Mandate
    {
        $mandate = self::mandate($id, $slot);
        $mandate->cancel(self::at($slot->minutes + 1));
        $mandate->releaseEvents();

        return $mandate;
    }

    public static function declaration(string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): DeclarationRecord
    {
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            self::tenant($slot->tenant),
            self::siret($slot->siret),
            self::period($slot->month),
            self::declarationFingerprint($slot->siret, $slot->tenant, $slot->month),
            null,
            self::at($slot->minutes),
        );
        $record->releaseEvents();

        return $record;
    }

    /**
     * A record of the state: submitting from minute + 1 (last attempt), then settled at minute + 2.
     */
    public static function declarationIn(DeclarationState $state, string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): DeclarationRecord
    {
        $record = self::declaration($id, $slot);
        if ($state === DeclarationState::Pending) {
            return $record;
        }
        $record->startSubmission(self::at($slot->minutes + 1));
        if ($state === DeclarationState::Declared) {
            $record->markDeclared(DeclarationSamples::result(), self::at($slot->minutes + 2));
        }
        if ($state === DeclarationState::Rejected) {
            $record->markRejected(DeclarationSamples::errors(), self::at($slot->minutes + 2));
        }
        $record->releaseEvents();

        return $record;
    }

    public static function declarationFingerprint(int $siret, string $tenant, int $month): DeclarationFingerprint
    {
        return DeclarationFingerprint::of(
            self::tenant($tenant),
            self::siret($siret),
            self::period($month),
            DeclarationSamples::turnover(),
        );
    }

    /**
     * The fingerprint of a re-entry with another content.
     */
    public static function otherDeclarationFingerprint(int $siret, string $tenant, int $month): DeclarationFingerprint
    {
        return DeclarationFingerprint::of(
            self::tenant($tenant),
            self::siret($siret),
            self::period($month),
            DeclarationSamples::otherTurnover(),
        );
    }

    public static function payment(string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): PaymentRecord
    {
        $record = PaymentRecord::record(
            PaymentRecordId::fromString($id),
            self::tenant($slot->tenant),
            PaymentReference::fromString($slot->reference ?? 'ref-' . $id),
            self::siret($slot->siret),
            self::order($slot->month, 1500),
            null,
            self::at($slot->minutes),
        );
        $record->releaseEvents();

        return $record;
    }

    public static function paymentIn(PaymentState $state, string $id, Slot $slot = new Slot(1, 'tenant-1', 10, 0, null)): PaymentRecord
    {
        $record = self::payment($id, $slot);
        if ($state === PaymentState::Pending) {
            return $record;
        }
        $record->startSubmission(self::at($slot->minutes + 1));
        if ($state === PaymentState::Paid) {
            $record->markPaid(PaymentSamples::result(), self::at($slot->minutes + 2));
        }
        if ($state === PaymentState::Rejected) {
            $record->markRejected(PaymentSamples::errors(), self::at($slot->minutes + 2));
        }
        $record->releaseEvents();

        return $record;
    }

    public static function order(int $month, int $amount): PaymentOrder
    {
        return PaymentOrder::of(self::period($month), Euros::of($amount), null, null);
    }
}
