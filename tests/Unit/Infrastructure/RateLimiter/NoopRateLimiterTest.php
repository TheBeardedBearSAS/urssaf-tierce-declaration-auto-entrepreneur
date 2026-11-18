<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\RateLimiter;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;

final class NoopRateLimiterTest extends TestCase
{
    public function testNeverThrottles(): void
    {
        $limiter = new NoopRateLimiter();
        $tenant = TenantId::fromString('tenant-a');

        for ($i = 0; $i < 500; ++$i) {
            $limiter->acquire($tenant, Operation::FindAccount);
        }

        $this->expectNotToPerformAssertions();
    }
}
