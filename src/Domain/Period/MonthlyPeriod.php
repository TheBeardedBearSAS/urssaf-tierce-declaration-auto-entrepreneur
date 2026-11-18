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
 * A calendar month of a year (monthly periodicity).
 */
final readonly class MonthlyPeriod implements DeclarationPeriod
{
    public const MIN_MONTH = 1;
    public const MAX_MONTH = 12;

    private function __construct(private int $year, private int $month) {}

    /**
     * @throws InvalidValue "period.year_out_of_range" (checked first) or "period.month_out_of_range"
     */
    public static function of(int $year, int $month): self
    {
        if ($year < DeclarationPeriods::MIN_YEAR || $year > DeclarationPeriods::MAX_YEAR) {
            throw InvalidValue::because(
                'period.year_out_of_range',
                \sprintf('Invalid period: the year must be between %d and %d.', DeclarationPeriods::MIN_YEAR, DeclarationPeriods::MAX_YEAR),
                ['min' => DeclarationPeriods::MIN_YEAR, 'max' => DeclarationPeriods::MAX_YEAR],
            );
        }
        if ($month < self::MIN_MONTH || $month > self::MAX_MONTH) {
            throw InvalidValue::because(
                'period.month_out_of_range',
                \sprintf('Invalid period: the month must be between %d and %d.', self::MIN_MONTH, self::MAX_MONTH),
                ['min' => self::MIN_MONTH, 'max' => self::MAX_MONTH],
            );
        }

        return new self($year, $month);
    }

    public function year(): int
    {
        return $this->year;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function periodicity(): Periodicity
    {
        return Periodicity::Monthly;
    }

    public function equals(DeclarationPeriod $other): bool
    {
        return $other instanceof self && $this->year === $other->year && $this->month === $other->month;
    }

    public function toString(): string
    {
        return \sprintf('%04d-%02d', $this->year, $this->month);
    }
}
