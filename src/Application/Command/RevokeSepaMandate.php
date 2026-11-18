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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Revokes a SEPA mandate (method 071, ADR 0009). The effective date, when given, must be after today in the business
 * time zone (ADR 0011 item 4); "skipEffectiveDateCheck" opts out of that local check.
 */
final readonly class RevokeSepaMandate
{
    public function __construct(
        public TenantId $tenantId,
        public SepaRevocation $revocation,
        public bool $skipEffectiveDateCheck = false,
    ) {}
}
