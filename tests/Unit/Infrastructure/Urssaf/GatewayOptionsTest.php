<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RecordingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SequenceIdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SteppingClock;

final class GatewayOptionsTest extends TestCase
{
    public function testDefaultsAreAllUnset(): void
    {
        $options = GatewayOptions::defaults();

        static::assertNull($options->rateLimiter());
        static::assertNull($options->idGenerator());
        static::assertNull($options->clock());
        static::assertNull($options->logger());
        static::assertNull($options->rateLimit());
        static::assertNull($options->rateLimitWindowSeconds());
    }

    public function testEachWitherSetsOnlyItsOwnValue(): void
    {
        $limiter = new RecordingRateLimiter();
        $ids = new SequenceIdGenerator();
        $clock = new SteppingClock(0);
        $logger = new CapturingLogger();
        $base = GatewayOptions::defaults()->withRateLimit(10, 20);

        $withLimiter = $base->withRateLimiter($limiter);
        static::assertSame($limiter, $withLimiter->rateLimiter());
        static::assertNull($withLimiter->idGenerator());
        static::assertNull($withLimiter->clock());
        static::assertNull($withLimiter->logger());
        static::assertSame(10, $withLimiter->rateLimit());
        static::assertSame(20, $withLimiter->rateLimitWindowSeconds());

        $withIds = $base->withIdGenerator($ids);
        static::assertNull($withIds->rateLimiter());
        static::assertSame($ids, $withIds->idGenerator());
        static::assertNull($withIds->clock());
        static::assertNull($withIds->logger());
        static::assertSame(10, $withIds->rateLimit());
        static::assertSame(20, $withIds->rateLimitWindowSeconds());

        $withClock = $base->withClock($clock);
        static::assertNull($withClock->rateLimiter());
        static::assertNull($withClock->idGenerator());
        static::assertSame($clock, $withClock->clock());
        static::assertNull($withClock->logger());
        static::assertSame(10, $withClock->rateLimit());
        static::assertSame(20, $withClock->rateLimitWindowSeconds());

        $withLogger = $base->withLogger($logger);
        static::assertNull($withLogger->rateLimiter());
        static::assertNull($withLogger->idGenerator());
        static::assertNull($withLogger->clock());
        static::assertSame($logger, $withLogger->logger());
        static::assertSame(10, $withLogger->rateLimit());
        static::assertSame(20, $withLogger->rateLimitWindowSeconds());
    }

    public function testWithRateLimitKeepsTheOtherFields(): void
    {
        $limiter = new RecordingRateLimiter();
        $ids = new SequenceIdGenerator();
        $clock = new SteppingClock(0);
        $logger = new CapturingLogger();

        $options = GatewayOptions::defaults()
            ->withRateLimiter($limiter)
            ->withIdGenerator($ids)
            ->withClock($clock)
            ->withLogger($logger)
            ->withRateLimit(5, 30);

        static::assertSame($limiter, $options->rateLimiter());
        static::assertSame($ids, $options->idGenerator());
        static::assertSame($clock, $options->clock());
        static::assertSame($logger, $options->logger());
        static::assertSame(5, $options->rateLimit());
        static::assertSame(30, $options->rateLimitWindowSeconds());
    }

    public function testWithersChainAndKeepTheOthers(): void
    {
        $limiter = new RecordingRateLimiter();
        $ids = new SequenceIdGenerator();
        $clock = new SteppingClock(0);
        $logger = new NullLogger();

        $options = GatewayOptions::defaults()
            ->withRateLimit(1, 1)
            ->withRateLimiter($limiter)
            ->withIdGenerator($ids)
            ->withClock($clock)
            ->withLogger($logger);

        static::assertSame($limiter, $options->rateLimiter());
        static::assertSame($ids, $options->idGenerator());
        static::assertSame($clock, $options->clock());
        static::assertSame($logger, $options->logger());
        static::assertSame(1, $options->rateLimit());
        static::assertSame(1, $options->rateLimitWindowSeconds());
    }

    public function testOptionsAreImmutable(): void
    {
        $defaults = GatewayOptions::defaults();

        $defaults->withLogger(new CapturingLogger())->withRateLimit(3, 4);

        static::assertNull($defaults->logger());
        static::assertNull($defaults->rateLimit());
        static::assertNull($defaults->rateLimitWindowSeconds());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideAcceptedRateLimits(): iterable
    {
        yield 'minimum' => [1, 1];
        yield 'documented quota' => [300, 60];
        yield 'above the quota' => [301, 61];
    }

    #[DataProvider('provideAcceptedRateLimits')]
    public function testItAcceptsAPositiveRateLimit(int $limit, int $window): void
    {
        $options = GatewayOptions::defaults()->withRateLimit($limit, $window);

        static::assertSame($limit, $options->rateLimit());
        static::assertSame($window, $options->rateLimitWindowSeconds());
    }

    /**
     * @return iterable<string, array{int, int, string, string}>
     */
    public static function provideRefusedRateLimits(): iterable
    {
        yield 'zero limit' => [0, 60, 'rate_limit.not_positive', 'The rate limit must be at least 1.'];
        yield 'negative limit' => [-1, 60, 'rate_limit.not_positive', 'The rate limit must be at least 1.'];
        yield 'zero window' => [300, 0, 'rate_limit_window.not_positive', 'The rate limit window must be at least 1 second.'];
        yield 'negative window' => [300, -1, 'rate_limit_window.not_positive', 'The rate limit window must be at least 1 second.'];
        yield 'both invalid reports the limit' => [0, 0, 'rate_limit.not_positive', 'The rate limit must be at least 1.'];
    }

    #[DataProvider('provideRefusedRateLimits')]
    public function testItRefusesANonPositiveRateLimit(int $limit, int $window, string $code, string $message): void
    {
        try {
            GatewayOptions::defaults()->withRateLimit($limit, $window);
            static::fail('The rate limit must be refused.');
        } catch (InvalidValue $exception) {
            static::assertSame($code, $exception->violationCode());
            static::assertSame($message, $exception->getMessage());
        }
    }
}
