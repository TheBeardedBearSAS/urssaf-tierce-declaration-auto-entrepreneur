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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Operation 060: orders the payment of the contributions (api-reference §2). Never resent, whatever the configuration: an unknown outcome is reconciled by an operator (ADR 0003).
 */
interface ContributionPayer
{
    /**
     * Orders the payment (060).
     *
     * @throws OutcomeUnknown             transport error, any 5xx, or a 2xx that cannot be read: never resent, not configurable
     * @throws UrssafRequestRejected      classified ({@see RejectionKind}) (400)
     * @throws UrssafAuthenticationFailed token 400/401/403, 401 (never replayed on 060, ADR 0003), 403
     * @throws UrssafUnavailable          retryable: 429 or token failure only (the call did not reach URSSAF)
     * @throws RateLimitExceeded          the local limiter refused the call (nothing sent)
     * @throws RecordNotFound             "credentials.not_found": the tenant has no credentials (nothing sent)
     *
     * Any other {@see UrssafException} means URSSAF answered something the contract does not describe.
     */
    public function payContributions(TenantId $tenantId, Siret $siret, #[SensitiveParameter] PaymentOrder $order): PaymentResult;
}
