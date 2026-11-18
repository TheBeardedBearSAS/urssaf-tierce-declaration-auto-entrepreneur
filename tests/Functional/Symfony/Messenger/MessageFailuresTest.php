<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Messenger;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\MessageFailures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\RetryDelay;
use Throwable;

/**
 * Failure mapping of the Messenger handlers: a temporary failure is retried, any other failure of the package (an unknown
 * outcome included) is not.
 */
final class MessageFailuresTest extends TestCase
{
    public function testATemporaryFailureIsRecoverableAndKeepsTheRetryDelayRequestedByUrssaf(): void
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::FindAccount, 503, 120, ApiErrors::none());

        $failure = self::caught(static function () use ($unavailable): never {
            throw $unavailable;
        });

        static::assertInstanceOf(RecoverableMessageHandlingException::class, $failure);
        RetryDelay::assertCarries(120_000, $failure);
        static::assertSame(0, $failure->getCode());
        static::assertSame($unavailable, $failure->getPrevious());
        static::assertSame($unavailable->getMessage(), $failure->getMessage());
    }

    public function testTheRetryDelayIsFoundAlongThePreviousChain(): void
    {
        $wrapped = RetryableUrssafFailure::causedBy(UrssafUnavailable::withStatus(Operation::FindAccount, 429, 7, ApiErrors::none()));

        $failure = self::caught(static function () use ($wrapped): never {
            throw $wrapped;
        });

        static::assertInstanceOf(RecoverableMessageHandlingException::class, $failure);
        RetryDelay::assertCarries(7000, $failure);
        static::assertSame($wrapped, $failure->getPrevious());
    }

    public function testTheRetryDelayComesFromTheLocalRateLimiterToo(): void
    {
        $failure = self::caught(static function (): never {
            throw RateLimitExceeded::forOperation(Operation::FindAccount, 3);
        });
        $immediate = self::caught(static function (): never {
            throw RateLimitExceeded::forOperation(Operation::FindAccount, 0);
        });

        static::assertInstanceOf(RecoverableMessageHandlingException::class, $failure);
        RetryDelay::assertCarries(3000, $failure);
        static::assertInstanceOf(RecoverableMessageHandlingException::class, $immediate);
        RetryDelay::assertCarries(0, $immediate);
    }

    public function testThereIsNoRetryDelayWhenUrssafGivesNone(): void
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::FindAccount, 503, null, ApiErrors::none());

        $failure = self::caught(static function () use ($unavailable): never {
            throw $unavailable;
        });

        static::assertInstanceOf(RecoverableMessageHandlingException::class, $failure);
        RetryDelay::assertCarries(null, $failure);
    }

    public function testThereIsNoRetryDelayForATransportFailure(): void
    {
        $transport = UrssafUnavailable::dueToTransportError(Operation::FindAccount, new LogicException('refused'));

        $failure = self::caught(static function () use ($transport): never {
            throw $transport;
        });

        static::assertInstanceOf(RecoverableMessageHandlingException::class, $failure);
        RetryDelay::assertCarries(null, $failure);
        static::assertSame($transport, $failure->getPrevious());
    }

    public function testEveryOtherFailureOfThePackageIsUnrecoverable(): void
    {
        $causes = [
            UrssafAuthenticationFailed::withStatus(401),
            UrssafRequestRejected::withErrors(Operation::FindAccount, 400, ApiErrors::none(), RejectionKind::InvalidParameter),
            RecordNotFound::withIdentifier('ae_account', 'account-1'),
            InvalidValue::empty('tenant_id'),
            OutcomeUnknown::fromUnavailable(UrssafUnavailable::dueToTransportError(Operation::Declare, new LogicException('lost'))),
        ];

        foreach ($causes as $cause) {
            $failure = self::caught(static function () use ($cause): never {
                throw $cause;
            });

            static::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
            static::assertSame($cause, $failure->getPrevious());
            static::assertSame($cause->getMessage(), $failure->getMessage());
            static::assertSame(0, $failure->getCode());
        }
    }

    public function testFailuresOfOtherOriginsAreLeftUntouchedAndResultsAreReturned(): void
    {
        $original = new RuntimeException('database down');

        $failure = self::caught(static function () use ($original): never {
            throw $original;
        });

        static::assertSame($original, $failure);
        static::assertSame('done', MessageFailures::guard(static fn(): string => 'done'));
    }

    public function testABusyOperationIsRecoverableLater(): void
    {
        $failure = MessageFailures::busy('urssaf_tdae.op.tenant-1');

        static::assertSame('Operation already running (lock "urssaf_tdae.op.tenant-1" is held).', $failure->getMessage());
        static::assertSame(0, $failure->getCode());
        static::assertNull($failure->getPrevious());
        RetryDelay::assertCarries(30_000, $failure);
    }

    /**
     * @param callable(): mixed $operation
     * @param-immediately-invoked-callable $operation
     */
    private static function caught(callable $operation): Throwable
    {
        try {
            MessageFailures::guard($operation);
        } catch (Throwable $failure) {
            return $failure;
        }

        static::fail('Nothing was thrown.');
    }
}
