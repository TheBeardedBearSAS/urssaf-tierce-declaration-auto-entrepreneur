<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf;

use Psr\Log\LoggerInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * Optional collaborators of the gateway (see {@see UrssafGatewayFactory}), immutable; each one not given keeps its
 * default:
 * - rate limiter: an in-memory sliding window enforcing the URSSAF quota of 300 requests per 60 seconds per tenant
 *   within the current process (ADR 0013; give a shared limiter, e.g. a framework one, when several workers call
 *   URSSAF). An explicit {@see self::withRateLimiter()} wins over {@see self::withRateLimit()};
 * - rate limit: the limit and the window of that default limiter;
 * - id generator: random UUIDs (used only when a request-id header is configured);
 * - clock: the system clock (UTC);
 * - logger: no logging.
 *
 *     GatewayOptions::defaults()->withLogger($logger)->withRateLimiter($sharedRateLimiter)
 */
final readonly class GatewayOptions
{
    private function __construct(
        private ?RateLimiter $rateLimiter,
        private ?IdGenerator $idGenerator,
        private ?Clock $clock,
        private ?LoggerInterface $logger,
        private ?int $rateLimit,
        private ?int $rateLimitWindowSeconds,
    ) {}

    public static function defaults(): self
    {
        return new self(null, null, null, null, null, null);
    }

    public function withRateLimiter(RateLimiter $rateLimiter): self
    {
        return new self(
            $rateLimiter,
            $this->idGenerator,
            $this->clock,
            $this->logger,
            $this->rateLimit,
            $this->rateLimitWindowSeconds,
        );
    }

    public function withIdGenerator(IdGenerator $idGenerator): self
    {
        return new self(
            $this->rateLimiter,
            $idGenerator,
            $this->clock,
            $this->logger,
            $this->rateLimit,
            $this->rateLimitWindowSeconds,
        );
    }

    public function withClock(Clock $clock): self
    {
        return new self(
            $this->rateLimiter,
            $this->idGenerator,
            $clock,
            $this->logger,
            $this->rateLimit,
            $this->rateLimitWindowSeconds,
        );
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self(
            $this->rateLimiter,
            $this->idGenerator,
            $this->clock,
            $logger,
            $this->rateLimit,
            $this->rateLimitWindowSeconds,
        );
    }

    /**
     * Limit and window of the default sliding-window rate limiter (ignored when a rate limiter is given).
     *
     * @throws InvalidValue "rate_limit.not_positive", "rate_limit_window.not_positive"
     */
    public function withRateLimit(int $limit, int $windowSeconds): self
    {
        if ($limit < 1) {
            throw InvalidValue::because('rate_limit.not_positive', 'The rate limit must be at least 1.');
        }

        if ($windowSeconds < 1) {
            throw InvalidValue::because(
                'rate_limit_window.not_positive',
                'The rate limit window must be at least 1 second.',
            );
        }

        return new self($this->rateLimiter, $this->idGenerator, $this->clock, $this->logger, $limit, $windowSeconds);
    }

    /**
     * Null when not given (the factory default applies).
     */
    public function rateLimiter(): ?RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Null when not given (the factory default applies).
     */
    public function idGenerator(): ?IdGenerator
    {
        return $this->idGenerator;
    }

    /**
     * Null when not given (the factory default applies).
     */
    public function clock(): ?Clock
    {
        return $this->clock;
    }

    /**
     * Null when not given (the factory default applies).
     */
    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Null when not given (the factory default of 300 applies).
     */
    public function rateLimit(): ?int
    {
        return $this->rateLimit;
    }

    /**
     * Null when not given (the factory default of 60 seconds applies).
     */
    public function rateLimitWindowSeconds(): ?int
    {
        return $this->rateLimitWindowSeconds;
    }
}
