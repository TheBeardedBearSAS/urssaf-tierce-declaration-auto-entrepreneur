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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;

/**
 * Re-entry of a rejected payment: the new record supersedes the previous one (ADR 0009).
 */
final class PaymentRecordSupersessionTest extends TestCase
{
    private static function rejected(?bool $withAccount): PaymentRecord
    {
        $record = PaymentSamples::record($withAccount === true ? PaymentSamples::accountId() : null);
        $record->startSubmission(PaymentSamples::later(1));
        $record->markRejected(PaymentSamples::errors(), PaymentSamples::later(2));
        $record->releaseEvents();

        return $record;
    }

    public function testSupersedeCreatesTheNewCurrentRecord(): void
    {
        $previous = self::rejected(true);
        $order = PaymentOrder::of(MonthlyPeriod::of(2026, 11), Euros::of(1600), null, null);

        $next = $previous->supersede(PaymentSamples::recordId('record-2'), $order, PaymentSamples::later(5));

        static::assertSame('record-2', $next->id()->value());
        static::assertSame('tenant-1', $next->tenantId()->value());
        static::assertSame('payment-ref-1', $next->reference()->value());
        static::assertSame('12345678901234', $next->siret()->toString());
        static::assertSame('account-1', $next->accountId()?->value());
        static::assertSame('2026-11', $next->period()->toString());
        static::assertSame(1600, $next->amount()->toInt());
        static::assertNull($next->rum());
        static::assertSame('record-1', $next->supersedes()?->value());
        static::assertNull($next->supersededBy());
        static::assertTrue($next->isCurrent());
        static::assertSame(PaymentState::Pending, $next->state());
        static::assertSame(0, $next->attempts());
        static::assertEquals(PaymentSamples::later(5), $next->createdAt());
        static::assertEquals(PaymentSamples::later(5), $next->updatedAt());

        $events = $next->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(PaymentRecorded::class, $event);
        static::assertSame('record-2', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-11', $event->period()->toString());
        static::assertSame('record-1', $event->supersedes()?->value());
        static::assertSame('2026-11-15T10:05:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testTheNewFingerprintFollowsTheNewOrder(): void
    {
        $previous = self::rejected(false);

        $same = $previous->supersede(PaymentSamples::recordId('record-2'), PaymentSamples::order(), PaymentSamples::later(5));
        $other = self::rejected(false)->supersede(PaymentSamples::recordId('record-3'), PaymentSamples::otherOrder(), PaymentSamples::later(5));

        static::assertTrue($same->hasSameFingerprintAs(PaymentSamples::fingerprint()));
        static::assertTrue($other->hasSameFingerprintAs(PaymentSamples::otherFingerprint()));
        static::assertNull($same->accountId());
    }

    public function testSupersedeLinksThePreviousRecordAndKeepsItsState(): void
    {
        $previous = self::rejected(false);

        $previous->supersede(PaymentSamples::recordId('record-2'), PaymentSamples::order(), PaymentSamples::later(5));

        static::assertSame('record-2', $previous->supersededBy()?->value());
        static::assertFalse($previous->isCurrent());
        static::assertSame(PaymentState::Rejected, $previous->state());
        static::assertEquals(PaymentSamples::later(2), $previous->updatedAt());
        static::assertNull($previous->supersedes());
        static::assertSame([], $previous->releaseEvents());
    }

    public function testASupersededRecordCannotBeSupersededAgain(): void
    {
        $previous = self::rejected(false);
        $previous->supersede(PaymentSamples::recordId('record-2'), PaymentSamples::order(), PaymentSamples::later(5));

        PaymentSamples::assertViolation(
            'payment_record.already_superseded',
            static fn(): PaymentRecord => $previous->supersede(PaymentSamples::recordId('record-3'), PaymentSamples::order(), PaymentSamples::later(6)),
        );
        static::assertSame('record-2', $previous->supersededBy()?->value());
    }

    /**
     * @return iterable<string, array{PaymentState}>
     */
    public static function statesThatAreNotRejected(): iterable
    {
        yield 'pending' => [PaymentState::Pending];
        yield 'submitting' => [PaymentState::Submitting];
        yield 'paid' => [PaymentState::Paid];
        yield 'outcome_unknown' => [PaymentState::OutcomeUnknown];
        yield 'error' => [PaymentState::Error];
        yield 'invalid_credentials' => [PaymentState::InvalidCredentials];
    }

    private static function inState(PaymentState $state): PaymentRecord
    {
        $record = PaymentSamples::record(null);
        if ($state === PaymentState::Pending) {
            return $record;
        }
        $record->startSubmission(PaymentSamples::later(1));
        $later = PaymentSamples::later(2);
        if ($state === PaymentState::Paid) {
            $record->markPaid(PaymentSamples::result(), $later);
        }
        if ($state === PaymentState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $later);
        }
        if ($state === PaymentState::Error) {
            $record->markFailed([], $later);
        }
        if ($state === PaymentState::InvalidCredentials) {
            $record->markInvalidCredentials($later);
        }

        return $record;
    }

    #[DataProvider('statesThatAreNotRejected')]
    public function testOnlyARejectedPaymentIsSuperseded(PaymentState $state): void
    {
        $record = self::inState($state);

        $exception = PaymentSamples::assertViolationException(
            'payment_record.supersede_requires_rejection',
            static fn(): PaymentRecord => $record->supersede(PaymentSamples::recordId('record-2'), PaymentSamples::order(), PaymentSamples::later(9)),
        );

        static::assertSame(['state' => $state->value], $exception->params());
        static::assertNull($record->supersededBy());
        static::assertTrue($record->isCurrent());
    }

    public function testAnAlreadySupersededRecordIsReportedBeforeTheRejectionRule(): void
    {
        $previous = self::rejected(false);
        $previous->supersede(PaymentSamples::recordId('record-2'), PaymentSamples::order(), PaymentSamples::later(5));
        $previous->markPaid(PaymentSamples::result(), PaymentSamples::later(6));

        PaymentSamples::assertViolation(
            'payment_record.already_superseded',
            static fn(): PaymentRecord => $previous->supersede(PaymentSamples::recordId('record-3'), PaymentSamples::order(), PaymentSamples::later(7)),
        );
    }

    public function testALateAcceptanceOnASupersededRecordIsRecorded(): void
    {
        $previous = self::rejected(false);
        $previous->supersede(PaymentSamples::recordId('record-2'), PaymentSamples::order(), PaymentSamples::later(5));

        $previous->markPaid(PaymentSamples::result(), PaymentSamples::later(6));

        static::assertSame(PaymentState::Paid, $previous->state());
        static::assertFalse($previous->isCurrent());
    }
}
