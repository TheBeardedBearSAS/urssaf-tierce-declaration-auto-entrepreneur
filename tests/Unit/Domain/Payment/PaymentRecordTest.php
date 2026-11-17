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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;

/**
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class PaymentRecordTest extends TestCase
{
    private static function submitting(): PaymentRecord
    {
        $record = PaymentSamples::record(null);
        $record->startSubmission(PaymentSamples::later(1));
        $record->releaseEvents();

        return $record;
    }

    private static function failed(): PaymentRecord
    {
        $record = self::submitting();
        $record->markFailed(PaymentSamples::errors(), PaymentSamples::later(2));
        $record->releaseEvents();

        return $record;
    }

    private static function unknown(): PaymentRecord
    {
        $record = self::submitting();
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, PaymentSamples::errors(), PaymentSamples::later(2));
        $record->releaseEvents();

        return $record;
    }

    private static function exhausted(int $attempts): PaymentRecord
    {
        $record = PaymentSamples::record(null);
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $record->startSubmission(PaymentSamples::later($attempt * 10));
            $record->markFailed([], PaymentSamples::later($attempt * 10 + 1));
            if ($attempt < $attempts) {
                $record->retry(PaymentSamples::later($attempt * 10 + 2), 100);
            }
        }
        $record->releaseEvents();

        return $record;
    }

    public function testRecord(): void
    {
        $record = PaymentRecord::record(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::siret(),
            PaymentSamples::order(),
            PaymentSamples::accountId(),
            PaymentSamples::instant(),
        );

        static::assertSame('record-1', $record->id()->value());
        static::assertSame('tenant-1', $record->tenantId()->value());
        static::assertSame('payment-ref-1', $record->reference()->value());
        static::assertSame('12345678901234', $record->siret()->toString());
        static::assertSame(1500, $record->amount()->toInt());
        static::assertSame('T1234567891234567891234568912345678', $record->rum()?->value());
        static::assertSame('2026-10', $record->period()->toString());
        static::assertSame(Periodicity::Monthly, $record->periodicity());
        static::assertTrue($record->fingerprint()->equals(PaymentSamples::fingerprint()));
        static::assertSame('account-1', $record->accountId()?->value());
        static::assertNull($record->supersedes());
        static::assertNull($record->supersededBy());
        static::assertTrue($record->isCurrent());
        static::assertEquals(PaymentSamples::instant(), $record->createdAt());
        static::assertSame(PaymentState::Pending, $record->state());
        static::assertSame(PaymentState::Pending, $record->status()->state());
        static::assertSame(0, $record->attempts());
        static::assertNull($record->lastAttemptAt());
        static::assertSame([], $record->errors());
        static::assertNull($record->result());
        static::assertNull($record->outcomeUnknownReason());
        static::assertEquals(PaymentSamples::instant(), $record->updatedAt());

        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentRecorded::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertNull($event->supersedes());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
        static::assertSame([], $record->releaseEvents());
    }

    public function testAnOrderWithoutRumNorIban(): void
    {
        $order = PaymentOrder::of(PaymentSamples::period(), Euros::of(0), null, null);
        $record = PaymentRecord::record(
            PaymentSamples::recordId('record-3'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::siret(),
            $order,
            null,
            PaymentSamples::instant(),
        );

        static::assertNull($record->rum());
        static::assertSame(0, $record->amount()->toInt());
        static::assertTrue($record->hasSameFingerprintAs(PaymentFingerprint::of(PaymentSamples::tenantId(), PaymentSamples::siret(), $order)));
    }

    public function testTheAccountIsOptional(): void
    {
        $record = PaymentSamples::record(null);

        static::assertNull($record->accountId());
    }

    public function testAQuarterlyPeriodIsStoredAsPartsAndRebuilt(): void
    {
        $record = PaymentRecord::record(
            PaymentSamples::recordId('record-q'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::siret(),
            PaymentOrder::of(QuarterlyPeriod::of(2026, 3), Euros::of(1500), null, null),
            null,
            PaymentSamples::instant(),
        );

        static::assertSame(Periodicity::Quarterly, $record->periodicity());
        static::assertInstanceOf(QuarterlyPeriod::class, $record->period());
        static::assertSame('2026-Q3', $record->period()->toString());
        $events = $record->releaseEvents();
        static::assertInstanceOf(PaymentRecorded::class, $events[0]);
        static::assertSame('2026-Q3', $events[0]->period()->toString());
    }

    public function testAMonthlyPeriodIsRebuilt(): void
    {
        $period = PaymentSamples::record(null)->period();

        static::assertInstanceOf(MonthlyPeriod::class, $period);
        static::assertSame(2026, $period->year());
        static::assertSame(10, $period->month());
    }

    public function testHasSameFingerprintAs(): void
    {
        $record = PaymentSamples::record(null);

        static::assertTrue($record->hasSameFingerprintAs(PaymentSamples::fingerprint()));
        static::assertFalse($record->hasSameFingerprintAs(PaymentSamples::otherFingerprint()));
    }

    public function testStartSubmission(): void
    {
        $record = PaymentSamples::record(null);

        $record->startSubmission(PaymentSamples::later(1));

        static::assertSame(PaymentState::Submitting, $record->state());
        static::assertSame(1, $record->attempts());
        static::assertEquals(PaymentSamples::later(1), $record->lastAttemptAt());
        static::assertEquals(PaymentSamples::later(1), $record->updatedAt());
        static::assertSame([], $record->releaseEvents());
    }

    public function testStartSubmissionIsRefusedWhenNotPending(): void
    {
        $record = self::submitting();

        PaymentSamples::assertInvalidTransition(
            static fn() => $record->startSubmission(PaymentSamples::later(3)),
            'submitting',
            'submitting',
        );
        static::assertSame(1, $record->attempts());
    }

    public function testMarkPaid(): void
    {
        $record = PaymentSamples::record(PaymentSamples::accountId());
        $record->startSubmission(PaymentSamples::later(1));
        $record->releaseEvents();

        $record->markPaid(PaymentSamples::result(), PaymentSamples::later(2));

        static::assertSame(PaymentState::Paid, $record->state());
        static::assertEquals(PaymentSamples::result(), $record->result());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentOrdered::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('12345678', $event->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $event->paymentId());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame('T1234567891234567891234568912345678', $event->rum()?->value());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkPaidIsTheLateAcceptanceOfEveryStateButPaid(): void
    {
        foreach ([PaymentSamples::record(null), self::failed(), self::unknown(), self::submitting()] as $record) {
            $record->markPaid(PaymentSamples::result(), PaymentSamples::later(9));

            static::assertSame(PaymentState::Paid, $record->state());
            static::assertSame([], $record->errors());
        }
    }

    public function testMarkPaidKeepsTheFirstResult(): void
    {
        $record = self::submitting();
        $record->markPaid(PaymentSamples::result(), PaymentSamples::later(2));
        $record->releaseEvents();

        PaymentSamples::assertInvalidTransition(
            static fn() => $record->markPaid(PaymentSamples::result(), PaymentSamples::later(3)),
            'paid',
            'paid',
        );
        static::assertSame([], $record->releaseEvents());
        static::assertEquals(PaymentSamples::later(2), $record->updatedAt());
    }

    public function testMarkRejected(): void
    {
        $record = PaymentRecord::record(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::siret(),
            PaymentSamples::order(),
            PaymentSamples::accountId(),
            PaymentSamples::instant(),
        );
        $record->startSubmission(PaymentSamples::later(1));
        $record->releaseEvents();

        $record->markRejected(PaymentSamples::errors(), PaymentSamples::later(2));

        static::assertSame(PaymentState::Rejected, $record->state());
        static::assertEquals(PaymentSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentRejected::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame(['ER_API_060_0070'], $event->errorCodes());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkOutcomeUnknown(): void
    {
        $record = PaymentSamples::record(PaymentSamples::accountId());
        $record->startSubmission(PaymentSamples::later(1));
        $record->releaseEvents();

        $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, PaymentSamples::errors(), PaymentSamples::later(2));

        static::assertSame(PaymentState::OutcomeUnknown, $record->state());
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $record->outcomeUnknownReason());
        static::assertEquals(PaymentSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentOutcomeUnknown::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $event->reason());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkFailed(): void
    {
        $record = self::submitting();

        $record->markFailed(PaymentSamples::errors(), PaymentSamples::later(2));

        static::assertSame(PaymentState::Error, $record->state());
        static::assertEquals(PaymentSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentSubmissionFailed::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(['ER_API_060_0070'], $event->errorCodes());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testMarkInvalidCredentials(): void
    {
        $record = self::submitting();

        $record->markInvalidCredentials(PaymentSamples::later(2));

        static::assertSame(PaymentState::InvalidCredentials, $record->state());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentCredentialsRejected::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('2026-11-15T10:02:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testTransitionsAreRefusedFromPending(): void
    {
        $record = PaymentSamples::record(null);
        $now = PaymentSamples::later(1);

        PaymentSamples::assertInvalidTransition(static fn() => $record->markRejected([], $now), 'pending', 'rejected');
        PaymentSamples::assertInvalidTransition(static fn() => $record->markFailed([], $now), 'pending', 'error');
        PaymentSamples::assertInvalidTransition(static fn() => $record->markInvalidCredentials($now), 'pending', 'invalid_credentials');
        PaymentSamples::assertInvalidTransition(static fn() => $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now), 'pending', 'outcome_unknown');
        static::assertSame(PaymentState::Pending, $record->state());
        static::assertSame([], $record->releaseEvents());
    }

    public function testRetryRequeuesAFailedSubmission(): void
    {
        $record = self::failed();

        $record->retry(PaymentSamples::later(3));

        static::assertSame(PaymentState::Pending, $record->state());
        static::assertEquals(PaymentSamples::errors(), $record->errors());
        static::assertEquals(PaymentSamples::later(3), $record->updatedAt());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentRequeued::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(PaymentState::Error, $event->from());
        static::assertSame('2026-11-15T10:03:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testRetryRequeuesInvalidCredentialsWhateverTheAttempts(): void
    {
        $record = self::submitting();
        $record->markInvalidCredentials(PaymentSamples::later(2));
        $record->releaseEvents();

        $record->retry(PaymentSamples::later(3), 1);

        static::assertSame(PaymentState::Pending, $record->state());
        $events = $record->releaseEvents();
        static::assertInstanceOf(PaymentRequeued::class, $events[0]);
        static::assertSame(PaymentState::InvalidCredentials, $events[0]->from());
    }

    public function testRetryRefusesToRequeueAnExhaustedRecord(): void
    {
        $record = self::exhausted(5);

        $exception = PaymentSamples::assertViolationException(
            'payment_record.max_attempts_reached',
            static fn() => $record->retry(PaymentSamples::later(200)),
        );

        static::assertInstanceOf(InvariantViolation::class, $exception);
        static::assertSame(['attempts' => 5, 'max_attempts' => 5], $exception->params());
        static::assertSame(PaymentState::Error, $record->state());
        static::assertSame([], $record->releaseEvents());
    }

    public function testRetryBoundaryAroundTheMaximumNumberOfAttempts(): void
    {
        $four = self::exhausted(4);
        $four->retry(PaymentSamples::later(300));

        static::assertSame(PaymentState::Pending, $four->state());
        static::assertSame(4, $four->attempts());
    }

    public function testRetryHonoursACustomMaximum(): void
    {
        $record = self::exhausted(2);

        PaymentSamples::assertViolation('payment_record.max_attempts_reached', static fn() => $record->retry(PaymentSamples::later(200), 2));
        $record->retry(PaymentSamples::later(201), 3);

        static::assertSame(PaymentState::Pending, $record->state());
    }

    public function testRetryNeverRequeuesAnUnknownOutcome(): void
    {
        $record = self::unknown();

        PaymentSamples::assertInvalidTransition(static fn() => $record->retry(PaymentSamples::later(3)), 'outcome_unknown', 'pending');
        static::assertSame(PaymentState::OutcomeUnknown, $record->state());
        static::assertSame([], $record->releaseEvents());
    }

    public function testRetryIsRefusedFromOtherStates(): void
    {
        $record = PaymentSamples::record(null);

        PaymentSamples::assertInvalidTransition(static fn() => $record->retry(PaymentSamples::later(3)), 'pending', 'pending');
    }

    public function testCanRetryAndIsExhausted(): void
    {
        $pending = PaymentSamples::record(null);
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
        $record->markInvalidCredentials(PaymentSamples::later(2));

        static::assertTrue($record->canRetry(1));
        static::assertFalse($record->isExhausted(1));
    }

    public function testResolveAsPaid(): void
    {
        $record = PaymentRecord::record(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::siret(),
            PaymentSamples::order(),
            PaymentSamples::accountId(),
            PaymentSamples::instant(),
        );
        $record->startSubmission(PaymentSamples::later(1));
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, PaymentSamples::errors(), PaymentSamples::later(2));
        $record->releaseEvents();

        $record->resolveAsPaid(Certificate::fromString('87654321'), PaymentSamples::later(3));

        static::assertSame(PaymentState::Paid, $record->state());
        $result = $record->result();
        static::assertNotNull($result);
        static::assertTrue($result->isResolution());
        static::assertSame('87654321', $result->certificate()->value());
        static::assertNull($result->paymentId());
        static::assertNull($record->outcomeUnknownReason());
        static::assertSame([], $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentOrdered::class, $event);
        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('87654321', $event->certificate()->value());
        static::assertNull($event->paymentId());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame('T1234567891234567891234568912345678', $event->rum()?->value());
        static::assertSame('2026-11-15T10:03:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testResolveAsPaidIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        $record = self::submitting();

        PaymentSamples::assertInvalidTransition(
            static fn() => $record->resolveAsPaid(Certificate::fromString('87654321'), PaymentSamples::later(3)),
            'submitting',
            'paid',
        );
        static::assertSame([], $record->releaseEvents());
    }

    public function testResolveAsNotReceived(): void
    {
        $record = self::unknown();

        $record->resolveAsNotReceived(PaymentSamples::later(3));

        static::assertSame(PaymentState::Pending, $record->state());
        static::assertNull($record->outcomeUnknownReason());
        static::assertEquals(PaymentSamples::errors(), $record->errors());
        $events = $record->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(PaymentRequeued::class, $events[0]);
        static::assertSame(PaymentState::OutcomeUnknown, $events[0]->from());
        static::assertSame('2026-11-15T10:03:00+00:00', $events[0]->occurredAt()->format('c'));
    }

    public function testResolveAsNotReceivedIsOnlyPossibleFromAnUnknownOutcome(): void
    {
        $record = self::failed();

        PaymentSamples::assertInvalidTransition(static fn() => $record->resolveAsNotReceived(PaymentSamples::later(3)), 'error', 'pending');
    }

    public function testIsStaleSubmission(): void
    {
        $record = self::submitting();
        $delay = new DateInterval('PT15M');

        static::assertFalse($record->isStaleSubmission(PaymentSamples::later(15), $delay));
        static::assertTrue($record->isStaleSubmission(PaymentSamples::later(16), $delay));
        static::assertFalse(PaymentSamples::record(null)->isStaleSubmission(PaymentSamples::later(1000), $delay));
        static::assertFalse(self::failed()->isStaleSubmission(PaymentSamples::later(1000), $delay));
    }

    public function testEventsAreReleasedInOrderThenForgotten(): void
    {
        $record = PaymentSamples::record(null);
        $record->startSubmission(PaymentSamples::later(1));
        $record->markFailed([], PaymentSamples::later(2));
        $record->retry(PaymentSamples::later(3));

        static::assertSame(
            [PaymentSubmissionFailed::class, PaymentRequeued::class],
            array_map(static fn(DomainEvent $event): string => $event::class, $record->releaseEvents()),
        );
        static::assertSame([], $record->releaseEvents());
    }

    public function testNoEventCarriesTheSiretOrAnAmount(): void
    {
        $record = PaymentRecord::record(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::siret(),
            PaymentSamples::order(),
            PaymentSamples::accountId(),
            PaymentSamples::instant(),
        );
        $record->startSubmission(PaymentSamples::later(1));
        $record->markPaid(PaymentSamples::result(), PaymentSamples::later(2));

        foreach ($record->releaseEvents() as $event) {
            $dump = print_r($event, true);
            static::assertStringNotContainsString('12345678901234', $dump, $event::class);
            static::assertStringNotContainsString('1500', $dump, $event::class);
            static::assertStringNotContainsString('FR76', $dump, $event::class);
        }
    }
}
