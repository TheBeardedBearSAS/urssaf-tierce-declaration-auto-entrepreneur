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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateTimeline;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

final class MandateTimelineTest extends TestCase
{
    public function testNotified(): void
    {
        $at = Instant::fromIso8601('2026-11-15T10:00:00Z');

        $timeline = MandateTimeline::notified($at);

        static::assertSame($at, $timeline->notifiedAt());
        static::assertNull($timeline->cancelledAt());
        static::assertSame($at, $timeline->updatedAt());
    }

    public function testWithCancellation(): void
    {
        $notified = Instant::fromIso8601('2026-11-15T10:00:00Z');
        $cancelled = Instant::fromIso8601('2026-11-16T10:00:00Z');

        $timeline = MandateTimeline::notified($notified)->withCancellation($cancelled);

        static::assertSame($notified, $timeline->notifiedAt());
        static::assertSame($cancelled, $timeline->cancelledAt());
        static::assertSame($cancelled, $timeline->updatedAt());
    }

    public function testEquality(): void
    {
        $notified = Instant::fromIso8601('2026-11-15T10:00:00Z');
        $cancelled = Instant::fromIso8601('2026-11-16T10:00:00Z');
        $timeline = MandateTimeline::notified($notified)->withCancellation($cancelled);

        static::assertTrue($timeline->equals(MandateTimeline::notified($notified)->withCancellation($cancelled)));
        static::assertFalse($timeline->equals(MandateTimeline::notified($notified)));
        static::assertFalse(MandateTimeline::notified($notified)->equals($timeline));
        static::assertFalse(MandateTimeline::notified($notified)->equals(MandateTimeline::notified($cancelled)));
        static::assertFalse($timeline->equals(MandateTimeline::notified($notified)->withCancellation(Instant::fromIso8601('2026-11-17T10:00:00Z'))));
        static::assertFalse($timeline->equals(MandateTimeline::notified(Instant::fromIso8601('2026-11-14T10:00:00Z'))->withCancellation($cancelled)));
    }
}
