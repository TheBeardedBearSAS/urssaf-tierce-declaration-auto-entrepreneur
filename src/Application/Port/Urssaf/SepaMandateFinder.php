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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Operation 075: lists the SEPA mandates of a SIRET (api-reference §2). A read: nothing is recorded, safe to retry.
 */
interface SepaMandateFinder
{
    /**
     * Lists the SEPA mandates (075).
     *
     * @return list<SepaMandate>
     * @throws UrssafRequestRejected      classified ({@see RejectionKind}); 404 is `not_found` on 075
     * @throws UrssafAuthenticationFailed token 400/401/403, 401 after one replay, 403
     * @throws UrssafUnavailable          retryable: transport error, 429 (Retry-After), 500/502/503/504, token 429/5xx/transport
     * @throws RateLimitExceeded          the local limiter refused the call (nothing sent)
     * @throws RecordNotFound             "credentials.not_found": the tenant has no credentials (nothing sent)
     *
     * Any other {@see UrssafException} (UnexpectedUrssafResponse: another 5xx, another 4xx, an unreadable 2xx) means URSSAF
     * answered something the contract does not describe.
     */
    public function listSepaMandates(TenantId $tenantId, Siret $siret): array;
}
