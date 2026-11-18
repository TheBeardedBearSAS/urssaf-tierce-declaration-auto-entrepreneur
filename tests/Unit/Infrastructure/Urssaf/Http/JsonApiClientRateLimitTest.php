<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RejectingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ApiRequests;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;

/**
 * Local rate limiting (ADR 0013): one acquisition per attempt, never for the token call.
 */
final class JsonApiClientRateLimitTest extends TestCase
{
    public function testItAcquiresTheRateLimiterOncePerCallForItsOperation(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, '{}');
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::FindAccount);
        ClientCalls::send($stack, Operation::Declare);

        static::assertSame(['tenant-1:020', 'tenant-1:050'], $stack->rateLimiter->acquired());
    }

    public function testTheTokenCallIsNotCounted(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::Estimate);

        static::assertCount(2, $stack->requests());
        static::assertSame(['tenant-1:040'], $stack->rateLimiter->acquired());
    }

    public function testARefusedAcquisitionSendsNothingAndFetchesNoToken(): void
    {
        $stack = Stacks::sandbox();

        try {
            $stack->jsonClientWith(new RejectingRateLimiter())->send(
                Stacks::tenant(),
                $stack->tenantCredentials(),
                ApiRequests::of(Operation::Pay),
            );
            static::fail('Expected the rate limit to be enforced.');
        } catch (RateLimitExceeded $exception) {
            static::assertSame(30, $exception->retryAfterSeconds());
            static::assertSame(Operation::Pay, $exception->operation());
        }
        static::assertSame([], $stack->requests());
        static::assertSame([], $stack->tokens->keys());
        static::assertSame([], $stack->logger->records());
    }
}
