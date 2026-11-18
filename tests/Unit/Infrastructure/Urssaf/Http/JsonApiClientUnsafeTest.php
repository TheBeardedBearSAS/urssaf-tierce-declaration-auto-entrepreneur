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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknownCause;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\TransportError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\RetryMatrix;
use Throwable;

/**
 * The retry-class matrix of the client (ADR 0003) over every operation, with and without the write declared in
 * `retrySafeWrites`: transport errors and 5xx.
 *
 * One method per event.
 */
final class JsonApiClientUnsafeTest extends TestCase
{
    #[DataProvider('provideColumns')]
    public function testATransportError(Operation $operation, ApiConfiguration $configuration, string $expectation): void
    {
        $exception = ClientCalls::transportFailureWith($configuration, $operation);

        static::assertInstanceOf(Throwable::class, $exception);
        static::assertSame($operation, self::operationOf($exception));
        $previous = $this->assertTransportFailure($exception, $expectation);
        static::assertInstanceOf(TransportError::class, $previous);
        static::assertNull($previous->getPrevious());
        static::assertStringNotContainsString('12345678912345', $previous->getMessage());
        static::assertStringNotContainsString('https://', $previous->getMessage());
    }

    /**
     * @return iterable<string, array{Operation, ApiConfiguration, string}>
     */
    public static function provideColumns(): iterable
    {
        return RetryMatrix::columns();
    }

    /**
     * @return iterable<string, array{Operation, ApiConfiguration, string, int}>
     */
    public static function provideListedServerStatuses(): iterable
    {
        foreach (RetryMatrix::columns() as $name => [$operation, $configuration, $expectation]) {
            foreach ([500, 502, 503, 504] as $status) {
                yield $name . ' HTTP ' . $status => [$operation, $configuration, $expectation, $status];
            }
        }
    }

    #[DataProvider('provideListedServerStatuses')]
    public function testTheFourRetryableServerStatuses(
        Operation $operation,
        ApiConfiguration $configuration,
        string $expectation,
        int $status,
    ): void {
        $exception = ClientCalls::answerWith(
            $configuration,
            $operation,
            $status,
            '{"code":"ERR_TECHNIQUE","message":"m","description":"d"}',
            ['Retry-After' => '9'],
        );

        if ($expectation === RetryMatrix::RETRYABLE) {
            static::assertInstanceOf(UrssafUnavailable::class, $exception);
            static::assertSame($status, $exception->httpStatus());
            static::assertTrue($exception->mayHaveReachedUrssaf());
            static::assertSame(9, $exception->retryAfterSeconds());

            return;
        }

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(OutcomeUnknownCause::ServerError, $exception->cause());
        static::assertSame($operation, $exception->operation());
        static::assertSame($status, $exception->httpStatus());
        static::assertSame(['ERR_TECHNIQUE'], $exception->errors()->codes());
        $cause = $exception->getPrevious();
        static::assertInstanceOf(UrssafUnavailable::class, $cause);
        static::assertSame(9, $cause->retryAfterSeconds());
        static::assertTrue($cause->mayHaveReachedUrssaf());
    }

    /**
     * @return iterable<string, array{Operation, ApiConfiguration, string, int}>
     */
    public static function provideOtherServerStatuses(): iterable
    {
        foreach (RetryMatrix::columns() as $name => [$operation, $configuration, $expectation]) {
            foreach ([501, 505, 511, 512, 513, 599] as $status) {
                yield $name . ' HTTP ' . $status => [$operation, $configuration, $expectation, $status];
            }
        }
    }

    #[DataProvider('provideOtherServerStatuses')]
    public function testOtherServerStatusesAreUnexpectedWhenRetryableAndUnknownOtherwise(
        Operation $operation,
        ApiConfiguration $configuration,
        string $expectation,
        int $status,
    ): void {
        $exception = ClientCalls::answerWith($configuration, $operation, $status, '{}');

        if ($expectation === RetryMatrix::RETRYABLE) {
            static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
            static::assertSame($status, $exception->getCode());

            return;
        }

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(OutcomeUnknownCause::ServerError, $exception->cause());
        static::assertSame($status, $exception->httpStatus());
    }

    public function testFiveHundredTwelveIsOrdinary(): void
    {
        $pay = ClientCalls::answer(Operation::Pay, 512);
        $declare = ClientCalls::answer(Operation::Declare, 512);
        $find = ClientCalls::answer(Operation::FindAccount, 512);

        static::assertInstanceOf(OutcomeUnknown::class, $pay);
        static::assertInstanceOf(OutcomeUnknown::class, $declare);
        static::assertInstanceOf(UnexpectedUrssafResponse::class, $find);
    }

    private static function operationOf(Throwable $exception): ?Operation
    {
        return $exception instanceof UrssafUnavailable || $exception instanceof OutcomeUnknown
            ? $exception->operation()
            : null;
    }

    private function assertTransportFailure(Throwable $exception, string $expectation): ?Throwable
    {
        if ($expectation === RetryMatrix::RETRYABLE) {
            static::assertInstanceOf(UrssafUnavailable::class, $exception);
            static::assertTrue($exception->mayHaveReachedUrssaf());
            static::assertNull($exception->httpStatus());
            static::assertFalse($exception->isQuotaExceeded());
            static::assertFalse($exception->whileAuthenticating());

            return $exception->getPrevious();
        }

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(OutcomeUnknownCause::Transport, $exception->cause());
        static::assertNull($exception->httpStatus());
        $unavailable = $exception->getPrevious();
        static::assertInstanceOf(UrssafUnavailable::class, $unavailable);

        return $unavailable->getPrevious();
    }
}
