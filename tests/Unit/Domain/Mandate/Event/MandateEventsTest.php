<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Mandate\Event;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateCancellationOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Mandate\MandateSamples;

final class MandateEventsTest extends TestCase
{
    public function testMandateNotifiedCarriesIdentifiersOnly(): void
    {
        $event = new MandateNotified(
            MandateSamples::id(),
            MandateSamples::tenantId(),
            MandateSamples::accountId(),
            MandateOrigin::Imported,
            Instant::fromIso8601('2026-11-15T10:00:00Z'),
        );

        static::assertSame('mandate-1', $event->mandateId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame(MandateOrigin::Imported, $event->origin());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMandateCancelledAcceptsAnUntrackedAccount(): void
    {
        $event = new MandateCancelled(
            MandateSamples::id(),
            MandateSamples::tenantId(),
            null,
            MandateCancellationOrigin::Recorded,
            Instant::fromIso8601('2026-11-16T10:00:00Z'),
        );

        static::assertSame('mandate-1', $event->mandateId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertNull($event->accountId());
        static::assertSame(MandateCancellationOrigin::Recorded, $event->origin());
        static::assertSame('2026-11-16T10:00:00+00:00', $event->occurredAt()->format('c'));
    }
}
