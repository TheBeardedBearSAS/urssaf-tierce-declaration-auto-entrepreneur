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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Operation 070: registers a SEPA mandate for a SIRET (api-reference §2). The NewSepaMandate holds no SIRET: it is passed separately (ADR 0008). The bank details are personal data (ADR 0010).
 */
interface SepaMandateRegistrar
{
    /**
     * Registers the SEPA mandate (070).
     *
     * @throws OutcomeUnknown             a transport error or a 5xx may have reached URSSAF: not resent unless declared safe (ADR 0003)
     * @throws UrssafRequestRejected      classified ({@see RejectionKind})
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable          retryable: 429, token failure (and, when declared safe, transport error or 5xx)
     * @throws RateLimitExceeded          the local limiter refused the call
     *
     * Any other {@see UrssafException} means URSSAF answered something the contract does not describe.
     */
    public function registerSepaMandate(TenantId $tenantId, Siret $siret, #[SensitiveParameter] NewSepaMandate $mandate): SepaMandate;
}
