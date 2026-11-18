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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Fictitious values of the official documentation examples (api-reference §2).
 */
final class AccountSamples
{
    public static function instant(string $iso): Instant
    {
        return Instant::fromIso8601($iso);
    }

    public static function tenantId(): TenantId
    {
        return TenantId::fromString('tenant-1');
    }

    public static function accountId(): AccountId
    {
        return AccountId::fromString('account-1');
    }

    public static function siret(): Siret
    {
        return Siret::fromString('12345678901234');
    }

    public static function birthDate(): CalendarDate
    {
        return CalendarDate::fromString('1975-10-01');
    }

    public static function status(bool $mandatePresent, Periodicity $periodicity): AccountStatus
    {
        return AccountStatus::of($mandatePresent, $periodicity, CalendarDate::fromString('2019-12-01'), false);
    }

    public static function account(?CalendarDate $birthDate): AeAccount
    {
        return AeAccount::track(
            self::accountId(),
            self::tenantId(),
            self::siret(),
            self::status(false, Periodicity::Monthly),
            $birthDate,
            self::instant('2026-11-15T10:00:00Z'),
        );
    }
}
