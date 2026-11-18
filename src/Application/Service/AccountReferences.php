<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;

/**
 * Builds the reference methods 030 and 031 need (SIRET and birth date): the birth date of the command, else the one
 * stored on the tracked account (ADR 0009 amendment).
 */
final class AccountReferences
{
    public const BIRTH_DATE_REQUIRED = 'account_reference.birth_date_required';

    /**
     * @throws ValidationFailed BIRTH_DATE_REQUIRED at the path "birthDate": the call is refused before sending
     */
    public static function resolve(Siret $siret, #[SensitiveParameter] ?CalendarDate $given, ?AeAccount $account): AccountReference
    {
        $birthDate = $given ?? $account?->birthDate();
        if (!$birthDate instanceof CalendarDate) {
            throw ValidationFailed::withResult(ValidationResult::of(Violation::error(
                'birthDate',
                self::BIRTH_DATE_REQUIRED,
                'Methods 030 and 031 need the birth date: give it, or track the account with it.',
            )));
        }

        return AccountReference::of($siret, $birthDate);
    }
}
