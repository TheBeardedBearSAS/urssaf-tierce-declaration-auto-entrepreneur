<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

final class RejectingRateLimiter implements RateLimiter
{
    public function acquire(TenantId $tenantId, Operation $operation): void
    {
        throw RateLimitExceeded::forOperation($operation, 30);
    }
}
