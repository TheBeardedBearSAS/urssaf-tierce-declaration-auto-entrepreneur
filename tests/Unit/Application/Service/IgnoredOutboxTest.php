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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRevoked;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

final class IgnoredOutboxTest extends TestCase
{
    public function testTheDefaultOutboxRecordsNothing(): void
    {
        $outbox = new IgnoredOutbox();
        $event = new SepaMandateRevoked('event-1', '2026-11-15T10:00:00Z', 'tenant-1', null, null, 'RUM-1');
        $entry = new OutboxEntry(1, $event, 0);
        $at = Instant::fromIso8601('2026-11-15T10:00:00Z');

        $outbox->append($event);
        $outbox->markDispatched($entry, $at);
        $outbox->markFailed($entry, 'error');

        static::assertSame([], $outbox->pending(10));
        static::assertSame(0, $outbox->purgeDispatchedBefore($at));
    }
}
