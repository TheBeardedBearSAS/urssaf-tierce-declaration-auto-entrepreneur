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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * One method per command and scenario of the aggregate.
 *
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class AeAccountTest extends TestCase
{
    private const CREATED = '2026-11-15T10:00:00Z';
    private const LATER = '2026-11-16T11:30:00Z';

    public function testTrackWithABirthDate(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());

        static::assertSame('account-1', $account->id()->value());
        static::assertSame('tenant-1', $account->tenantId()->value());
        static::assertSame('12345678901234', $account->siret()->toString());
        static::assertSame('1975-10-01', $account->birthDate()?->toString());
        static::assertFalse($account->status()->mandatePresent());
        static::assertSame(Periodicity::Monthly, $account->periodicity());
        static::assertSame(self::CREATED, $account->createdAt()->toIso8601());
        static::assertSame(self::CREATED, $account->lastSyncedAt()->toIso8601());
        static::assertSame(self::CREATED, $account->updatedAt()->toIso8601());

        $reference = $account->reference();
        static::assertNotNull($reference);
        static::assertSame('12345678901234', $reference->siret()->toString());
        static::assertSame('1975-10-01', $reference->birthDate()->toString());
    }

    public function testTrackWithoutABirthDate(): void
    {
        $account = AccountSamples::account(null);

        static::assertNull($account->birthDate());
        static::assertNull($account->reference());
    }

    public function testPeriodicityComesFromTheStatus(): void
    {
        $account = AeAccount::track(
            AccountSamples::accountId(),
            AccountSamples::tenantId(),
            AccountSamples::siret(),
            AccountSamples::status(true, Periodicity::Quarterly),
            null,
            AccountSamples::instant(self::CREATED),
        );

        static::assertSame(Periodicity::Quarterly, $account->periodicity());
    }

    public function testTrackRecordsTheFirstSnapshotAsAnEvent(): void
    {
        $account = AeAccount::track(
            AccountSamples::accountId(),
            AccountSamples::tenantId(),
            AccountSamples::siret(),
            AccountSamples::status(true, Periodicity::Quarterly),
            AccountSamples::birthDate(),
            AccountSamples::instant(self::CREATED),
        );

        $events = $account->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(AccountSynced::class, $event);
        static::assertSame('account-1', $event->accountId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertNull($event->previousMandatePresent());
        static::assertTrue($event->mandatePresent());
        static::assertNull($event->previousPeriodicity());
        static::assertSame(Periodicity::Quarterly, $event->periodicity());
        static::assertSame(self::CREATED, $event->occurredAt()->format('Y-m-d\\TH:i:s\\Z'));
    }

    public function testReleaseEventsForgetsThem(): void
    {
        $account = AccountSamples::account(null);

        static::assertCount(1, $account->releaseEvents());
        static::assertSame([], $account->releaseEvents());
    }

    public function testSyncWithoutTrackedChangeOnlyRefreshesTheSnapshot(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());
        $account->releaseEvents();
        $status = AccountStatus::of(false, Periodicity::Monthly, CalendarDate::fromString('2020-01-01'), true);

        $account->sync($status, AccountSamples::instant(self::LATER));

        static::assertSame($status, $account->status());
        static::assertSame(self::CREATED, $account->createdAt()->toIso8601());
        static::assertSame(self::LATER, $account->lastSyncedAt()->toIso8601());
        static::assertSame(self::LATER, $account->updatedAt()->toIso8601());
        static::assertSame([], $account->releaseEvents());
    }

    public function testSyncRecordsAnEventWhenTheMandateAppears(): void
    {
        $account = AccountSamples::account(null);
        $account->releaseEvents();

        $account->sync(AccountSamples::status(true, Periodicity::Monthly), AccountSamples::instant(self::LATER));

        $events = $account->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(AccountSynced::class, $event);
        static::assertFalse($event->previousMandatePresent());
        static::assertTrue($event->mandatePresent());
        static::assertSame(Periodicity::Monthly, $event->previousPeriodicity());
        static::assertSame(Periodicity::Monthly, $event->periodicity());
        static::assertSame(self::LATER, $event->occurredAt()->format('Y-m-d\\TH:i:s\\Z'));
        static::assertSame('account-1', $event->accountId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
    }

    public function testReleaseEventsReturnsEveryRecordedEvent(): void
    {
        $account = AccountSamples::account(null);
        $account->releaseEvents();

        $account->sync(AccountSamples::status(true, Periodicity::Monthly), AccountSamples::instant(self::LATER));
        $account->sync(AccountSamples::status(false, Periodicity::Quarterly), AccountSamples::instant(self::LATER));

        static::assertCount(2, $account->releaseEvents());
        static::assertSame([], $account->releaseEvents());
    }

    public function testSyncRecordsAnEventWhenThePeriodicityChanges(): void
    {
        $account = AccountSamples::account(null);
        $account->releaseEvents();

        $account->sync(AccountSamples::status(false, Periodicity::Quarterly), AccountSamples::instant(self::LATER));

        $events = $account->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(AccountSynced::class, $event);
        static::assertFalse($event->previousMandatePresent());
        static::assertFalse($event->mandatePresent());
        static::assertSame(Periodicity::Monthly, $event->previousPeriodicity());
        static::assertSame(Periodicity::Quarterly, $event->periodicity());
        static::assertSame(Periodicity::Quarterly, $account->periodicity());
    }

    public function testSyncNeverTouchesTheBirthDate(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());

        $account->sync(AccountSamples::status(true, Periodicity::Quarterly), AccountSamples::instant(self::LATER));

        static::assertSame('1975-10-01', $account->birthDate()?->toString());
    }

    public function testRememberBirthDateStoresTheFirstOne(): void
    {
        $account = AccountSamples::account(null);

        $account->rememberBirthDate(AccountSamples::birthDate(), AccountSamples::instant(self::LATER));

        static::assertSame('1975-10-01', $account->birthDate()?->toString());
        static::assertSame(self::LATER, $account->updatedAt()->toIso8601());
        static::assertSame(self::CREATED, $account->lastSyncedAt()->toIso8601());
        static::assertNotNull($account->reference());
    }

    public function testRememberBirthDateReplacesADifferentOne(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());

        $account->rememberBirthDate(CalendarDate::fromString('1980-02-03'), AccountSamples::instant(self::LATER));

        static::assertSame('1980-02-03', $account->birthDate()?->toString());
        static::assertSame(self::LATER, $account->updatedAt()->toIso8601());
    }

    public function testRememberTheSameBirthDateChangesNothing(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());
        $account->releaseEvents();

        $account->rememberBirthDate(CalendarDate::fromString('1975-10-01'), AccountSamples::instant(self::LATER));

        static::assertSame('1975-10-01', $account->birthDate()?->toString());
        static::assertSame(self::CREATED, $account->updatedAt()->toIso8601());
        static::assertSame([], $account->releaseEvents());
    }

    public function testForgetRecordsTheErasedIdentifiers(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());
        $account->releaseEvents();

        $account->forget(
            AccountSamples::instant(self::LATER),
            [MandateId::fromString('mandate-1')],
            [DeclarationRecordId::fromString('declaration-1'), DeclarationRecordId::fromString('declaration-2')],
            [PaymentRecordId::fromString('payment-1')],
        );

        $events = $account->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(AccountForgotten::class, $event);
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(['mandate-1'], array_map(static fn(MandateId $id): string => $id->value(), $event->mandateIds()));
        static::assertSame(
            ['declaration-1', 'declaration-2'],
            array_map(static fn(DeclarationRecordId $id): string => $id->value(), $event->declarationRecordIds()),
        );
        static::assertSame(['payment-1'], array_map(static fn(PaymentRecordId $id): string => $id->value(), $event->paymentRecordIds()));
        static::assertSame(self::LATER, $event->occurredAt()->format('Y-m-d\\TH:i:s\\Z'));
        static::assertSame(self::CREATED, $account->updatedAt()->toIso8601());
        static::assertSame('12345678901234', $account->siret()->toString());
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $account = AccountSamples::account(AccountSamples::birthDate());

        static::assertSame(
            ['id' => 'account-1', 'tenantId' => 'tenant-1', 'siret' => '**********1234', 'birthDate' => '***'],
            $account->__debugInfo(),
        );
        static::assertNull(AccountSamples::account(null)->__debugInfo()['birthDate']);
    }

    public function testTheBirthDateIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(AeAccount::class, 'track', ['birthDate']);
        DomainAssertions::assertSensitiveParameters(AeAccount::class, 'rememberBirthDate', ['birthDate']);
        DomainAssertions::assertSensitiveParameters(AeAccount::class, '__construct', ['birthDate']);
        static::assertTrue((new ReflectionMethod(AeAccount::class, '__construct'))->isPrivate());
    }
}
