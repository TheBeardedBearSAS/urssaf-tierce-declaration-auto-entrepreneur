<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class DeclarationStatusTest extends TestCase
{
    private static function pending(): DeclarationStatus
    {
        return new DeclarationStatus(DeclarationState::Pending, 0, null, [], null, null, DeclarationSamples::instant());
    }

    private static function submitting(): DeclarationStatus
    {
        return self::pending()->submissionStarted(DeclarationSamples::later(1));
    }

    private static function unknown(): DeclarationStatus
    {
        return self::submitting()->outcomeUnknown(OutcomeUnknownReason::StaleSubmission, DeclarationSamples::errors(), DeclarationSamples::later(2));
    }

    public function testRejectsANegativeAttemptCount(): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'declaration_record.negative_attempts',
            static fn(): DeclarationStatus => new DeclarationStatus(DeclarationState::Pending, -1, null, [], null, null, DeclarationSamples::instant()),
        );
        static::assertSame(['attempts' => -1], $exception->params());
    }

    public function testZeroAttemptsIsValid(): void
    {
        static::assertSame(0, self::pending()->attempts());
    }

    public function testADeclaredStatusRequiresItsResult(): void
    {
        DeclarationSamples::assertViolation(
            'declaration_record.result_required',
            static fn(): DeclarationStatus => new DeclarationStatus(DeclarationState::Declared, 1, null, [], null, null, DeclarationSamples::instant()),
        );
        $declared = new DeclarationStatus(DeclarationState::Declared, 1, null, [], DeclarationSamples::result(), null, DeclarationSamples::instant());

        static::assertSame(DeclarationState::Declared, $declared->state());
    }

    public function testAnUnknownOutcomeRequiresItsReason(): void
    {
        DeclarationSamples::assertViolation(
            'declaration_record.outcome_unknown_reason_required',
            static fn(): DeclarationStatus => new DeclarationStatus(DeclarationState::OutcomeUnknown, 1, null, [], null, null, DeclarationSamples::instant()),
        );
    }

    public function testSubmissionStartedCountsTheAttempt(): void
    {
        $status = self::submitting();

        static::assertSame(DeclarationState::Submitting, $status->state());
        static::assertSame(1, $status->attempts());
        static::assertEquals(DeclarationSamples::later(1), $status->lastAttemptAt());
        static::assertEquals(DeclarationSamples::later(1), $status->updatedAt());
        static::assertNull($status->outcomeUnknownReason());
    }

    public function testSubmissionStartedKeepsErrorsAndResetsTheReason(): void
    {
        $requeued = self::unknown()->resolvedAsNotReceived(DeclarationSamples::later(3));
        $again = $requeued->submissionStarted(DeclarationSamples::later(4));

        static::assertSame(DeclarationSamples::errors()[0]->code(), $again->errors()[0]->code());
        static::assertNull($again->outcomeUnknownReason());
        static::assertSame(2, $again->attempts());
    }

    public function testDeclaredStoresTheResultAndClearsTheErrors(): void
    {
        $failed = self::submitting()->failed(DeclarationSamples::errors(), DeclarationSamples::later(2))->retried(DeclarationSamples::later(3));
        $declared = $failed->submissionStarted(DeclarationSamples::later(4))->declared(DeclarationSamples::result(), DeclarationSamples::later(5));

        static::assertSame(DeclarationState::Declared, $declared->state());
        static::assertSame([], $declared->errors());
        static::assertEquals(DeclarationSamples::result(), $declared->result());
        static::assertEquals(DeclarationSamples::later(4), $declared->lastAttemptAt());
        static::assertEquals(DeclarationSamples::later(5), $declared->updatedAt());
        static::assertSame(2, $declared->attempts());
    }

    public function testDeclaredIsAlsoTheLateAcceptanceOfEveryStateButDeclared(): void
    {
        $at = DeclarationSamples::later(9);
        $states = [
            self::pending(),
            self::unknown(),
            self::submitting()->failed(DeclarationSamples::errors(), DeclarationSamples::later(2)),
            self::submitting()->credentialsRejected(DeclarationSamples::later(2)),
            self::submitting()->rejected(DeclarationSamples::errors(), DeclarationSamples::later(2)),
        ];
        foreach ($states as $status) {
            $declared = $status->declared(DeclarationSamples::result(), $at);

            static::assertSame(DeclarationState::Declared, $declared->state());
            static::assertSame([], $declared->errors());
            static::assertNull($declared->outcomeUnknownReason());
            static::assertEquals(DeclarationSamples::result(), $declared->result());
            static::assertSame($status->attempts(), $declared->attempts());
            static::assertEquals($at, $declared->updatedAt());
        }
    }

    public function testDeclaredKeepsTheFirstResult(): void
    {
        $declared = self::submitting()->declared(DeclarationSamples::result(), DeclarationSamples::later(2));

        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => $declared->declared(DeclarationSamples::result(), DeclarationSamples::later(3)), 'declared', 'declared');
    }

    public function testResolvedAsDeclaredStoresTheResultAndClearsTheErrors(): void
    {
        $resolved = self::unknown()->resolvedAsDeclared(DeclarationSamples::result(), DeclarationSamples::later(3));

        static::assertSame(DeclarationState::Declared, $resolved->state());
        static::assertSame([], $resolved->errors());
        static::assertEquals(DeclarationSamples::result(), $resolved->result());
        static::assertNull($resolved->outcomeUnknownReason());
        static::assertSame(1, $resolved->attempts());
        static::assertEquals(DeclarationSamples::later(1), $resolved->lastAttemptAt());
        static::assertEquals(DeclarationSamples::later(3), $resolved->updatedAt());
    }

    public function testResolvedAsDeclaredIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => self::submitting()->resolvedAsDeclared(DeclarationSamples::result(), DeclarationSamples::later(3)), 'submitting', 'declared');
    }

    public function testRejectedAndFailedStoreTheErrors(): void
    {
        $rejected = self::submitting()->rejected(DeclarationSamples::errors(), DeclarationSamples::later(2));
        $failed = self::submitting()->failed(DeclarationSamples::errors(), DeclarationSamples::later(2));

        static::assertSame(DeclarationState::Rejected, $rejected->state());
        static::assertSame(DeclarationState::Error, $failed->state());
        foreach ([$rejected, $failed] as $status) {
            static::assertEquals(DeclarationSamples::errors(), $status->errors());
            static::assertSame(1, $status->attempts());
            static::assertEquals(DeclarationSamples::later(1), $status->lastAttemptAt());
            static::assertEquals(DeclarationSamples::later(2), $status->updatedAt());
            static::assertNull($status->result());
        }
    }

    public function testOutcomeUnknownStoresTheReasonAndTheErrors(): void
    {
        $status = self::unknown();

        static::assertSame(DeclarationState::OutcomeUnknown, $status->state());
        static::assertSame(OutcomeUnknownReason::StaleSubmission, $status->outcomeUnknownReason());
        static::assertEquals(DeclarationSamples::errors(), $status->errors());
        static::assertSame(1, $status->attempts());
        static::assertEquals(DeclarationSamples::later(1), $status->lastAttemptAt());
        static::assertEquals(DeclarationSamples::later(2), $status->updatedAt());
    }

    public function testCredentialsRejectedClearsTheErrors(): void
    {
        $status = self::submitting()->failed(DeclarationSamples::errors(), DeclarationSamples::later(2))->retried(DeclarationSamples::later(3))
            ->submissionStarted(DeclarationSamples::later(4))->credentialsRejected(DeclarationSamples::later(5));

        static::assertSame(DeclarationState::InvalidCredentials, $status->state());
        static::assertSame([], $status->errors());
    }

    public function testRetriedKeepsTheErrors(): void
    {
        $failed = self::submitting()->failed(DeclarationSamples::errors(), DeclarationSamples::later(2));
        $retried = $failed->retried(DeclarationSamples::later(3));

        static::assertSame(DeclarationState::Pending, $retried->state());
        static::assertEquals(DeclarationSamples::errors(), $retried->errors());
        static::assertSame(1, $retried->attempts());
        static::assertEquals(DeclarationSamples::later(3), $retried->updatedAt());
    }

    public function testRetriedNeverRequeuesAnUnknownOutcome(): void
    {
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => self::unknown()->retried(DeclarationSamples::later(3)), 'outcome_unknown', 'pending');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => self::submitting()->retried(DeclarationSamples::later(3)), 'submitting', 'pending');
    }

    public function testResolvedAsNotReceivedKeepsTheErrorsAndClearsTheReason(): void
    {
        $status = self::unknown()->resolvedAsNotReceived(DeclarationSamples::later(3));

        static::assertSame(DeclarationState::Pending, $status->state());
        static::assertEquals(DeclarationSamples::errors(), $status->errors());
        static::assertNull($status->outcomeUnknownReason());
        static::assertSame(1, $status->attempts());
        static::assertEquals(DeclarationSamples::later(3), $status->updatedAt());
    }

    public function testResolvedAsNotReceivedIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => self::submitting()->failed([], DeclarationSamples::later(2))->resolvedAsNotReceived(DeclarationSamples::later(3)), 'error', 'pending');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => self::submitting()->credentialsRejected(DeclarationSamples::later(2))->resolvedAsNotReceived(DeclarationSamples::later(3)), 'invalid_credentials', 'pending');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => self::submitting()->resolvedAsNotReceived(DeclarationSamples::later(3)), 'submitting', 'pending');
    }

    public function testEveryTransitionFollowsTheStateMachine(): void
    {
        $pending = self::pending();
        $later = DeclarationSamples::later(1);
        $errors = DeclarationSamples::errors();
        $unknown = OutcomeUnknownReason::MayHaveReachedUrssaf;

        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => $pending->rejected($errors, $later), 'pending', 'rejected');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => $pending->failed($errors, $later), 'pending', 'error');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => $pending->outcomeUnknown($unknown, $errors, $later), 'pending', 'outcome_unknown');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => $pending->credentialsRejected($later), 'pending', 'invalid_credentials');
        DeclarationSamples::assertInvalidTransition(static fn(): DeclarationStatus => $pending->submissionStarted($later)->submissionStarted($later), 'submitting', 'submitting');
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

        static::assertFalse(self::pending()->isLastAttemptStartedBefore(DeclarationSamples::later(1000), $interval));
        static::assertFalse($status->isLastAttemptStartedBefore(DeclarationSamples::later(15), $interval));
        static::assertTrue($status->isLastAttemptStartedBefore(DeclarationSamples::later(16), $interval));
        static::assertTrue($status->isLastAttemptStartedBefore(DeclarationSamples::later(17), $interval));
    }
}
