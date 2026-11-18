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
     * @throws OutcomeUnknown             a transport error or a 5xx may have reached URSSAF: not resent unless declared safe (ADR 0003)
     * @throws UrssafRequestRejected      classified ({@see RejectionKind})
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable          retryable: 429, token failure (and, when declared safe, transport error or 5xx)
     * @throws RateLimitExceeded          the local limiter refused the call
     *
     * Any other {@see UrssafException} means URSSAF answered something the contract does not describe.
     */
    public function declareTurnover(TenantId $tenantId, Siret $siret, TurnoverDeclaration $declaration): DeclarationResult;
}
