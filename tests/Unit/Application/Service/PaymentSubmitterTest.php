<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\SubmissionErrorView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use Throwable;

/**
 * The submitter alone: the claim, the call (never resent, 060), and the classification of every failure into the
 * settlement of ADR 0009.
 *
 * @mago-expect lint:too-many-methods
 */
final class PaymentSubmitterTest extends TestCase
{
    public function testALostClaimIsNotClaimedAndMakesNoCall(): void
    {
        $world = $this->worldWithPendingRecord();
        $world->payments->refuseClaim(PaymentRecordId::fromString('record-1'));

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('record-1', $submission->payment->recordId);
        static::assertSame('pending', $submission->payment->status->state);
        static::assertSame([], $world->journal->entries());
    }

    public function testAnAcceptedAnswerIsRecordedAsPaid(): void
    {
        $world = $this->worldWithPendingRecord();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('paid', $submission->payment->status->state);
        static::assertSame(1, $submission->payment->status->attempts);
        static::assertSame('12345678', $submission->payment->status->result?->certificat);
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $submission->payment->status->result->idTelepaiement);
        static::assertFalse($submission->payment->status->result->resolved);
        static::assertSame([PaymentWorld::SIRET], array_map(static fn(Siret $siret): string => $siret->toString(), $world->gateway->sirets()));
        static::assertSame(['PaymentOrdered'], $world->publisher->names());
    }

    /**
     * @return iterable<string, array{string, ItemOutcome, string, list<array{string, string}>}>
     */
    public static function returnedFailures(): iterable
    {
        yield 'rejected with errors' => ['rejected', ItemOutcome::Rejected, 'rejected', [['ER_API_060_0070', 'invalid_bank_details'], ['ER_API_060_0071', 'invalid_bank_details']]];
        yield 'rejected without errors' => ['rejected_empty', ItemOutcome::Rejected, 'rejected', [['urssaf.rejected', 'unknown']]];
        yield 'unauthenticated' => ['unauthenticated', ItemOutcome::InvalidCredentials, 'invalid_credentials', []];
        yield 'forbidden' => ['forbidden', ItemOutcome::InvalidCredentials, 'invalid_credentials', []];
        yield 'unknown outcome' => ['unknown', ItemOutcome::OutcomeUnknown, 'outcome_unknown', [['urssaf.outcome_unknown', 'technical']]];
        yield 'unknown outcome with errors' => ['unknown_with_errors', ItemOutcome::OutcomeUnknown, 'outcome_unknown', [['ER_API_060_0500', 'technical']]];
        yield 'may have reached URSSAF (never resent)' => ['reached', ItemOutcome::OutcomeUnknown, 'outcome_unknown', [['urssaf.unavailable', 'technical']]];
        yield 'transport error (never resent)' => ['transport', ItemOutcome::OutcomeUnknown, 'outcome_unknown', [['urssaf.unavailable', 'technical']]];
    }

    /**
     * @param list<array{string, string}> $errors code and kind of the stored errors
     */
    #[DataProvider('returnedFailures')]
    public function testAFailureThatSettlesTheClaimIsReturned(string $failure, ItemOutcome $outcome, string $state, array $errors): void
    {
        $world = $this->worldWithPendingRecord();
        $world->gateway->queue(Operation::Pay, self::failure($failure));

        $submission = $this->submit($world);

        static::assertSame($outcome, $submission->outcome);
        static::assertSame($state, $submission->payment->status->state);
        static::assertSame($errors, array_map(static fn(SubmissionErrorView $error): array => [$error->code, $error->kind], $submission->payment->status->errors));
        static::assertSame(['060'], $world->gateway->calls());
        static::assertSame($outcome === ItemOutcome::OutcomeUnknown ? 'may_have_reached_urssaf' : null, $submission->payment->status->outcomeUnknownReason);
    }

    public function testARejectionKeepsTheUrssafMessageAndDescription(): void
    {
        $world = $this->worldWithPendingRecord();
        $world->gateway->queue(Operation::Pay, UrssafRequestRejected::withErrors(
            Operation::Pay,
            400,
            ApiErrors::of(ApiError::of('ER_API_060_0070', 'Message', 'Description')),
            RejectionKind::InvalidBankDetails,
        ));

        $error = $this->submit($world)->payment->status->errors[0];

        static::assertSame('Message', $error->message);
        static::assertSame('Description', $error->description);
        static::assertSame(['code' => 'ER_API_060_0070', 'kind' => 'invalid_bank_details', 'message' => '***', 'description' => '***'], $error->__debugInfo());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function retryableFailures(): iterable
    {
        yield 'throttled (429)' => ['throttled', 'urssaf.unavailable', UrssafUnavailable::class];
        yield 'token unavailable' => ['token', 'urssaf.unavailable', UrssafUnavailable::class];
        yield 'local rate limit' => ['rate_limit', 'urssaf.unavailable', RateLimitExceeded::class];
    }

    #[DataProvider('retryableFailures')]
    public function testAFailureProvenNotSentIsRetryableAndTheRecordIsPendingAgain(string $name, string $code, string $class): void
    {
        $world = $this->worldWithPendingRecord();
        $failure = self::failure($name);
        $world->gateway->queue(Operation::Pay, $failure);

        try {
            $this->submit($world);
            static::fail('Expected RetryableUrssafFailure.');
        } catch (RetryableUrssafFailure $retry) {
            static::assertSame($failure, $retry->getPrevious());
        }
        $stored = $world->payments->get(PaymentRecordId::fromString('record-1'));
        static::assertSame(PaymentState::Pending, $stored->state());
        static::assertSame(1, $stored->attempts());
        static::assertSame($code, $stored->errors()[0]->code());
        static::assertSame('technical', $stored->errors()[0]->kind());
        static::assertSame($class, $stored->errors()[0]->message());
        static::assertSame(['PaymentRequeued'], \array_slice($world->publisher->names(), -1));
    }

    public function testTheErrorsOfAThrottledAnswerAreKept(): void
    {
        $world = $this->worldWithPendingRecord();
        $world->gateway->queue(Operation::Pay, self::failure('throttled_with_errors'));

        try {
            $this->submit($world);
            static::fail('Expected RetryableUrssafFailure.');
        } catch (RetryableUrssafFailure) {
            $error = $world->payments->get(PaymentRecordId::fromString('record-1'))->errors()[0];
            static::assertSame('ER_API_060_0429', $error->code());
            static::assertSame('technical', $error->kind());
        }
    }

    public function testTheDelayOfARetryableFailureIsReadFromThePreviousException(): void
    {
        $world = $this->worldWithPendingRecord();
        $world->gateway->queue(Operation::Pay, UrssafUnavailable::withStatus(Operation::Pay, 429, 30, ApiErrors::none()));

        try {
            $this->submit($world);
            static::fail('Expected RetryableUrssafFailure.');
        } catch (RetryableUrssafFailure $retry) {
            $previous = $retry->getPrevious();
            static::assertInstanceOf(UrssafUnavailable::class, $previous);
            static::assertSame(30, $previous->retryAfterSeconds());
            static::assertSame('URSSAF is temporarily unavailable: the submission must be retried later.', $retry->getMessage());
        }
    }

    public function testTheAttemptsAreBoundedByThePolicy(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(2));
        $world->paymentIn('record-1', PaymentState::Pending, 0);
        $world->gateway->queue(Operation::Pay, RateLimitExceeded::forOperation(Operation::Pay, 5), RateLimitExceeded::forOperation(Operation::Pay, 5));

        try {
            $this->submit($world);
            static::fail('Expected RetryableUrssafFailure.');
        } catch (RetryableUrssafFailure) {
            static::assertSame(PaymentState::Pending, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
        }
        $second = $this->submit($world);

        static::assertSame(ItemOutcome::Exhausted, $second->outcome);
        static::assertSame('error', $second->payment->status->state);
        static::assertSame(2, $second->payment->status->attempts);
    }

    public function testASingleAttemptPolicyExhaustsAtOnce(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(1));
        $world->paymentIn('record-1', PaymentState::Pending, 0);
        $world->gateway->queue(Operation::Pay, RateLimitExceeded::forOperation(Operation::Pay, 5));

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::Exhausted, $submission->outcome);
        static::assertSame('error', $submission->payment->status->state);
        static::assertSame(['PaymentSubmissionFailed'], \array_slice($world->publisher->names(), -1));
    }

    public function testAMissingCredentialsRefusalRequeuesThenIsRethrown(): void
    {
        $world = $this->worldWithPendingRecord();
        $refusal = RecordNotFound::withIdentifier('credentials', 'tenant-1');
        $world->gateway->queue(Operation::Pay, $refusal);

        try {
            $this->submit($world);
            static::fail('Expected the refusal.');
        } catch (RecordNotFound $thrown) {
            static::assertSame($refusal, $thrown);
        }
        $stored = $world->payments->get(PaymentRecordId::fromString('record-1'));
        static::assertSame(PaymentState::Pending, $stored->state());
        static::assertSame('credentials.not_found', $stored->errors()[0]->code());
        static::assertSame('unknown', $stored->errors()[0]->kind());
        static::assertSame(RecordNotFound::class, $stored->errors()[0]->message());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unexpectedFailures(): iterable
    {
        yield 'foreign' => ['foreign', 'urssaf.unexpected_failure'];
        yield 'another domain exception' => ['domain', 'payment.other'];
        yield 'another record not found' => ['not_found', 'mandate.not_found'];
    }

    #[DataProvider('unexpectedFailures')]
    public function testAnUnexpectedFailureIsAnUnknownOutcomeThenRethrownAndNeverStoredByMessage(string $name, string $code): void
    {
        $world = $this->worldWithPendingRecord();
        $failure = self::failure($name);
        $world->gateway->queue(Operation::Pay, $failure);

        try {
            $this->submit($world);
            static::fail('Expected the failure.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        $stored = $world->payments->get(PaymentRecordId::fromString('record-1'));
        static::assertSame(PaymentState::OutcomeUnknown, $stored->state());
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $stored->outcomeUnknownReason());
        static::assertSame($code, $stored->errors()[0]->code());
        static::assertSame('unknown', $stored->errors()[0]->kind());
        static::assertSame($failure::class, $stored->errors()[0]->message());
        static::assertStringNotContainsString('FR76', serialize($stored->errors()));
        static::assertSame(['060'], $world->gateway->calls());
    }

    public function testAFailureOnARecordTheClaimNoLongerOwnsIsNeverRethrown(): void
    {
        $world = $this->worldWithPendingRecord();
        $world->gateway->queue(Operation::Pay, new RuntimeException('foreign'));
        $world->gateway->duringNextCall(static function () use ($world): void {
            $other = $world->payments->inner()->get(PaymentRecordId::fromString('record-1'));
            $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], Instant::fromIso8601(PaymentWorld::NOW));
            $world->payments->inner()->save($other);
        });

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::Replayed, $submission->outcome);
        static::assertSame('outcome_unknown', $submission->payment->status->state);
    }

    private static function failure(string $name): Throwable
    {
        return match ($name) {
            'rejected' => UrssafRequestRejected::withErrors(
                Operation::Pay,
                400,
                ApiErrors::of(ApiError::of('ER_API_060_0070', 'Message', 'Description'), ApiError::of('ER_API_060_0071', null, null)),
                RejectionKind::InvalidBankDetails,
            ),
            'rejected_empty' => UrssafRequestRejected::withErrors(Operation::Pay, 400, ApiErrors::none(), RejectionKind::Unknown),
            'unauthenticated' => UrssafAuthenticationFailed::withStatus(401),
            'forbidden' => UrssafAuthenticationFailed::withStatus(403),
            'unknown' => OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus(Operation::Pay, 500, null, ApiErrors::none())),
            'unknown_with_errors' => OutcomeUnknown::fromUnavailable(
                UrssafUnavailable::withStatus(Operation::Pay, 502, null, ApiErrors::of(ApiError::of('ER_API_060_0500', null, null))),
            ),
            'reached' => UrssafUnavailable::withStatus(Operation::Pay, 503, 30, ApiErrors::none()),
            'transport' => UrssafUnavailable::dueToTransportError(Operation::Pay, new RuntimeException('connection reset')),
            'throttled' => UrssafUnavailable::withStatus(Operation::Pay, 429, 30, ApiErrors::none()),
            'throttled_with_errors' => UrssafUnavailable::withStatus(Operation::Pay, 429, 30, ApiErrors::of(ApiError::of('ER_API_060_0429', null, null))),
            'token' => UrssafUnavailable::duringAuthentication(Operation::Pay, 503, null, null),
            'rate_limit' => RateLimitExceeded::forOperation(Operation::Pay, 5),
            'domain' => InvariantViolation::because('payment.other', 'Another.'),
            'not_found' => RecordNotFound::withIdentifier('mandate', 'x'),
            default => new RuntimeException('secret detail FR7630001007941234567890185'),
        };
    }

    private function worldWithPendingRecord(): PaymentWorld
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Pending, 0);

        return $world;
    }

    private function submit(PaymentWorld $world): PaymentSubmission
    {
        return $world->submitter()->submit(
            PaymentRecordId::fromString('record-1'),
            PaymentWorld::tenant(),
            PaymentWorld::siret(),
            PaymentWorld::order(),
        );
    }
}
