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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknownCause;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RejectingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\RetryMatrix;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use Throwable;

/**
 * The cross-operation failure matrix (ADR 0002, ADR 0003), end to end over the nine operations. The expectation comes
 * from the retry class of the operation, never from a per-operation table.
 *
 * @mago-expect lint:too-many-methods
 */
final class UrssafHttpGatewayFailureTest extends TestCase
{
    private const RETRY_AFTER = ['Retry-After' => '30'];

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideOperations(): iterable
    {
        foreach (Operation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }

    /**
     * Every operation, with the five unspecified writes also declared safe to retry.
     *
     * @return iterable<string, array{Operation, ApiConfiguration, string}>
     */
    public static function provideRetryColumns(): iterable
    {
        yield from RetryMatrix::columns();
    }

    #[DataProvider('provideOperations')]
    public function test401ThenTheOfficialAnswerSucceedsAfterTheReplay(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(401, '');
        $stack->respondToken();
        Gateways::respondSuccess($stack, $operation);

        $returnsNothing = Gateways::call(Gateways::gateway($stack), $operation) === null;

        $label = $operation->value;
        static::assertCount(4, $stack->requests());
        static::assertSame(["tenant-1:{$label}", "tenant-1:{$label}"], $stack->rateLimiter->acquired());
        static::assertSame(
            \in_array($operation, [Operation::NotifyMandate, Operation::CancelMandate, Operation::RevokeSepaMandate], true),
            $returnsNothing,
        );
    }

    #[DataProvider('provideOperations')]
    public function test401TwiceIsAnAuthenticationFailure(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(401, '');
        $stack->respondToken();
        $stack->respond(401, '');

        $exception = $this->failure($stack, $operation);

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(401, $exception->httpStatus());
        static::assertCount(4, $stack->requests());
    }

    #[DataProvider('provideOperations')]
    public function test403IsAnAuthenticationFailureWithoutReplay(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(403, '');

        $exception = $this->failure($stack, $operation);

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(403, $exception->httpStatus());
        static::assertCount(2, $stack->requests());
    }

    #[DataProvider('provideOperations')]
    public function test429IsRetryableOnEveryClass(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(429, '', self::RETRY_AFTER);
        $stack->respond(429, '');
        $gateway = Gateways::gateway($stack);

        $withHeader = $this->failureOf($gateway, $operation);
        $withoutHeader = $this->failureOf($gateway, $operation);

        static::assertInstanceOf(UrssafUnavailable::class, $withHeader);
        static::assertTrue($withHeader->isQuotaExceeded());
        static::assertFalse($withHeader->mayHaveReachedUrssaf());
        static::assertSame(30, $withHeader->retryAfterSeconds());
        static::assertInstanceOf(UrssafUnavailable::class, $withoutHeader);
        static::assertNull($withoutHeader->retryAfterSeconds());
    }

    #[DataProvider('provideRetryColumns')]
    public function testATransportErrorFollowsTheRetryClass(Operation $operation, ApiConfiguration $configuration, string $expectation): void
    {
        $stack = Gateways::warmWith($configuration);
        $stack->fail(new TransportFailure('reset'));

        $exception = $this->failure($stack, $operation);

        if ($expectation === RetryMatrix::RETRYABLE) {
            static::assertInstanceOf(UrssafUnavailable::class, $exception);
            static::assertTrue($exception->mayHaveReachedUrssaf());
            static::assertNull($exception->httpStatus());

            return;
        }

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(OutcomeUnknownCause::Transport, $exception->cause());
        static::assertNull($exception->httpStatus());
    }

    #[DataProvider('provideRetryColumns')]
    public function testA500AndA503FollowTheRetryClassAndKeepTheErrors(Operation $operation, ApiConfiguration $configuration, string $expectation): void
    {
        $stack = Gateways::warmWith($configuration);
        $stack->respond(500, '');
        $stack->respond(503, Fixtures::load('error_technique'));
        $gateway = Gateways::gateway($stack);

        $withoutBody = $this->failureOf($gateway, $operation);
        $withErrors = $this->failureOf($gateway, $operation);

        foreach ([[$withoutBody, 500, []], [$withErrors, 503, ['ERR_TECHNIQUE']]] as [$exception, $status, $codes]) {
            if ($expectation === RetryMatrix::RETRYABLE) {
                static::assertInstanceOf(UrssafUnavailable::class, $exception);
                static::assertTrue($exception->mayHaveReachedUrssaf());
                static::assertSame($status, $exception->httpStatus());
                static::assertSame($codes, $exception->errors()->codes());

                continue;
            }

            static::assertInstanceOf(OutcomeUnknown::class, $exception);
            static::assertSame(OutcomeUnknownCause::ServerError, $exception->cause());
            static::assertSame($status, $exception->httpStatus());
            static::assertSame($codes, $exception->errors()->codes());
        }
    }

    #[DataProvider('provideRetryColumns')]
    public function testA501IsUnexpectedWhenRetryableAndAnUnknownOutcomeOtherwise(Operation $operation, ApiConfiguration $configuration, string $expectation): void
    {
        $stack = Gateways::warmWith($configuration);
        $stack->respond(501, '');

        $exception = $this->failure($stack, $operation);

        if ($expectation === RetryMatrix::RETRYABLE) {
            static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);

            return;
        }

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(OutcomeUnknownCause::ServerError, $exception->cause());
        static::assertSame(501, $exception->httpStatus());
    }

    #[DataProvider('provideRetryColumns')]
    public function testAnUnreadable2xxIsUnexpectedOnlyOnASafeOperation(Operation $operation, ApiConfiguration $configuration): void
    {
        $stack = Gateways::warmWith($configuration);
        $stack->respond(200, 'not json');

        $exception = $this->failure($stack, $operation);

        if ($operation->retryClass() === OperationRetryClass::Safe) {
            static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);

            return;
        }

        // 030 and 031 read "not json" as a body that is neither blank nor {}: the same unreadable answer.
        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(OutcomeUnknownCause::UnreadableResponse, $exception->cause());
        static::assertSame(200, $exception->httpStatus());
    }

    #[DataProvider('provideOperations')]
    public function test400WithoutABodyIsRejectedAsUnknown(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, '');

        $exception = $this->failure($stack, $operation);

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame(RejectionKind::Unknown, $exception->kind());
        static::assertSame([], $exception->errors()->codes());
    }

    #[DataProvider('provideOperations')]
    public function test400WithAnErrorEnvelopeOrAListIsClassified(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, '{"errors":[{"code":"PARAM_INVALIDE"}]}');
        $stack->respond(400, '[{"code":"CPTE_INCONNU"}]');
        $gateway = Gateways::gateway($stack);

        $envelope = $this->failureOf($gateway, $operation);
        $list = $this->failureOf($gateway, $operation);

        static::assertInstanceOf(UrssafRequestRejected::class, $envelope);
        static::assertSame(RejectionKind::InvalidParameter, $envelope->kind());
        static::assertInstanceOf(UrssafRequestRejected::class, $list);
        static::assertSame(RejectionKind::AccountUnknown, $list->kind());
    }

    #[DataProvider('provideOperations')]
    public function testATokenServerErrorIsNeverAnUnknownOutcome(Operation $operation): void
    {
        $stack = Stacks::sandbox();
        $stack->respond(503, '');

        $exception = $this->failure($stack, $operation);

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertTrue($exception->whileAuthenticating());
        static::assertFalse($exception->mayHaveReachedUrssaf());
    }

    #[DataProvider('provideOperations')]
    public function testATokenRefusalIsAnAuthenticationFailure(Operation $operation): void
    {
        $stack = Stacks::sandbox();
        $stack->respond(401, '');

        $exception = $this->failure($stack, $operation);

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(401, $exception->httpStatus());
    }

    #[DataProvider('provideOperations')]
    public function testTheLocalLimiterRefusesWithoutAnyRequest(Operation $operation): void
    {
        $stack = Stacks::sandbox();

        try {
            Gateways::call(Gateways::withRateLimiter($stack, new RejectingRateLimiter()), $operation);
            static::fail('Expected a rate limit refusal.');
        } catch (RateLimitExceeded $exception) {
            static::assertSame($operation, $exception->operation());
            static::assertSame([], $stack->requests());
        }
    }

    #[DataProvider('provideOperations')]
    public function test418IsUnexpectedOnEveryClass(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(418, '');

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $this->failure($stack, $operation));
    }

    #[DataProvider('provideOperations')]
    public function test404IsNotFoundOnTheSepaOperationsOnly(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(404, '');

        $exception = $this->failure($stack, $operation);

        if ($operation->isSepa()) {
            static::assertInstanceOf(UrssafRequestRejected::class, $exception);
            static::assertSame(RejectionKind::NotFound, $exception->kind());

            return;
        }

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
    }

    #[DataProvider('provideOperations')]
    public function test409MeansAnExistingMandateOnRegistrationOnly(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(409, '');

        $exception = $this->failure($stack, $operation);

        if ($operation === Operation::RegisterSepaMandate) {
            static::assertInstanceOf(UrssafRequestRejected::class, $exception);
            static::assertSame(RejectionKind::SepaMandateAlreadyExists, $exception->kind());

            return;
        }

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
    }

    private function failure(Stack $stack, Operation $operation): Throwable
    {
        return $this->failureOf(Gateways::gateway($stack), $operation);
    }

    private function failureOf(UrssafHttpGateway $gateway, Operation $operation): Throwable
    {
        try {
            Gateways::call($gateway, $operation);
        } catch (Throwable $exception) {
            return $exception;
        }

        static::fail('Expected a failure.');
    }
}
