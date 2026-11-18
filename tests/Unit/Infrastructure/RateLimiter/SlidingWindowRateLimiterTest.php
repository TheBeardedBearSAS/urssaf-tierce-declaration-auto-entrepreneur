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

final class SlidingWindowRateLimiterTest extends TestCase
{
    private const START = '2026-01-01T10:00:00+00:00';

    public function testAllowsCallsUpToTheDefaultQuotaThenRejectsTheNext(): void
    {
        $limiter = new SlidingWindowRateLimiter(new SystemClock(new MockClock(self::START)));
        $tenant = TenantId::fromString('tenant-a');

        for ($i = 0; $i < 300; ++$i) {
            static::assertNull(self::retryAfterOrNull($limiter, $tenant));
        }

        static::assertSame(60, self::retryAfterOrNull($limiter, $tenant));
    }

    public function testRejectsTheCallAboveTheLimit(): void
    {
        $limiter = new SlidingWindowRateLimiter(new SystemClock(new MockClock(self::START)), 3, 10);
        $tenant = TenantId::fromString('tenant-a');
        self::fill($limiter, $tenant, 3);

        $this->expectExceptionObject(RateLimitExceeded::forOperation(Operation::FindAccount, 10));

        $limiter->acquire($tenant, Operation::FindAccount);
    }

    public function testRetryDelayIsTheTimeUntilTheOldestCallLeavesTheWindow(): void
    {
        $clock = new MockClock(self::START);
        $limiter = new SlidingWindowRateLimiter(new SystemClock($clock), 3, 60);
        $tenant = TenantId::fromString('tenant-a');
        $limiter->acquire($tenant, Operation::FindAccount);
        $clock->modify('+10 seconds');
        $limiter->acquire($tenant, Operation::FindAccount);
        $limiter->acquire($tenant, Operation::FindAccount);

        static::assertSame(50, self::retryAfterOrNull($limiter, $tenant));
    }

    public function testRetryDelayNeverExceedsTheWindowWhenTheClockGoesBackwards(): void
    {
        $clock = new MockClock(self::START);
        $limiter = new SlidingWindowRateLimiter(new SystemClock($clock), 1, 60);
        $tenant = TenantId::fromString('tenant-a');
        $clock->modify('+30 seconds');
        $limiter->acquire($tenant, Operation::FindAccount);
        $clock->modify('-20 seconds');

        static::assertSame(60, self::retryAfterOrNull($limiter, $tenant));
    }

    public function testRetryDelayFollowsTheOldestCallEvenWhenTheClockWentBackwards(): void
    {
        $clock = new MockClock(self::START);
        $limiter = new SlidingWindowRateLimiter(new SystemClock($clock), 3, 60);
        $tenant = TenantId::fromString('tenant-a');
        $clock->modify('+30 seconds');
        $limiter->acquire($tenant, Operation::FindAccount);
        $clock->modify('-25 seconds');
        $limiter->acquire($tenant, Operation::FindAccount);
        $clock->modify('+15 seconds');
        $limiter->acquire($tenant, Operation::FindAccount);

        static::assertSame(45, self::retryAfterOrNull($limiter, $tenant));
    }

    public function testCallsLeaveTheWindowExactlyWindowSecondsLater(): void
    {
        $clock = new MockClock(self::START);
        $limiter = new SlidingWindowRateLimiter(new SystemClock($clock), 2, 10);
        $tenant = TenantId::fromString('tenant-a');
        self::fill($limiter, $tenant, 2);

        $clock->modify('+9 seconds');
        static::assertSame(1, self::retryAfterOrNull($limiter, $tenant));

        $clock->modify('+1 second');
        static::assertNull(self::retryAfterOrNull($limiter, $tenant));
    }

    public function testRejectedCallsAreNotCounted(): void
    {
        $clock = new MockClock(self::START);
        $limiter = new SlidingWindowRateLimiter(new SystemClock($clock), 1, 10);
        $tenant = TenantId::fromString('tenant-a');
        $limiter->acquire($tenant, Operation::FindAccount);
        $clock->modify('+5 seconds');
        static::assertSame(5, self::retryAfterOrNull($limiter, $tenant));

        $clock->modify('+5 seconds');

        static::assertNull(self::retryAfterOrNull($limiter, $tenant));
    }

    private static function fill(SlidingWindowRateLimiter $limiter, TenantId $tenant, int $calls): void
    {
        for ($i = 0; $i < $calls; ++$i) {
            $limiter->acquire($tenant, Operation::FindAccount);
        }
    }

    /**
     * Returns the retry delay when the call is rejected, null when it is allowed.
     */
    private static function retryAfterOrNull(SlidingWindowRateLimiter $limiter, TenantId $tenant): ?int
    {
        try {
            $limiter->acquire($tenant, Operation::FindAccount);
        } catch (RateLimitExceeded $exception) {
            return $exception->retryAfterSeconds();
        }

        return null;
    }
}
