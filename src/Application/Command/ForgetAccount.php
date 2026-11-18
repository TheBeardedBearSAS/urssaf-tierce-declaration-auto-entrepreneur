<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * GDPR erasure of an auto-entrepreneur from the local store: the account, every mandate, every declaration record and
 * every payment record of the SIRET (ADR 0017). Nothing is sent to URSSAF: cancel the mandate first (CancelMandate) if it
 * must end there.
 *
 * "allowActiveMandate" and "allowUnknownOutcomes" opt out of two refusals: null applies the WorkflowPolicy default, a
 * boolean is an explicit choice of this call. A submission in flight is always refused.
 */
final readonly class ForgetAccount
{
    public function __construct(
        public TenantId $tenantId,
        public AccountSelector $account,
        public ?bool $allowActiveMandate,
        public ?bool $allowUnknownOutcomes,
    ) {}
}
