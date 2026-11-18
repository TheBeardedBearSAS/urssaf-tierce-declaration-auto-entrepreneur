<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Command;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePayments;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePaymentsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * ReconcileStalePayments: the payments left submitting move to outcome_unknown, every tenant, nothing is sent (ADR 0003).
 */
final class ReconcileStalePaymentsHandlerTest extends TestCase
{
    public function testStalePaymentsOfEveryTenantBecomeAnUnknownOutcomeOldestFirst(): void
    {
        $world = new PaymentWorld();
        self::submittingSince($world, 'late', '2026-11-17T08:30:00Z', 'tenant-1', 'ref-late');
        self::submittingSince($world, 'early', '2026-11-17T08:00:00Z', 'tenant-2', 'ref-early');

        $report = $this->reconcile($world, null);

        static::assertEquals(
            [
                new BatchItem('early', ItemOutcome::ManualReview, ['stale_submission']),
                new BatchItem('late', ItemOutcome::ManualReview, ['stale_submission']),
            ],
            $report->items(),
        );
        foreach (['early', 'late'] as $id) {
            $record = $world->payments->get(PaymentRecordId::fromString($id));
            static::assertSame(PaymentState::OutcomeUnknown, $record->state());
            static::assertSame(OutcomeUnknownReason::StaleSubmission, $record->outcomeUnknownReason());
            static::assertSame([], $record->errors());
            static::assertSame(PaymentWorld::NOW, $record->updatedAt()->toIso8601());
        }
        static::assertSame(['PaymentOutcomeUnknown', 'PaymentOutcomeUnknown'], $world->publisher->names());
        static::assertInstanceOf(PaymentOutcomeUnknown::class, $world->publisher->events()[0]);
        static::assertSame([], $world->gateway->calls());
        static::assertSame(
            ['begin', 'save:early', 'append:urssaf.tdae.payment.outcome_unknown', 'commit', 'publish:PaymentOutcomeUnknown', 'begin', 'save:late', 'append:urssaf.tdae.payment.outcome_unknown', 'commit', 'publish:PaymentOutcomeUnknown'],
            $world->journal->entries(),
        );
    }

    public function testOnlyThePaymentsSubmittingForAtLeastTheStaleDelayAreReconciled(): void
    {
        $world = new PaymentWorld();
        // Now is 09:00 and the delay 15 minutes: the limit is 08:45, included.
        self::submittingSince($world, 'at-limit', '2026-11-17T08:45:00Z', 'tenant-1', 'ref-1');
        self::submittingSince($world, 'fresh', '2026-11-17T08:45:01Z', 'tenant-1', 'ref-2');
        $world->paymentIn('pending', PaymentState::Pending, 0);
        $world->paymentWith('paid', PaymentState::Paid, 1, PaymentWorld::order(), 'ref-3', PaymentWorld::SIRET, 'tenant-1');

        $report = $this->reconcile($world, null);

        static::assertEquals([new BatchItem('at-limit', ItemOutcome::ManualReview, ['stale_submission'])], $report->items());
        static::assertSame(PaymentState::Submitting, $world->payments->get(PaymentRecordId::fromString('fresh'))->state());
    }

    public function testTheDelayIsThePolicyOne(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withStaleSubmissionAfter(new DateInterval('PT2H')));
        self::submittingSince($world, 'one-hour', '2026-11-17T08:00:00Z', 'tenant-1', 'ref-1');
        self::submittingSince($world, 'three-hours', '2026-11-17T06:00:00Z', 'tenant-1', 'ref-2');

        static::assertEquals(
            [new BatchItem('three-hours', ItemOutcome::ManualReview, ['stale_submission'])],
            $this->reconcile($world, null)->items(),
        );
    }

    public function testTheLimitIsCappedByThePolicy(): void
    {
        $world = new PaymentWorld();
        self::submittingSince($world, 'a', '2026-11-17T08:00:00Z', 'tenant-1', 'ref-a');
        self::submittingSince($world, 'b', '2026-11-17T08:10:00Z', 'tenant-1', 'ref-b');
        self::submittingSince($world, 'c', '2026-11-17T08:20:00Z', 'tenant-1', 'ref-c');

        $first = $this->reconcile($world, 2);
        $second = $this->reconcile($world, null);

        static::assertSame(['a', 'b'], array_map(static fn(BatchItem $item): string => $item->subject, $first->items()));
        static::assertSame(['c'], array_map(static fn(BatchItem $item): string => $item->subject, $second->items()));
    }

    public function testNothingToReconcileIsAnEmptyReport(): void
    {
        $world = new PaymentWorld();

        static::assertSame(0, $this->reconcile($world, null)->total());
        static::assertSame([], $world->journal->entries());
    }

    public function testARecordChangedByAnotherProcessIsSkippedAndTheOthersAreStillReconciled(): void
    {
        $world = new PaymentWorld();
        self::submittingSince($world, 'a', '2026-11-17T08:00:00Z', 'tenant-1', 'ref-a');
        self::submittingSince($world, 'b', '2026-11-17T08:10:00Z', 'tenant-1', 'ref-b');
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $other = $inner->get(PaymentRecordId::fromString('a'));
            $other->markPaid(PaymentWorld::paid(), Instant::fromIso8601(PaymentWorld::NOW));
            $inner->save($other);
        });

        $report = $this->reconcile($world, null);

        static::assertEquals(
            [
                new BatchItem('a', ItemOutcome::NotClaimed, ['payment_record.concurrent_modification']),
                new BatchItem('b', ItemOutcome::ManualReview, ['stale_submission']),
            ],
            $report->items(),
        );
        static::assertSame(PaymentState::Paid, $world->payments->get(PaymentRecordId::fromString('a'))->state());
        static::assertSame(PaymentState::OutcomeUnknown, $world->payments->get(PaymentRecordId::fromString('b'))->state());
        static::assertSame(['PaymentOutcomeUnknown'], $world->publisher->names());
    }

    public function testAnotherViolationIsRethrown(): void
    {
        $world = new PaymentWorld();
        self::submittingSince($world, 'a', '2026-11-17T08:00:00Z', 'tenant-1', 'ref-a');
        $world->payments->beforeNextSave(static function (): never {
            throw InvariantViolation::because('storage.broken', 'The storage is broken.');
        });

        try {
            $this->reconcile($world, null);
            static::fail('Expected the violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame('storage.broken', $violation->violationCode());
        }
        static::assertSame(PaymentState::Submitting, $world->payments->get(PaymentRecordId::fromString('a'))->state());
    }

    private function reconcile(PaymentWorld $world, ?int $limit): BatchReport
    {
        return (new ReconcileStalePaymentsHandler($world->payments, $world->clock, $world->unitOfWork, $world->policy))(new ReconcileStalePayments($limit));
    }

    private static function submittingSince(PaymentWorld $world, string $id, string $since, string $tenant, string $reference): PaymentRecord
    {
        $record = PaymentRecord::record(
            PaymentRecordId::fromString($id),
            PaymentWorld::tenant($tenant),
            PaymentWorld::reference($reference),
            PaymentWorld::siret(),
            PaymentWorld::order(),
            null,
            Instant::fromIso8601(PaymentWorld::RECORDED_AT),
        );
        $record->startSubmission(Instant::fromIso8601($since));
        $record->releaseEvents();
        $world->payments->add($record);

        return $record;
    }
}
