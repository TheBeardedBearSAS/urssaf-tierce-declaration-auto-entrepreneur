<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;

/**
 * Clock port backed by any PSR-20 clock (the framework's system clock in production).
 */
final readonly class SystemClock implements Clock
{
    public function __construct(private ClockInterface $clock) {}

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
