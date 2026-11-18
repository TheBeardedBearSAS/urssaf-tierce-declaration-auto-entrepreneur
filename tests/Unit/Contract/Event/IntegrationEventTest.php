<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Contract\Event;

use PHPUnit\Framework\TestCase;

final class IntegrationEventTest extends TestCase
{
    public function testAnEventExposesItsTypeVersionAndProperties(): void
    {
        $event = new SampleIntegrationEvent('event-1', '2026-11-15T10:00:00Z', 'tenant-1', 'local-1', 'invoice-42', 'declared');

        static::assertSame('urssaf.tdae.sample', $event->type());
        static::assertSame(2, $event->version());
        static::assertSame([
            'eventId' => 'event-1',
            'occurredAt' => '2026-11-15T10:00:00Z',
            'tenantId' => 'tenant-1',
            'aggregateId' => 'local-1',
            'externalReference' => 'invoice-42',
            'state' => 'declared',
        ], $event->toArray());
    }

    public function testTheAggregateAndTheExternalReferenceMayBeNull(): void
    {
        $event = new SampleIntegrationEvent('event-1', '2026-11-15T10:00:00Z', 'tenant-1', null, null, 'declared');

        static::assertNull($event->aggregateId);
        static::assertNull($event->externalReference);
        static::assertSame(
            [
                'eventId' => 'event-1',
                'occurredAt' => '2026-11-15T10:00:00Z',
                'tenantId' => 'tenant-1',
                'aggregateId' => null,
                'externalReference' => null,
                'state' => 'declared',
            ],
            $event->toArray(),
        );
    }
}
