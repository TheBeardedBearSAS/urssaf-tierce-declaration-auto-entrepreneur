<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period;

/**
 * Period of a turnover declaration or of a payment: a month or a quarter of a year (ADR 0004).
 *
 * Sum type: callers match on {@see MonthlyPeriod} and {@see QuarterlyPeriod}.
 */
interface DeclarationPeriod
{
    public function year(): int;

    public function periodicity(): Periodicity;

    public function equals(self $other): bool;

    /**
     * Stable text form: "2019-12" for a month, "2019-Q3" for a quarter.
     */
    public function toString(): string;
}
