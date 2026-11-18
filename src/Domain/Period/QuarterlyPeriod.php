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

/**
 * A calendar quarter of a year (quarterly periodicity).
 */
final readonly class QuarterlyPeriod implements DeclarationPeriod
{
    public const MIN_QUARTER = 1;
    public const MAX_QUARTER = 4;

    private function __construct(private int $year, private int $quarter) {}

    /**
     * @throws InvalidValue "period.year_out_of_range" (checked first) or "period.quarter_out_of_range"
     */
    public static function of(int $year, int $quarter): self
    {
        if ($year < DeclarationPeriods::MIN_YEAR || $year > DeclarationPeriods::MAX_YEAR) {
            throw InvalidValue::because(
                'period.year_out_of_range',
                \sprintf('Invalid period: the year must be between %d and %d.', DeclarationPeriods::MIN_YEAR, DeclarationPeriods::MAX_YEAR),
                ['min' => DeclarationPeriods::MIN_YEAR, 'max' => DeclarationPeriods::MAX_YEAR],
            );
        }
        if ($quarter < self::MIN_QUARTER || $quarter > self::MAX_QUARTER) {
            throw InvalidValue::because(
                'period.quarter_out_of_range',
                \sprintf('Invalid period: the quarter must be between %d and %d.', self::MIN_QUARTER, self::MAX_QUARTER),
                ['min' => self::MIN_QUARTER, 'max' => self::MAX_QUARTER],
            );
        }

        return new self($year, $quarter);
    }

    public function year(): int
    {
        return $this->year;
    }

    public function quarter(): int
    {
        return $this->quarter;
    }

    public function periodicity(): Periodicity
    {
        return Periodicity::Quarterly;
    }

    public function equals(DeclarationPeriod $other): bool
    {
        return $other instanceof self && $this->year === $other->year && $this->quarter === $other->quarter;
    }

    public function toString(): string
    {
        return \sprintf('%04d-Q%d', $this->year, $this->quarter);
    }
}
