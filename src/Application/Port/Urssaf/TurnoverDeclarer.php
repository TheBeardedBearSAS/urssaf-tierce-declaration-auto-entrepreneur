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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Operation 050: declares a turnover (api-reference §2). The specification does not state its idempotency (the last declaration wins in the sandbox): an unknown outcome is not resent by default (ADR 0003).
 */
interface TurnoverDeclarer
{
    /**
     * Declares the turnover (050).
     *
     * @throws OutcomeUnknown             unlisted: transport error or any 5xx; listed or not: a 2xx that cannot be read
     *                                    (ADR 0003). Never resent automatically.
     * @throws UrssafRequestRejected      classified ({@see RejectionKind})
     * @throws UrssafAuthenticationFailed token 400/401/403, 401 after one replay, 403
     * @throws UrssafUnavailable          retryable: 429, token failure; when listed in retrySafeWrites also a transport error
     *                                    or 500/502/503/504 (mayHaveReachedUrssaf() true)
     * @throws RateLimitExceeded          the local limiter refused the call (nothing sent)
     * @throws RecordNotFound             "credentials.not_found": the tenant has no credentials (nothing sent)
     *
     * Any other {@see UrssafException} means URSSAF answered something the contract does not describe.
     */
    public function declareTurnover(TenantId $tenantId, Siret $siret, TurnoverDeclaration $declaration): DeclarationResult;
}
