<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Input;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\RawFields;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;

/**
 * Turns the raw period of a host input into a {@see DeclarationPeriod}, reporting every violation at once
 * (notification pattern, ADR 0000 item 4).
 *
 * Input keys: "year" (integer, required), and exactly one of "month" (monthly period) or "quarter" (quarterly period).
 *
 * The range of the year and of the month or quarter are checked through stand-in periods (the first valid year, the
 * first valid month), so that an out-of-range year
 * does not hide an out-of-range month. The real period is built only without any violation.
 */
final readonly class DeclarationPeriodFactory
{
    /**
     * @param array<array-key, mixed> $input
     */
    public function validate(#[SensitiveParameter] array $input): ValidationResult
    {
        return RawFields::validate($input, fn(RawFields $fields): ?DeclarationPeriod => $this->parse($fields));
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @throws ValidationFailed with every violation
     */
    public function fromInput(#[SensitiveParameter] array $input): DeclarationPeriod
    {
        return RawFields::resolve($input, fn(RawFields $fields): ?DeclarationPeriod => $this->parse($fields));
    }

    /**
     * Reads the period out of the fields of a map; also used to read the nested "period" of the other factories.
     */
    public function parse(RawFields $fields): ?DeclarationPeriod
    {
        $errorsBefore = $fields->errorCount();
        $year = $fields->requiredInteger('year');
        $errorsBeforeNumber = $fields->errorCount();
        $month = $fields->integer('month');
        $quarter = $fields->integer('quarter');

        if ($month !== null && $quarter !== null) {
            $fields->add(Violation::error(
                '',
                'period.month_and_quarter',
                'A period has either a month or a quarter, not both.',
            ));
        }
        if ($month === null && $quarter === null && $fields->errorCount() === $errorsBeforeNumber) {
            $fields->add(Violation::error(
                '',
                'period.month_or_quarter_required',
                'A period needs a month or a quarter.',
            ));
        }
        if ($year !== null) {
            $fields->build('year', static fn(): MonthlyPeriod => MonthlyPeriod::of($year, MonthlyPeriod::MIN_MONTH));
        }
        if ($month !== null) {
            $fields->build('month', static fn(): MonthlyPeriod => MonthlyPeriod::of(DeclarationPeriods::MIN_YEAR, $month));
        }
        if ($quarter !== null) {
            $fields->build('quarter', static fn(): QuarterlyPeriod => QuarterlyPeriod::of(DeclarationPeriods::MIN_YEAR, $quarter));
        }
        if ($year === null || $fields->errorCount() > $errorsBefore) {
            return null;
        }
        if ($month !== null) {
            return MonthlyPeriod::of($year, $month);
        }

        return $quarter === null ? null : QuarterlyPeriod::of($year, $quarter);
    }
}
