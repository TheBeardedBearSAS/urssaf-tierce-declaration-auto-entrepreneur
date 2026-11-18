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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;

/**
 * Helpers on the {@see DeclarationPeriod} sum type: the single place where a new period kind must be handled (ADR 0004).
 */
final class DeclarationPeriods
{
    public const MIN_YEAR = 1;
    public const MAX_YEAR = 9999;

    /**
     * Rebuilds a period from its persisted parts (periodicity, year, month or quarter number).
     *
     * @throws InvalidValue "period.year_out_of_range", "period.month_out_of_range" or "period.quarter_out_of_range"
     */
    public static function fromParts(Periodicity $periodicity, int $year, int $number): DeclarationPeriod
    {
        return match ($periodicity) {
            Periodicity::Monthly => MonthlyPeriod::of($year, $number),
            Periodicity::Quarterly => QuarterlyPeriod::of($year, $number),
        };
    }

    /**
     * The month (1 to 12) or the quarter (1 to 4) of the period.
     *
     * @throws InvariantViolation "declaration_period.unsupported" for a period kind this version does not know
     */
    public static function numberOf(DeclarationPeriod $period): int
    {
        return match (true) {
            $period instanceof MonthlyPeriod => $period->month(),
            $period instanceof QuarterlyPeriod => $period->quarter(),
            default => throw InvariantViolation::because(
                'declaration_period.unsupported',
                'The declaration period kind is not supported.',
            ),
        };
    }
}
