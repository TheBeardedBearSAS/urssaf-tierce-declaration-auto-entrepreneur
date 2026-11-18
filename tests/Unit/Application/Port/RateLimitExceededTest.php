<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Port;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;

final class RateLimitExceededTest extends TestCase
{
    public function testMessageAndAccessors(): void
    {
        $exception = RateLimitExceeded::forOperation(Operation::Declare, 12);

        static::assertSame(Operation::Declare, $exception->operation());
        static::assertSame(12, $exception->retryAfterSeconds());
        static::assertSame(
            'Local rate limit reached for URSSAF method 050; retry in 12 second(s).',
            $exception->getMessage(),
        );
        static::assertContains(RetryableException::class, class_implements($exception));
    }

    public function testNegativeDelaysAreClampedToZero(): void
    {
        $exception = RateLimitExceeded::forOperation(Operation::NotifyMandate, -3);

        static::assertSame(0, $exception->retryAfterSeconds());
        static::assertSame(
            'Local rate limit reached for URSSAF method 030; retry in 0 second(s).',
            $exception->getMessage(),
        );
        static::assertSame(0, RateLimitExceeded::forOperation(Operation::NotifyMandate, 0)->retryAfterSeconds());
    }

    public function testItNeverHasReachedUrssaf(): void
    {
        $exception = RateLimitExceeded::forOperation(Operation::Pay, 1);

        static::assertFalse((new ReflectionMethod($exception, 'mayHaveReachedUrssaf'))->invoke($exception));
        static::assertSame('false', (string) (new ReflectionMethod($exception, 'mayHaveReachedUrssaf'))->getReturnType());
        static::assertTrue((new ReflectionMethod($exception, 'mayHaveReachedUrssaf'))->isPublic());
        static::assertContains(UrssafException::class, class_implements($exception));
    }
}
