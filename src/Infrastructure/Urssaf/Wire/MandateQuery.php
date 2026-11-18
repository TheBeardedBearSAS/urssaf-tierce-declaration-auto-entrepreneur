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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;

/**
 * Query string parameters of the mandate operations 030 and 031: the SIRET and the birth date of the entrepreneur
 * (ADR 0008), the date as a date-only `Y-m-d` string (ADR 0001). Both are personal data: never logged (ADR 0010).
 */
final class MandateQuery
{
    /**
     * @return array{siret: string, dateNaissance: string}
     */
    public static function of(#[SensitiveParameter] AccountReference $account): array
    {
        return [
            'siret' => $account->siret()->toString(),
            'dateNaissance' => DateEncoding::encode($account->birthDate()),
        ];
    }
}
