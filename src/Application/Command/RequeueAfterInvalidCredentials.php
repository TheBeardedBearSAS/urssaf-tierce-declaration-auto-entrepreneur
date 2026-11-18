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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Moves the declarations and payments refused for invalid credentials back to pending (once the tenant credentials are
 * fixed).
 *
 * The limit applies to each kind and is capped by the workflow policy (default page size when null).
 */
final readonly class RequeueAfterInvalidCredentials
{
    public function __construct(
        public TenantId $tenantId,
        public ?int $limit,
    ) {}
}
