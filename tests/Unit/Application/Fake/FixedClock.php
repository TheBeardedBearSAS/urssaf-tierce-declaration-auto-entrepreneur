<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use DateTimeImmutable;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;

/**
 * Clock stopped at a given instant, movable by the test.
 */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new DateTimeImmutable($now);
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function moveTo(string $now): void
    {
        $this->now = new DateTimeImmutable($now);
    }
}
