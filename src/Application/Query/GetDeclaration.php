<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Reads one declaration record of a tenant, by record id or as the current record of a SIRET and a period.
 */
final readonly class GetDeclaration
{
    public function __construct(public TenantId $tenantId, public DeclarationSelector $declaration) {}
}
