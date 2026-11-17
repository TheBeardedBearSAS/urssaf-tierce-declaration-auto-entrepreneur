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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;

/**
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class DeclarationRecordTest extends TestCase
{
    private static function submitting(): DeclarationRecord
    {
        $record = DeclarationSamples::record(null);
        $record->startSubmission(DeclarationSamples::later(1));
        $record->releaseEvents();

        return $record;
    }

    private static function failed(): DeclarationRecord
    {
        $record = self::submitting();
        $record->markFailed(DeclarationSamples::errors(), DeclarationSamples::later(2));
        $record->releaseEvents();

        return $record;
    }

    private static function unknown(): DeclarationRecord
    {
        $record = self::submitting();
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, DeclarationSamples::errors(), DeclarationSamples::later(2));
        $record->releaseEvents();

        return $record;
    }

    private static function exhausted(int $attempts): DeclarationRecord
    {
        $record = DeclarationSamples::record(null);
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $record->startSubmission(DeclarationSamples::later($attempt * 10));
            $record->markFailed([], DeclarationSamples::later($attempt * 10 + 1));
            if ($attempt < $attempts) {
                $record->retry(DeclarationSamples::later($attempt * 10 + 2), 100);
            }
        }
        $record->releaseEvents();

        return $record;
    }

    public function testRecord(): void
    {
        $record = DeclarationRecord::record(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::siret(),
            DeclarationSamples::period(),
            DeclarationSamples::fingerprint(),
            DeclarationSamples::accountId(),
            DeclarationSamples::instant(),
        );

        static::assertSame('record-1', $record->id()->value());
        static::assertSame('tenant-1', $record->tenantId()->value());
        static::assertSame('12345678901234', $record->siret()->toString());
        static::assertSame('2026-10', $record->period()->toString());
        static::assertSame(Periodicity::Monthly, $record->periodicity());
        static::assertTrue($record->fingerprint()->equals(DeclarationSamples::fingerprint()));
        static::assertSame('account-1', $record->accountId()?->value());
        static::assertNull($record->supersedes());
        static::assertNull($record->supersededBy());
        static::assertTrue($record->isCurrent());
        static::assertFalse($record->isAcceptedAfterSupersession());
        static::assertEquals(DeclarationSamples::instant(), $record->createdAt());
        static::assertSame(DeclarationState::Pending, $record->state());
        static::assertSame(DeclarationState::Pending, $record->status()->state());
        static::assertSame(0, $record->attempts());
        static::assertNull($record->lastAttemptAt());
        static::assertSame([], $record->errors());
        static::assertNull($record->result());
        static::assertNull($record->outcomeUnknownReason());
        static::assertEquals(DeclarationSamples::instant(), $record->updatedAt());

        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationRecorded::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertNull($event->supersedes());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
        static::assertSame([], $record->releaseEvents());
    }

    public function testTheAccountIsOptional(): void
    {
        $record = DeclarationSamples::record(null);

        static::assertNull($record->accountId());
    }

    public function testAQuarterlyPeriodIsStoredAsPartsAndRebuilt(): void
    {
        $record = DeclarationRecord::record(
            DeclarationSamples::recordId('record-q'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::siret(),
            QuarterlyPeriod::of(2026, 3),
            DeclarationSamples::fingerprint(),
            null,
            DeclarationSamples::instant(),
        );

        static::assertSame(Periodicity::Quarterly, $record->periodicity());
        static::assertInstanceOf(QuarterlyPeriod::class, $record->period());
        static::assertSame('2026-Q3', $record->period()->toString());
        $events = $record->releaseEvents();
        static::assertInstanceOf(DeclarationRecorded::class, $events[0]);
        static::assertSame('2026-Q3', $events[0]->period()->toString());
    }

    public function testAMonthlyPeriodIsRebuilt(): void
    {
        $period = DeclarationSamples::record(null)->period();

        static::assertInstanceOf(MonthlyPeriod::class, $period);
        static::assertSame(2026, $period->year());
        static::assertSame(10, $period->month());
    }

    public function testHasSameFingerprintAs(): void
    {
        $record = DeclarationSamples::record(null);

        static::assertTrue($record->hasSameFingerprintAs(DeclarationSamples::fingerprint()));
        static::assertFalse($record->hasSameFingerprintAs(DeclarationSamples::otherFingerprint()));
    }

    public function testStartSubmission(): void
    {
        $record = DeclarationSamples::record(null);

        $record->startSubmission(DeclarationSamples::later(1));

        static::assertSame(DeclarationState::Submitting, $record->state());
        static::assertSame(1, $record->attempts());
        static::assertEquals(DeclarationSamples::later(1), $record->lastAttemptAt());
        static::assertEquals(DeclarationSamples::later(1), $record->updatedAt());
        static::assertSame([], $record->releaseEvents());
    }

    public function testStartSubmissionIsRefusedWhenNotPending(): void
    {
        $record = self::submitting();

        DeclarationSamples::assertInvalidTransition(
            static fn() => $record->startSubmission(DeclarationSamples::later(3)),
            'submitting',
            'submitting',
        );
        static::assertSame(1, $record->attempts());
    }

    public function testMarkDeclared(): void
    {
        $record = DeclarationSamples::record(DeclarationSamples::accountId());
        $record->startSubmission(DeclarationSamples::later(1));
        $record->releaseEvents();

        $record->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(2));

        static::assertSame(DeclarationState::Declared, $record->state());
        static::assertEquals(DeclarationSamples::result(), $record->result());
        static::assertFalse($record->isAcceptedAfterSupersession());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationDeclared::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('12345678', $event->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $event->declarationId());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkDeclaredIsTheLateAcceptanceOfEveryStateButDeclared(): void
    {
        foreach ([DeclarationSamples::record(null), self::failed(), self::unknown(), self::submitting()] as $record) {
            $record->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(9));

            static::assertSame(DeclarationState::Declared, $record->state());
            static::assertSame([], $record->errors());
        }
    }

    public function testMarkDeclaredKeepsTheFirstResult(): void
    {
        $record = self::submitting();
        $record->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(2));
        $record->releaseEvents();

        DeclarationSamples::assertInvalidTransition(
            static fn() => $record->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(3)),
            'declared',
            'declared',
        );
        static::assertSame([], $record->releaseEvents());
        static::assertEquals(DeclarationSamples::later(2), $record->updatedAt());
    }

    public function testMarkRejected(): void
    {
        $record = DeclarationRecord::record(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::siret(),
            DeclarationSamples::period(),
            DeclarationSamples::fingerprint(),
            DeclarationSamples::accountId(),
            DeclarationSamples::instant(),
        );
        $record->startSubmission(DeclarationSamples::later(1));
        $record->releaseEvents();

        $record->markRejected(DeclarationSamples::errors(), DeclarationSamples::later(2));

        static::assertSame(DeclarationState::Rejected, $record->state());
        static::assertEquals(DeclarationSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationRejected::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame(['ER_API_050_0070'], $event->errorCodes());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkOutcomeUnknown(): void
    {
        $record = DeclarationSamples::record(DeclarationSamples::accountId());
        $record->startSubmission(DeclarationSamples::later(1));
        $record->releaseEvents();

        $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, DeclarationSamples::errors(), DeclarationSamples::later(2));

        static::assertSame(DeclarationState::OutcomeUnknown, $record->state());
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $record->outcomeUnknownReason());
        static::assertEquals(DeclarationSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationOutcomeUnknown::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $event->reason());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkFailed(): void
    {
        $record = self::submitting();

        $record->markFailed(DeclarationSamples::errors(), DeclarationSamples::later(2));

        static::assertSame(DeclarationState::Error, $record->state());
        static::assertEquals(DeclarationSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationSubmissionFailed::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(['ER_API_050_0070'], $event->errorCodes());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkInvalidCredentials(): void
    {
        $record = self::submitting();

        $record->markInvalidCredentials(DeclarationSamples::later(2));

        static::assertSame(DeclarationState::InvalidCredentials, $record->state());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationCredentialsRejected::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testTransitionsAreRefusedFromPending(): void
    {
        $record = DeclarationSamples::record(null);
        $now = DeclarationSamples::later(1);

        DeclarationSamples::assertInvalidTransition(static fn() => $record->markRejected([], $now), 'pending', 'rejected');
        DeclarationSamples::assertInvalidTransition(static fn() => $record->markFailed([], $now), 'pending', 'error');
        DeclarationSamples::assertInvalidTransition(static fn() => $record->markInvalidCredentials($now), 'pending', 'invalid_credentials');
        DeclarationSamples::assertInvalidTransition(static fn() => $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now), 'pending', 'outcome_unknown');
        static::assertSame(DeclarationState::Pending, $record->state());
        static::assertSame([], $record->releaseEvents());
    }

    public function testRetryRequeuesAFailedSubmission(): void
    {
        $record = self::failed();

        $record->retry(DeclarationSamples::later(3));

        static::assertSame(DeclarationState::Pending, $record->state());
        static::assertEquals(DeclarationSamples::errors(), $record->errors());
        static::assertEquals(DeclarationSamples::later(3), $record->updatedAt());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationRequeued::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(DeclarationState::Error, $event->from());
        static::assertSame('2026-11-15T10:03:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testRetryRequeuesInvalidCredentialsWhateverTheAttempts(): void
    {
        $record = self::submitting();
        $record->markInvalidCredentials(DeclarationSamples::later(2));
        $record->releaseEvents();

        $record->retry(DeclarationSamples::later(3), 1);

        static::assertSame(DeclarationState::Pending, $record->state());
        $events = $record->releaseEvents();
        static::assertInstanceOf(DeclarationRequeued::class, $events[0]);
        static::assertSame(DeclarationState::InvalidCredentials, $events[0]->from());
    }

    public function testRetryRefusesToRequeueAnExhaustedRecord(): void
    {
        $record = self::exhausted(5);

        $exception = DeclarationSamples::assertViolationException(
            'declaration_record.max_attempts_reached',
            static fn() => $record->retry(DeclarationSamples::later(200)),
        );

        static::assertInstanceOf(InvariantViolation::class, $exception);
        static::assertSame(['attempts' => 5, 'max_attempts' => 5], $exception->params());
        static::assertSame(DeclarationState::Error, $record->state());
        static::assertSame([], $record->releaseEvents());
    }

    public function testRetryBoundaryAroundTheMaximumNumberOfAttempts(): void
    {
        $four = self::exhausted(4);
        $four->retry(DeclarationSamples::later(300));

        static::assertSame(DeclarationState::Pending, $four->state());
        static::assertSame(4, $four->attempts());
    }

    public function testRetryHonoursACustomMaximum(): void
    {
        $record = self::exhausted(2);

        DeclarationSamples::assertViolation('declaration_record.max_attempts_reached', static fn() => $record->retry(DeclarationSamples::later(200), 2));
        $record->retry(DeclarationSamples::later(201), 3);

        static::assertSame(DeclarationState::Pending, $record->state());
    }

    public function testRetryNeverRequeuesAnUnknownOutcome(): void
    {
        $record = self::unknown();

        DeclarationSamples::assertInvalidTransition(static fn() => $record->retry(DeclarationSamples::later(3)), 'outcome_unknown', 'pending');
        static::assertSame(DeclarationState::OutcomeUnknown, $record->state());
        static::assertSame([], $record->releaseEvents());
    }

    public function testRetryIsRefusedFromOtherStates(): void
    {
        $record = DeclarationSamples::record(null);

        DeclarationSamples::assertInvalidTransition(static fn() => $record->retry(DeclarationSamples::later(3)), 'pending', 'pending');
    }

    public function testCanRetryAndIsExhausted(): void
    {
        $pending = DeclarationSamples::record(null);
        $four = self::exhausted(4);
        $five = self::exhausted(5);

        static::assertTrue($pending->canRetry());
        static::assertFalse($pending->isExhausted());
        static::assertTrue($four->canRetry());
        static::assertFalse($four->isExhausted());
        static::assertFalse($five->canRetry());
        static::assertTrue($five->isExhausted());
        static::assertTrue($five->canRetry(6));
        static::assertFalse($five->isExhausted(6));
        static::assertFalse($four->canRetry(4));
        static::assertTrue($four->isExhausted(4));
    }

    public function testAnExhaustedCheckOnlyConcernsTheErrorState(): void
    {
        $record = self::submitting();
        $record->markInvalidCredentials(DeclarationSamples::later(2));

        static::assertTrue($record->canRetry(1));
        static::assertFalse($record->isExhausted(1));
    }

    public function testResolveAsDeclared(): void
    {
        $record = DeclarationRecord::record(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::siret(),
            DeclarationSamples::period(),
            DeclarationSamples::fingerprint(),
            DeclarationSamples::accountId(),
            DeclarationSamples::instant(),
        );
        $record->startSubmission(DeclarationSamples::later(1));
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, DeclarationSamples::errors(), DeclarationSamples::later(2));
        $record->releaseEvents();

        $record->resolveAsDeclared(Certificate::fromString('87654321'), DeclarationSamples::later(3));

        static::assertSame(DeclarationState::Declared, $record->state());
        $result = $record->result();
        static::assertNotNull($result);
        static::assertTrue($result->isResolution());
        static::assertSame('87654321', $result->certificate()->value());
        static::assertNull($result->declarationId());
        static::assertNull($record->outcomeUnknownReason());
        static::assertSame([], $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationDeclared::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('87654321', $event->certificate()->value());
        static::assertNull($event->declarationId());
        static::assertSame('2026-11-15T10:03:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testResolveAsDeclaredIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        $record = self::submitting();

        DeclarationSamples::assertInvalidTransition(
            static fn() => $record->resolveAsDeclared(Certificate::fromString('87654321'), DeclarationSamples::later(3)),
            'submitting',
            'declared',
        );
        static::assertSame([], $record->releaseEvents());
    }

    public function testResolveAsNotReceived(): void
    {
        $record = self::unknown();

        $record->resolveAsNotReceived(DeclarationSamples::later(3));

        static::assertSame(DeclarationState::Pending, $record->state());
        static::assertNull($record->outcomeUnknownReason());
        static::assertEquals(DeclarationSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(DeclarationRequeued::class, $events[0]);
        static::assertSame(DeclarationState::OutcomeUnknown, $events[0]->from());
        static::assertSame('2026-11-15T10:03:00+00:00', $events[0]->occurredAt()->format('c'));
    }

    public function testResolveAsNotReceivedIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        $record = self::failed();

        DeclarationSamples::assertInvalidTransition(static fn() => $record->resolveAsNotReceived(DeclarationSamples::later(3)), 'error', 'pending');
    }

    public function testIsStaleSubmission(): void
    {
        $record = self::submitting();
        $delay = new DateInterval('PT15M');

        static::assertFalse($record->isStaleSubmission(DeclarationSamples::later(15), $delay));
        static::assertTrue($record->isStaleSubmission(DeclarationSamples::later(16), $delay));
        static::assertFalse(DeclarationSamples::record(null)->isStaleSubmission(DeclarationSamples::later(1000), $delay));
        static::assertFalse(self::failed()->isStaleSubmission(DeclarationSamples::later(1000), $delay));
    }

    public function testEventsAreReleasedInOrderThenForgotten(): void
    {
        $record = DeclarationSamples::record(null);
        $record->startSubmission(DeclarationSamples::later(1));
        $record->markFailed([], DeclarationSamples::later(2));
        $record->retry(DeclarationSamples::later(3));

        static::assertSame(
            [DeclarationSubmissionFailed::class, DeclarationRequeued::class],
            array_map(static fn(DomainEvent $event): string => $event::class, $record->releaseEvents()),
        );
        static::assertSame([], $record->releaseEvents());
    }

    public function testNoEventCarriesTheSiretOrAnAmount(): void
    {
        $record = DeclarationRecord::record(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::siret(),
            DeclarationSamples::period(),
            DeclarationSamples::fingerprint(),
            DeclarationSamples::accountId(),
            DeclarationSamples::instant(),
        );
        $record->startSubmission(DeclarationSamples::later(1));
        $record->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(2));

        foreach ($record->releaseEvents() as $event) {
            $dump = print_r($event, true);
            static::assertStringNotContainsString('12345678901234', $dump, $event::class);
            static::assertStringNotContainsString('1000', $dump, $event::class);
        }
    }
}
