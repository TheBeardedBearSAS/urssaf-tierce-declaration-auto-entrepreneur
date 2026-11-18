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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;

/**
 * Query string parameters of the operations that identify an account by its SIRET: 020, 040, 050, 060 and 075
 * (ADR 0008). The SIRET is personal data: the query is never logged (ADR 0010).
 */
final class AccountQuery
{
    /**
     * @return array{siret: string}
     */
    public static function of(Siret $siret): array
    {
        return ['siret' => $siret->toString()];
    }
}
