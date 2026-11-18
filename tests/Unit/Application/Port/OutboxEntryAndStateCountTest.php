<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Port;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

final class OutboxEntryAndStateCountTest extends TestCase
{
    public function testAnOutboxEntryKeepsItsSequenceEventAndAttempts(): void
    {
        $event = new SepaMandateRegistered('event-1', '2026-11-15T10:00:00Z', 'tenant-1', null, null, 'RUM-1');
        $entry = new OutboxEntry(7, $event, 2);

        static::assertSame(7, $entry->sequence);
        static::assertSame($event, $entry->event);
        static::assertSame(2, $entry->attempts);
    }

    public function testAStateCountKeepsItsTenantStateAndCount(): void
    {
        $tenant = TenantId::fromString('tenant-1');
        $count = new StateCount($tenant, 'pending', 3);

        static::assertSame($tenant, $count->tenantId);
        static::assertSame('pending', $count->state);
        static::assertSame(3, $count->count);
    }
}
