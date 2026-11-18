<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Operation 020: finds the account of a SIRET (api-reference §2). A read: nothing is recorded, safe to retry.
 */
interface AccountReader
{
    /**
     * Finds the account (020).
     *
     * @throws UrssafRequestRejected      classified ({@see RejectionKind})
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable          retryable (transport error, 429, 5xx)
     * @throws RateLimitExceeded          the local limiter refused the call
     *
     * Any other {@see UrssafException} means URSSAF answered something the contract does not describe.
     */
    public function findAccount(TenantId $tenantId, Siret $siret): AccountStatus;
}
