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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RejectingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ApiRequests;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\RetryMatrix;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use Throwable;

/**
 * Answers that are never an unknown outcome, whatever the retry class (ADR 0003): 429, 403, the local rate limit,
 * token failures and statuses outside the 5xx range.
 *
 * One method per event.
 *
 * @mago-expect lint:too-many-methods
 */
final class JsonApiClientNeverUnknownTest extends TestCase
{
    /**
     * @return iterable<string, array{Operation, ApiConfiguration}>
     */
    public static function provideColumns(): iterable
    {
        foreach (RetryMatrix::columns() as $name => [$operation, $configuration]) {
            yield $name => [$operation, $configuration];
        }
    }

    #[DataProvider('provideColumns')]
    public function testA429IsAnUnavailableQuotaWhateverTheClass(Operation $operation, ApiConfiguration $configuration): void
    {
        $withHeader = ClientCalls::answerWith($configuration, $operation, 429, '{}', ['Retry-After' => '7']);
        $without = ClientCalls::answerWith($configuration, $operation, 429);

        static::assertInstanceOf(UrssafUnavailable::class, $withHeader);
        static::assertTrue($withHeader->isQuotaExceeded());
        static::assertSame(7, $withHeader->retryAfterSeconds());
        static::assertFalse($withHeader->mayHaveReachedUrssaf());
        static::assertInstanceOf(UrssafUnavailable::class, $without);
        static::assertTrue($without->isQuotaExceeded());
        static::assertNull($without->retryAfterSeconds());
        static::assertFalse($without->mayHaveReachedUrssaf());
    }

    #[DataProvider('provideColumns')]
    public function testA403IsAnAuthenticationFailure(Operation $operation, ApiConfiguration $configuration): void
    {
        $exception = ClientCalls::answerWith($configuration, $operation, 403);

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(403, $exception->httpStatus());
    }

    #[DataProvider('provideColumns')]
    public function testTheLocalRateLimitIsPropagatedWithoutAnyHttpRequest(Operation $operation, ApiConfiguration $configuration): void
    {
        $stack = Stacks::configured($configuration);

        try {
            $stack->jsonClientWith(new RejectingRateLimiter())->send(
                Stacks::tenant(),
                $stack->tenantCredentials(),
                ApiRequests::of($operation),
            );
            static::fail('Expected the rate limit to be enforced.');
        } catch (RateLimitExceeded $exception) {
            static::assertSame($operation, $exception->operation());
        }
        static::assertSame([], $stack->requests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideTokenFailures(): iterable
    {
        yield 'quota' => [429];
        yield 'server error' => [500];
        yield 'bad gateway' => [502];
        yield 'last 5xx' => [599];
    }

    #[DataProvider('provideTokenFailures')]
    public function testTokenFailuresNeverBecomeAnUnknownOutcome(int $status): void
    {
        foreach (Operation::cases() as $operation) {
            $stack = Stacks::sandbox();
            $stack->respond($status, '');

            try {
                ClientCalls::send($stack, $operation);
                static::fail('Expected a token failure.');
            } catch (UrssafUnavailable $exception) {
                static::assertTrue($exception->whileAuthenticating());
                static::assertFalse($exception->mayHaveReachedUrssaf());
                static::assertSame($status, $exception->httpStatus());
            }
            static::assertCount(1, $stack->requests());
        }
    }

    public function testATokenTransportErrorNeverBecomesAnUnknownOutcome(): void
    {
        foreach (Operation::cases() as $operation) {
            $stack = Stacks::sandbox();
            $stack->fail(new TransportFailure('down'));

            try {
                ClientCalls::send($stack, $operation);
                static::fail('Expected a token failure.');
            } catch (UrssafUnavailable $exception) {
                static::assertTrue($exception->whileAuthenticating());
                static::assertFalse($exception->mayHaveReachedUrssaf());
                static::assertNull($exception->httpStatus());
            }
        }
    }

    public function testTokenAuthenticationFailuresAreNotRetried(): void
    {
        foreach ([400, 401, 403] as $status) {
            $stack = Stacks::sandbox();
            $stack->respond($status, '');

            try {
                ClientCalls::send($stack, Operation::Pay);
                static::fail('Expected an authentication failure.');
            } catch (UrssafAuthenticationFailed $exception) {
                static::assertSame($status, $exception->httpStatus());
            }
            static::assertCount(1, $stack->requests());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNotServerStatuses(): iterable
    {
        yield 'just below the 5xx range' => [499];
        yield 'informational' => [199];
        yield 'redirect' => [302];
        yield 'teapot' => [418];
        yield 'just above the 5xx range' => [600];
    }

    #[DataProvider('provideNotServerStatuses')]
    public function testOtherStatusesAreUnexpectedAndNeverUnknown(int $status): void
    {
        foreach ([Operation::Pay, Operation::Declare] as $operation) {
            $exception = ClientCalls::answer($operation, $status);

            static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
            static::assertSame($status, $exception->getCode());
        }
    }

    public function testSuccessIsReturnedForEveryClass(): void
    {
        foreach (Operation::cases() as $operation) {
            $response = ClientCalls::answer($operation, 200, '{}');

            static::assertNotInstanceOf(Throwable::class, $response);
        }
    }
}
