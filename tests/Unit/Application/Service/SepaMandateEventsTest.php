<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IntegrationEventFacts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\SepaMandateEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRevoked;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

final class SepaMandateEventsTest extends TestCase
{
    public function testARegisteredMandateHasNoAggregateAndNoReference(): void
    {
        $at = new DateTimeImmutable('2026-11-15T10:00:00Z');

        $event = SepaMandateEvents::registered(TenantId::fromString('tenant-1'), Rum::fromString('RUM-1'), $at);

        static::assertSame(
            [
                'eventId' => IntegrationEventFacts::id(SepaMandateRegistered::TYPE, 'tenant-1', null, $at, ['RUM-1']),
                'occurredAt' => '2026-11-15T10:00:00Z',
                'tenantId' => 'tenant-1',
                'aggregateId' => null,
                'externalReference' => null,
                'rum' => 'RUM-1',
            ],
            $event->toArray(),
        );
    }

    public function testARevokedMandateHasNoAggregateAndNoReference(): void
    {
        $at = new DateTimeImmutable('2026-11-15T10:00:00Z');

        $event = SepaMandateEvents::revoked(TenantId::fromString('tenant-1'), Rum::fromString('RUM-1'), $at);

        static::assertSame(
            [
                'eventId' => IntegrationEventFacts::id(SepaMandateRevoked::TYPE, 'tenant-1', null, $at, ['RUM-1']),
                'occurredAt' => '2026-11-15T10:00:00Z',
                'tenantId' => 'tenant-1',
                'aggregateId' => null,
                'externalReference' => null,
                'rum' => 'RUM-1',
            ],
            $event->toArray(),
        );
    }

    public function testTheSameRumUnderTwoTenantsGivesTwoIds(): void
    {
        $at = new DateTimeImmutable('2026-11-15T10:00:00Z');
        $rum = Rum::fromString('RUM-1');

        $first = SepaMandateEvents::registered(TenantId::fromString('tenant-1'), $rum, $at);
        $second = SepaMandateEvents::registered(TenantId::fromString('tenant-2'), $rum, $at);

        static::assertNotSame($first->eventId, $second->eventId);
        static::assertSame($first->eventId, SepaMandateEvents::registered(TenantId::fromString('tenant-1'), $rum, $at)->eventId);
        static::assertNotSame($first->eventId, SepaMandateEvents::revoked(TenantId::fromString('tenant-1'), $rum, $at)->eventId);
    }
}
