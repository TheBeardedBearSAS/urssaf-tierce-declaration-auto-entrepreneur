<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Dates of the URSSAF wire contract (ADR 0001): requests and the 040 / 050 / 060 responses use date-only `YYYY-MM-DD`
 * strings, never a time part, `Z` or offset; `date_etat` of 020 is the only `DD/MM/YYYY` date (PDF only).
 */
final class DateEncoding
{
    private const FRENCH_DATE = '/^(\d{2})\/(\d{2})\/(\d{4})$/D';

    public static function encode(CalendarDate $date): string
    {
        return $date->toString();
    }

    /**
     * @throws UnexpectedUrssafResponse when the value is not a strict `Y-m-d` calendar date (names the field only)
     */
    public static function decodeIsoDate(#[SensitiveParameter] string $value, string $field): CalendarDate
    {
        try {
            return CalendarDate::fromString($value);
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy(\sprintf('field "%s" is not a Y-m-d date', $field), $exception);
        }
    }

    /**
     * @throws UnexpectedUrssafResponse when the value is not a `DD/MM/YYYY` calendar date (names the field only)
     */
    public static function decodeFrenchDate(#[SensitiveParameter] string $value, string $field): CalendarDate
    {
        $parts = [];
        if (preg_match(self::FRENCH_DATE, $value, $parts) !== 1) {
            throw UnexpectedUrssafResponse::invalidPayload(\sprintf('field "%s" is not a DD/MM/YYYY date', $field));
        }

        try {
            return CalendarDate::fromString($parts[3] . '-' . $parts[2] . '-' . $parts[1]);
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy(
                \sprintf('field "%s" is not a DD/MM/YYYY date', $field),
                $exception,
            );
        }
    }
}
