<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Mandate;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Mandates in every state, events released.
 */
final class MandateSamples
{
    public static function id(): MandateId
    {
        return MandateId::fromString('mandate-1');
    }

    public static function tenantId(): TenantId
    {
        return TenantId::fromString('tenant-1');
    }

    public static function siret(): Siret
    {
        return Siret::fromString('12345678901234');
    }

    public static function accountId(): AccountId
    {
        return AccountId::fromString('account-1');
    }

    public static function notifiedAt(): Instant
    {
        return Instant::fromIso8601('2026-11-15T10:00:00Z');
    }

    public static function later(): Instant
    {
        return Instant::fromIso8601('2026-11-16T11:30:00Z');
    }

    public static function notified(): Mandate
    {
        $mandate = Mandate::notify(self::id(), self::tenantId(), self::siret(), self::accountId(), self::notifiedAt());
        $mandate->releaseEvents();

        return $mandate;
    }

    public static function inState(MandateState $state): Mandate
    {
        $mandate = self::notified();
        if ($state === MandateState::Cancelled) {
            $mandate->cancel(Instant::fromIso8601('2026-11-15T12:00:00Z'));
        }
        $mandate->releaseEvents();

        return $mandate;
    }
}
