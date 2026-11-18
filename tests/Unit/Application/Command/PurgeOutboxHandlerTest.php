<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Command;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;

final class PurgeOutboxHandlerTest extends TestCase
{
    public function testTheEntriesDispatchedBeforeTheRetentionAreDeleted(): void
    {
        $outbox = self::dispatchedOutbox();

        $deleted = self::handler($outbox, new WorkflowPolicy())(new PurgeOutbox(new DateInterval('P2D')));

        static::assertSame(2, $deleted);
        static::assertSame(1, self::handler($outbox, new WorkflowPolicy())(new PurgeOutbox(new DateInterval('PT1H'))));
    }

    public function testThePolicyRetentionIsTheDefault(): void
    {
        $outbox = self::dispatchedOutbox();
        $policy = (new WorkflowPolicy())->withOutboxRetention(new DateInterval('P2D'));

        static::assertSame(2, self::handler($outbox, $policy)(new PurgeOutbox(null)));
    }

    public function testAnEntryDispatchedExactlyAtTheLimitIsKept(): void
    {
        $outbox = self::dispatchedOutbox();

        static::assertSame(1, self::handler($outbox, new WorkflowPolicy())(new PurgeOutbox(new DateInterval('P3D'))));
    }

    /**
     * Three dispatched entries, at 5, 3 and 1 days before 2026-11-17T09:00:00Z.
     */
    private static function dispatchedOutbox(): InMemoryOutbox
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(
            new SepaMandateRegistered('e1', '2026-11-12T09:00:00Z', 'tenant-1', null, null, 'RUM-1'),
            new SepaMandateRegistered('e2', '2026-11-14T09:00:00Z', 'tenant-1', null, null, 'RUM-2'),
            new SepaMandateRegistered('e3', '2026-11-16T09:00:00Z', 'tenant-1', null, null, 'RUM-3'),
        );
        $at = ['2026-11-12T09:00:00Z', '2026-11-14T09:00:00Z', '2026-11-16T09:00:00Z'];
        foreach ($outbox->pending(10) as $index => $entry) {
            $outbox->markDispatched($entry, Instant::fromIso8601($at[$index]));
        }

        return $outbox;
    }

    private static function handler(InMemoryOutbox $outbox, WorkflowPolicy $policy): PurgeOutboxHandler
    {
        return new PurgeOutboxHandler($outbox, new FixedClock('2026-11-17T09:00:00Z'), $policy);
    }
}
