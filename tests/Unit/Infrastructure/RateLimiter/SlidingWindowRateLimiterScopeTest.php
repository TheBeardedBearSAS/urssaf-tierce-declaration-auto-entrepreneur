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
use Symfony\Component\Clock\MockClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;

/**
 * What the quota is counted over: one tenant, every operation together (ADR 0013).
 */
final class SlidingWindowRateLimiterScopeTest extends TestCase
{
    private const START = '2026-01-01T10:00:00+00:00';

    public function testQuotaIsSharedByAllOperationsOfATenant(): void
    {
        $limiter = new SlidingWindowRateLimiter(new SystemClock(new MockClock(self::START)), 3, 10);
        $tenant = TenantId::fromString('tenant-a');
        $limiter->acquire($tenant, Operation::FindAccount);
        $limiter->acquire($tenant, Operation::Declare);
        $limiter->acquire($tenant, Operation::NotifyMandate);

        $this->expectExceptionObject(RateLimitExceeded::forOperation(Operation::Estimate, 10));

        $limiter->acquire($tenant, Operation::Estimate);
    }

    public function testTenantsAreIsolated(): void
    {
        $limiter = new SlidingWindowRateLimiter(new SystemClock(new MockClock(self::START)), 1, 10);
        $limiter->acquire(TenantId::fromString('tenant-a'), Operation::FindAccount);

        $limiter->acquire(TenantId::fromString('tenant-b'), Operation::FindAccount);

        $this->expectException(RateLimitExceeded::class);

        $limiter->acquire(TenantId::fromString('tenant-a'), Operation::FindAccount);
    }

    public function testExceptionNamesTheOperationOfTheRefusedCall(): void
    {
        $limiter = new SlidingWindowRateLimiter(new SystemClock(new MockClock(self::START)), 1, 10);
        $tenant = TenantId::fromString('tenant-a');
        $limiter->acquire($tenant, Operation::FindAccount);

        try {
            $limiter->acquire($tenant, Operation::Pay);
            static::fail('The limit should be exceeded.');
        } catch (RateLimitExceeded $exception) {
            static::assertSame(Operation::Pay, $exception->operation());
        }
    }

    public function testLimitAndWindowOfOneAreAccepted(): void
    {
        $limiter = new SlidingWindowRateLimiter(new SystemClock(new MockClock(self::START)), 1, 1);
        $tenant = TenantId::fromString('tenant-a');

        $limiter->acquire($tenant, Operation::FindAccount);

        $this->expectExceptionObject(RateLimitExceeded::forOperation(Operation::FindAccount, 1));

        $limiter->acquire($tenant, Operation::FindAccount);
    }
}
