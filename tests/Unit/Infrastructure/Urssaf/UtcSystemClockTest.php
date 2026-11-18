<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UtcSystemClock;

final class UtcSystemClockTest extends TestCase
{
    public function testItReturnsTheCurrentInstantInUtc(): void
    {
        $before = time();
        $now = (new UtcSystemClock())->now();

        static::assertSame('UTC', $now->getTimezone()->getName());
        static::assertGreaterThanOrEqual($before, $now->getTimestamp());
        static::assertLessThanOrEqual(time() + 1, $now->getTimestamp());
    }
}
