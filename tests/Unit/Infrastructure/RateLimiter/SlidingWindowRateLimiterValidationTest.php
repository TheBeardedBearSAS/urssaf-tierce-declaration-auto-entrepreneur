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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;

final class SlidingWindowRateLimiterValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNonPositiveValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('provideNonPositiveValues')]
    public function testRejectsANonPositiveLimit(int $limit): void
    {
        $this->expectExceptionObject(InvalidValue::because('rate_limit.not_positive', 'The rate limit must be at least 1.'));

        new SlidingWindowRateLimiter(new SystemClock(new MockClock()), $limit, 10);
    }

    #[DataProvider('provideNonPositiveValues')]
    public function testRejectsANonPositiveWindow(int $window): void
    {
        $this->expectExceptionObject(InvalidValue::because('rate_limit_window.not_positive', 'The rate limit window must be at least 1 second.'));

        new SlidingWindowRateLimiter(new SystemClock(new MockClock()), 10, $window);
    }
}
