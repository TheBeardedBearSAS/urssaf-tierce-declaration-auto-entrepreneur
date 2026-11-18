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
 * Reads the 020 snapshot of the least recently synced accounts of a tenant, never synced first.
 *
 * The limit is capped by the workflow policy (default page size when null).
 */
final readonly class SyncAccounts
{
    public function __construct(
        public TenantId $tenantId,
        public ?int $limit,
    ) {}
}
