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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Query string parameters of the SEPA mandate revocation (071). The SIRET and the RUM go into the path
 * ({@see ApiPaths::revokeSepaMandate()}); only the optional effective date is a parameter, and only when given.
 */
final class SepaRevocationQuery
{
    /**
     * @return array{dateEffet?: string}
     */
    public static function of(SepaRevocation $revocation): array
    {
        $effectiveDate = $revocation->effectiveDate();

        if (!$effectiveDate instanceof CalendarDate) {
            return [];
        }

        return ['dateEffet' => DateEncoding::encode($effectiveDate)];
    }
}
