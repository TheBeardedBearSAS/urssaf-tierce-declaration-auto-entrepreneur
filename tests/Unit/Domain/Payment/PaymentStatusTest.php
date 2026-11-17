<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class PaymentStatusTest extends TestCase
{
    private static function pending(): PaymentStatus
    {
        return new PaymentStatus(PaymentState::Pending, 0, null, [], null, null, PaymentSamples::instant());
    }

    private static function submitting(): PaymentStatus
    {
        return self::pending()->submissionStarted(PaymentSamples::later(1));
    }

    private static function unknown(): PaymentStatus
    {
        return self::submitting()->outcomeUnknown(OutcomeUnknownReason::StaleSubmission, PaymentSamples::errors(), PaymentSamples::later(2));
    }

    public function testRejectsANegativeAttemptCount(): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'payment_record.negative_attempts',
            static fn(): PaymentStatus => new PaymentStatus(PaymentState::Pending, -1, null, [], null, null, PaymentSamples::instant()),
        );
        static::assertSame(['attempts' => -1], $exception->params());
    }

    public function testZeroAttemptsIsValid(): void
    {
        static::assertSame(0, self::pending()->attempts());
    }

    public function testAPaidStatusRequiresItsResult(): void
    {
        PaymentSamples::assertViolation(
            'payment_record.result_required',
            static fn(): PaymentStatus => new PaymentStatus(PaymentState::Paid, 1, null, [], null, null, PaymentSamples::instant()),
        );
        $paid = new PaymentStatus(PaymentState::Paid, 1, null, [], PaymentSamples::result(), null, PaymentSamples::instant());

        static::assertSame(PaymentState::Paid, $paid->state());
    }

    public function testAnUnknownOutcomeRequiresItsReason(): void
    {
        PaymentSamples::assertViolation(
            'payment_record.outcome_unknown_reason_required',
            static fn(): PaymentStatus => new PaymentStatus(PaymentState::OutcomeUnknown, 1, null, [], null, null, PaymentSamples::instant()),
        );
    }

    public function testSubmissionStartedCountsTheAttempt(): void
    {
        $status = self::submitting();

        static::assertSame(PaymentState::Submitting, $status->state());
        static::assertSame(1, $status->attempts());
        static::assertEquals(PaymentSamples::later(1), $status->lastAttemptAt());
        static::assertEquals(PaymentSamples::later(1), $status->updatedAt());
        static::assertNull($status->outcomeUnknownReason());
    }

    public function testSubmissionStartedKeepsErrorsAndResetsTheReason(): void
    {
        $requeued = self::unknown()->resolvedAsNotReceived(PaymentSamples::later(3));
        $again = $requeued->submissionStarted(PaymentSamples::later(4));

        static::assertSame(PaymentSamples::errors()[0]->code(), $again->errors()[0]->code());
        static::assertNull($again->outcomeUnknownReason());
        static::assertSame(2, $again->attempts());
    }

    public function testPaidStoresTheResultAndClearsTheErrors(): void
    {
        $failed = self::submitting()->failed(PaymentSamples::errors(), PaymentSamples::later(2))->retried(PaymentSamples::later(3));
        $paid = $failed->submissionStarted(PaymentSamples::later(4))->paid(PaymentSamples::result(), PaymentSamples::later(5));

        static::assertSame(PaymentState::Paid, $paid->state());
        static::assertSame([], $paid->errors());
        static::assertEquals(PaymentSamples::result(), $paid->result());
        static::assertEquals(PaymentSamples::later(4), $paid->lastAttemptAt());
        static::assertEquals(PaymentSamples::later(5), $paid->updatedAt());
        static::assertSame(2, $paid->attempts());
    }

    public function testPaidIsAlsoTheLateAcceptanceOfEveryStateButPaid(): void
    {
        $at = PaymentSamples::later(9);
        $states = [
            self::pending(),
            self::unknown(),
            self::submitting()->failed(PaymentSamples::errors(), PaymentSamples::later(2)),
            self::submitting()->credentialsRejected(PaymentSamples::later(2)),
            self::submitting()->rejected(PaymentSamples::errors(), PaymentSamples::later(2)),
        ];
        foreach ($states as $status) {
            $paid = $status->paid(PaymentSamples::result(), $at);

            static::assertSame(PaymentState::Paid, $paid->state());
            static::assertSame([], $paid->errors());
            static::assertNull($paid->outcomeUnknownReason());
            static::assertEquals(PaymentSamples::result(), $paid->result());
            static::assertSame($status->attempts(), $paid->attempts());
            static::assertEquals($at, $paid->updatedAt());
        }
    }

    public function testPaidKeepsTheFirstResult(): void
    {
        $paid = self::submitting()->paid(PaymentSamples::result(), PaymentSamples::later(2));

        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => $paid->paid(PaymentSamples::result(), PaymentSamples::later(3)), 'paid', 'paid');
    }

    public function testResolvedAsPaidStoresTheResultAndClearsTheErrors(): void
    {
        $resolved = self::unknown()->resolvedAsPaid(PaymentSamples::result(), PaymentSamples::later(3));

        static::assertSame(PaymentState::Paid, $resolved->state());
        static::assertSame([], $resolved->errors());
        static::assertEquals(PaymentSamples::result(), $resolved->result());
        static::assertNull($resolved->outcomeUnknownReason());
        static::assertSame(1, $resolved->attempts());
        static::assertEquals(PaymentSamples::later(1), $resolved->lastAttemptAt());
        static::assertEquals(PaymentSamples::later(3), $resolved->updatedAt());
    }

    public function testResolvedAsPaidIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => self::submitting()->resolvedAsPaid(PaymentSamples::result(), PaymentSamples::later(3)), 'submitting', 'paid');
    }

    public function testRejectedAndFailedStoreTheErrors(): void
    {
        $rejected = self::submitting()->rejected(PaymentSamples::errors(), PaymentSamples::later(2));
        $failed = self::submitting()->failed(PaymentSamples::errors(), PaymentSamples::later(2));

        static::assertSame(PaymentState::Rejected, $rejected->state());
        static::assertSame(PaymentState::Error, $failed->state());
        foreach ([$rejected, $failed] as $status) {
            static::assertEquals(PaymentSamples::errors(), $status->errors());
            static::assertSame(1, $status->attempts());
            static::assertEquals(PaymentSamples::later(1), $status->lastAttemptAt());
            static::assertEquals(PaymentSamples::later(2), $status->updatedAt());
            static::assertNull($status->result());
        }
    }

    public function testOutcomeUnknownStoresTheReasonAndTheErrors(): void
    {
        $status = self::unknown();

        static::assertSame(PaymentState::OutcomeUnknown, $status->state());
        static::assertSame(OutcomeUnknownReason::StaleSubmission, $status->outcomeUnknownReason());
        static::assertEquals(PaymentSamples::errors(), $status->errors());
        static::assertSame(1, $status->attempts());
        static::assertEquals(PaymentSamples::later(1), $status->lastAttemptAt());
        static::assertEquals(PaymentSamples::later(2), $status->updatedAt());
    }

    public function testCredentialsRejectedClearsTheErrors(): void
    {
        $status = self::submitting()->failed(PaymentSamples::errors(), PaymentSamples::later(2))->retried(PaymentSamples::later(3))
            ->submissionStarted(PaymentSamples::later(4))->credentialsRejected(PaymentSamples::later(5));

        static::assertSame(PaymentState::InvalidCredentials, $status->state());
        static::assertSame([], $status->errors());
    }

    public function testRetriedKeepsTheErrors(): void
    {
        $failed = self::submitting()->failed(PaymentSamples::errors(), PaymentSamples::later(2));
        $retried = $failed->retried(PaymentSamples::later(3));

        static::assertSame(PaymentState::Pending, $retried->state());
        static::assertEquals(PaymentSamples::errors(), $retried->errors());
        static::assertSame(1, $retried->attempts());
        static::assertEquals(PaymentSamples::later(3), $retried->updatedAt());
    }

    public function testRetriedNeverRequeuesAnUnknownOutcome(): void
    {
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => self::unknown()->retried(PaymentSamples::later(3)), 'outcome_unknown', 'pending');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => self::submitting()->retried(PaymentSamples::later(3)), 'submitting', 'pending');
    }

    public function testResolvedAsNotReceivedKeepsTheErrorsAndClearsTheReason(): void
    {
        $status = self::unknown()->resolvedAsNotReceived(PaymentSamples::later(3));

        static::assertSame(PaymentState::Pending, $status->state());
        static::assertEquals(PaymentSamples::errors(), $status->errors());
        static::assertNull($status->outcomeUnknownReason());
        static::assertSame(1, $status->attempts());
        static::assertEquals(PaymentSamples::later(3), $status->updatedAt());
    }

    public function testResolvedAsNotReceivedIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => self::submitting()->failed([], PaymentSamples::later(2))->resolvedAsNotReceived(PaymentSamples::later(3)), 'error', 'pending');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => self::submitting()->credentialsRejected(PaymentSamples::later(2))->resolvedAsNotReceived(PaymentSamples::later(3)), 'invalid_credentials', 'pending');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => self::submitting()->resolvedAsNotReceived(PaymentSamples::later(3)), 'submitting', 'pending');
    }

    public function testEveryTransitionFollowsTheStateMachine(): void
    {
        $pending = self::pending();
        $later = PaymentSamples::later(1);
        $errors = PaymentSamples::errors();
        $unknown = OutcomeUnknownReason::MayHaveReachedUrssaf;

        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => $pending->rejected($errors, $later), 'pending', 'rejected');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => $pending->failed($errors, $later), 'pending', 'error');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => $pending->outcomeUnknown($unknown, $errors, $later), 'pending', 'outcome_unknown');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => $pending->credentialsRejected($later), 'pending', 'invalid_credentials');
        PaymentSamples::assertInvalidTransition(static fn(): PaymentStatus => $pending->submissionStarted($later)->submissionStarted($later), 'submitting', 'submitting');
    }

    public function testHasAttemptsLeft(): void
    {
        $status = self::submitting();

        static::assertTrue($status->hasAttemptsLeft(2));
        static::assertFalse($status->hasAttemptsLeft(1));
        static::assertFalse($status->hasAttemptsLeft(0));
    }

    public function testLastAttemptStartedBefore(): void
    {
        $interval = new DateInterval('PT15M');
        $status = self::submitting();

        static::assertFalse(self::pending()->isLastAttemptStartedBefore(PaymentSamples::later(1000), $interval));
        static::assertFalse($status->isLastAttemptStartedBefore(PaymentSamples::later(15), $interval));
        static::assertTrue($status->isLastAttemptStartedBefore(PaymentSamples::later(16), $interval));
        static::assertTrue($status->isLastAttemptStartedBefore(PaymentSamples::later(17), $interval));
    }
}
