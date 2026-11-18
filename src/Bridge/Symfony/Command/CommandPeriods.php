<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command;

use Symfony\Component\Console\Exception\InvalidOptionException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Input\DeclarationPeriodFactory;

/**
 * The --period option: "YYYY-MM" for a monthly period, "YYYY-Tn" for a quarterly one. The ranges (year, month 1-12,
 * quarter 1-4) are checked by the period factory. The year and the month are cast: a leading zero ("0999", "08") is no valid
 * integer for the factory, which reads a one digit quarter as is.
 */
final class CommandPeriods
{
    private const MONTHLY = '/^(?<year>[0-9]{4})-(?<month>[0-9]{2})$/D';
    private const QUARTERLY = '/^(?<year>[0-9]{4})-T(?<quarter>[0-9])$/D';

    /**
     * @throws InvalidOptionException for a value that is neither of the two forms
     * @throws ValidationFailed       for a year, a month or a quarter out of range
     */
    public static function fromOption(string $value): DeclarationPeriod
    {
        $factory = new DeclarationPeriodFactory();
        $match = [];
        if (preg_match(self::MONTHLY, $value, $match) === 1) {
            return $factory->fromInput(['year' => (int) $match['year'], 'month' => (int) $match['month']]);
        }
        if (preg_match(self::QUARTERLY, $value, $match) === 1) {
            return $factory->fromInput(['year' => (int) $match['year'], 'quarter' => $match['quarter']]);
        }

        throw new InvalidOptionException('The --period option must be YYYY-MM (monthly) or YYYY-Tn (quarterly, n from 1 to 4).');
    }
}
