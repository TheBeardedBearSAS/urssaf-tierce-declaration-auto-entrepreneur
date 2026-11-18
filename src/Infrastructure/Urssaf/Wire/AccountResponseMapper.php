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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the answer of 020 (ADR 0006). `mandatPresent` and `periodicite` are required; `date_etat` and `exoAccrePresent`
 * exist in the PDF only and stay optional.
 */
final class AccountResponseMapper
{
    /**
     * @throws UnexpectedUrssafResponse when the body is not a JSON object or a field is missing or malformed
     */
    public static function map(Payload $payload): AccountStatus
    {
        $payload->requireJsonObject();

        $mandatePresent = $payload->requireBool('mandatPresent');
        $periodicity = Periodicity::fromCode($payload->requireString('periodicite'))
            ?? throw UnexpectedUrssafResponse::invalidPayload('field "periodicite" is not M or T');

        return AccountStatus::of(
            $mandatePresent,
            $periodicity,
            self::affiliationDate($payload),
            $payload->optionalBool('exoAccrePresent'),
        );
    }

    private static function affiliationDate(Payload $payload): ?CalendarDate
    {
        $date = $payload->optionalString('date_etat');

        return $date === null ? null : DateEncoding::decodeFrenchDate($date, 'date_etat');
    }
}
