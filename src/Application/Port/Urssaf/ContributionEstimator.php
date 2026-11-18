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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Operation 040: estimates the contributions of a turnover (api-reference §2). A read: nothing is recorded, safe to retry.
 */
interface ContributionEstimator
{
    /**
     * Estimates the contributions (040).
     *
     * @throws UrssafRequestRejected      classified ({@see RejectionKind}); 404 is `not_found` on 075
     * @throws UrssafAuthenticationFailed token 400/401/403, 401 after one replay, 403
     * @throws UrssafUnavailable          retryable: transport error, 429 (Retry-After), 500/502/503/504, token 429/5xx/transport
     * @throws RateLimitExceeded          the local limiter refused the call (nothing sent)
     * @throws RecordNotFound             "credentials.not_found": the tenant has no credentials (nothing sent)
     *
     * Any other {@see UrssafException} (UnexpectedUrssafResponse: another 5xx, another 4xx, an unreadable 2xx) means URSSAF
     * answered something the contract does not describe.
     */
    public function estimateContributions(TenantId $tenantId, Siret $siret, Turnover $turnover): Estimate;
}
