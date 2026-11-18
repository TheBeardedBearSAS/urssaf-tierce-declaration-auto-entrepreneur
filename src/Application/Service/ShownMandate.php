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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The mandate an account view shows: the active one, else the most recent one (a cancelled mandate), else none. A mandate
 * may predate the account, so it is read by SIRET.
 */
final class ShownMandate
{
    public static function of(MandateRepository $mandates, TenantId $tenantId, Siret $siret): ?Mandate
    {
        $active = $mandates->findActive($tenantId, $siret);
        if ($active instanceof Mandate) {
            return $active;
        }
        $known = $mandates->findBySiret($tenantId, $siret);

        return $known === [] ? null : $known[\count($known) - 1];
    }
}
