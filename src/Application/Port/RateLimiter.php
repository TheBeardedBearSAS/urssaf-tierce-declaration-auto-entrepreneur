<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Client-side throttling of the URSSAF quota (ADR 0013). URSSAF publishes 300 requests per minute per API client: the
 * factory default `SlidingWindowRateLimiter` enforces it per tenant and per process; `NoopRateLimiter` leaves the quota
 * to HTTP 429. Token calls are not counted; the replay after a 401 is (except on 060, never replayed).
 *
 * The operation is informative (logs, messages): implementations must not keep per-operation quotas.
 */
interface RateLimiter
{
    /**
     * Reserves one call slot, blocking until one is available or throwing when the implementation is non-blocking.
     *
     * @throws RateLimitExceeded
     */
    public function acquire(TenantId $tenantId, Operation $operation): void;
}
