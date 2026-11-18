<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;

/**
 * The local rate limiter (ADR 0013): off, a host limiter, or the built-in sliding window. A limit above the published
 * 300 calls per minute is accepted but reported.
 */
final readonly class RateLimiterCheck implements InstallationCheck
{
    private const NAME = 'rate limiter';

    /**
     * @param bool    $enabled       the "rate_limiter.enabled" option
     * @param int     $limit         the "rate_limiter.limit" option
     * @param ?string $service       the "rate_limiter.service" option
     * @param int     $windowSeconds the "rate_limiter.window" option, in seconds
     */
    public function __construct(private bool $enabled, private int $limit, private ?string $service, private int $windowSeconds) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        $result = match (true) {
            !$this->enabled => [CheckStatus::Ok, 'Off ("rate_limiter.enabled": false): URSSAF answers 429 beyond its own limit.'],
            $this->service !== null => [CheckStatus::Ok, \sprintf('Host limiter "%s".', $this->service)],
            $this->limit > SlidingWindowRateLimiter::DEFAULT_LIMIT => [
                CheckStatus::Warning,
                \sprintf('%d per %d s: above the published %d per minute (ADR 0013).', $this->limit, $this->windowSeconds, SlidingWindowRateLimiter::DEFAULT_LIMIT),
            ],
            default => [CheckStatus::Ok, \sprintf('%d per %d s.', $this->limit, $this->windowSeconds)],
        };

        return [new CheckResult(self::NAME, '', $result[0], $result[1])];
    }
}
