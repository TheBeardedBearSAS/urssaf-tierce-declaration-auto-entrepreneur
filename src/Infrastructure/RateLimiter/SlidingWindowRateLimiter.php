<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Non-blocking in-memory limiter of the URSSAF quota (ADR 0013): at most $limit calls per tenant (= per API client), all
 * operations together, in any sliding window of $windowSeconds (default 300 per 60 s). Per process. Token calls are
 * not counted (the client never acquires for them). Call instants are kept at second precision; when the limit is
 * reached the retry delay is the time left until the oldest call leaves the window.
 */
final class SlidingWindowRateLimiter implements RateLimiter
{
    public const DEFAULT_LIMIT = 300;
    public const DEFAULT_WINDOW_SECONDS = 60;

    /** @var array<string, array<int, int>> calls per second (UNIX timestamp => count) per tenant identifier */
    private array $calls = [];

    /**
     * @throws InvalidValue "rate_limit.not_positive" / "rate_limit_window.not_positive"
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
        if ($limit < 1) {
            throw InvalidValue::because('rate_limit.not_positive', 'The rate limit must be at least 1.');
        }

        if ($windowSeconds < 1) {
            throw InvalidValue::because('rate_limit_window.not_positive', 'The rate limit window must be at least 1 second.');
        }
    }

    /**
     * @throws RateLimitExceeded
     */
    public function acquire(TenantId $tenantId, Operation $operation): void
    {
        $now = $this->clock->now()->getTimestamp();
        $windowStart = $now - $this->windowSeconds;
        $recent = array_filter(
            $this->calls[$tenantId->value()] ?? [],
            static fn(int $instant): bool => $instant > $windowStart,
            \ARRAY_FILTER_USE_KEY,
        );

        if (array_sum($recent) >= $this->limit) {
            throw RateLimitExceeded::forOperation($operation, min([$now, ...array_keys($recent)]) + $this->windowSeconds - $now);
        }

        $recent[$now] = ($recent[$now] ?? 0) + 1;
        $this->calls[$tenantId->value()] = $recent;
    }
}
