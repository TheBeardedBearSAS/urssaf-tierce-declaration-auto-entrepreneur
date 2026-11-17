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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

final class AccountStatusTest extends TestCase
{
    private static function base(): AccountStatus
    {
        return AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), true);
    }

    public function testAccessors(): void
    {
        $status = self::base();

        static::assertTrue($status->mandatePresent());
        static::assertSame(Periodicity::Quarterly, $status->periodicity());
        static::assertSame('2019-12-01', $status->affiliationDate()?->toString());
        static::assertTrue($status->acreExemption());
    }

    public function testOptionalFieldsCanBeAbsent(): void
    {
        $status = AccountStatus::of(false, Periodicity::Monthly, null, null);

        static::assertFalse($status->mandatePresent());
        static::assertNull($status->affiliationDate());
        static::assertNull($status->acreExemption());
    }

    public function testEqualsComparesEveryField(): void
    {
        $status = self::base();

        static::assertTrue($status->equals(self::base()));
        static::assertFalse($status->equals(AccountStatus::of(false, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), true)));
        static::assertFalse($status->equals(AccountStatus::of(true, Periodicity::Monthly, CalendarDate::fromString('2019-12-01'), true)));
        static::assertFalse($status->equals(AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-02'), true)));
        static::assertFalse($status->equals(AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), false)));
        static::assertFalse($status->equals(AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), null)));
        static::assertFalse($status->equals(AccountStatus::of(true, Periodicity::Quarterly, null, true)));
    }

    public function testEqualsWithAbsentValues(): void
    {
        $absent = AccountStatus::of(true, Periodicity::Quarterly, null, null);

        static::assertTrue($absent->equals(AccountStatus::of(true, Periodicity::Quarterly, null, null)));
        static::assertFalse($absent->equals(self::base()));
        static::assertFalse($absent->equals(AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), null)));
    }

    public function testOnlyTheMandateAndThePeriodicityAreTracked(): void
    {
        $status = self::base();

        static::assertFalse($status->differsInTrackedFieldsFrom(self::base()));
        static::assertFalse($status->differsInTrackedFieldsFrom(AccountStatus::of(true, Periodicity::Quarterly, null, null)));
        static::assertTrue($status->differsInTrackedFieldsFrom(AccountStatus::of(false, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), true)));
        static::assertTrue($status->differsInTrackedFieldsFrom(AccountStatus::of(true, Periodicity::Monthly, CalendarDate::fromString('2019-12-01'), true)));
        static::assertTrue($status->differsInTrackedFieldsFrom(AccountStatus::of(false, Periodicity::Monthly, CalendarDate::fromString('2019-12-01'), true)));
    }
}
