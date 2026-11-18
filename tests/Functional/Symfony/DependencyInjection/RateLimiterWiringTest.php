<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The local limiter (ADR 0013 item 3): off in the bundle, a sliding window per tenant when enabled, or the one of the host.
 */
final class RateLimiterWiringTest extends TestCase
{
    public function testTheLimiterIsOffByDefault(): void
    {
        static::assertInstanceOf(NoopRateLimiter::class, Harness::boot()->privateService(RateLimiter::class, RateLimiter::class));
    }

    public function testTheBuiltInLimiterRefusesTheCallsAboveTheLimitOfTheWindow(): void
    {
        $harness = Harness::boot(['rate_limiter' => ['enabled' => true, 'limit' => 2, 'window' => 'PT1M']]);
        $gateway = $harness->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class);

        $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
        $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertInstanceOf(SlidingWindowRateLimiter::class, $harness->privateService(RateLimiter::class, RateLimiter::class));
        try {
            $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
            static::fail('The third call of the window must be refused.');
        } catch (RateLimitExceeded $refusal) {
            static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
            static::assertGreaterThan(0, $refusal->retryAfterSeconds());
            static::assertLessThanOrEqual(60, $refusal->retryAfterSeconds());
        }
    }

    public function testTheWindowIsTheConfiguredDuration(): void
    {
        $harness = Harness::boot(['rate_limiter' => ['enabled' => true, 'limit' => 1, 'window' => 'PT2M']]);
        $gateway = $harness->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class);
        $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        try {
            $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
            static::fail('The second call of the window must be refused.');
        } catch (RateLimitExceeded $refusal) {
            static::assertGreaterThan(60, $refusal->retryAfterSeconds());
            static::assertLessThanOrEqual(120, $refusal->retryAfterSeconds());
        }

        $harness->clock->modify('+121 seconds');
        $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheLimiterOfTheHostReplacesTheBuiltInOne(): void
    {
        $harness = Harness::boot(['rate_limiter' => ['enabled' => true, 'service' => 'test.limiter']]);

        static::assertInstanceOf(NoopRateLimiter::class, $harness->privateService(RateLimiter::class, RateLimiter::class));
        static::assertSame($harness->privateService('test.limiter', RateLimiter::class), $harness->privateService(RateLimiter::class, RateLimiter::class));
    }
}
