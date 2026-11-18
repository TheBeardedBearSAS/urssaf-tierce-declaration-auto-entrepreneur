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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarations;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use Throwable;

final class ReconcileStaleDeclarationsHandlerTest extends TestCase
{
    public function testAStaleSubmissionBecomesAnUnknownOutcomeForManualReview(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Submitting, 1);

        $report = $this->reconcile($world, null);

        static::assertEquals([new BatchItem('record-1', ItemOutcome::ManualReview, ['stale_submission'])], $report->items());
        $record = $world->records->get(DeclarationRecordId::fromString('record-1'));
        static::assertSame(DeclarationState::OutcomeUnknown, $record->state());
        static::assertSame('stale_submission', $record->outcomeUnknownReason()?->value);
        static::assertSame([], $record->errors());
        static::assertSame(
            ['begin', 'save:record-1', 'append:urssaf.tdae.declaration.outcome_unknown', 'commit', 'publish:DeclarationOutcomeUnknown'],
            $world->journal->entries(),
        );
        static::assertSame([], $world->gateway->calls());
    }

    public function testEveryTenantIsReconciledOldestAttemptFirstEachInATransactionOfItsOwn(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(
            self::submitting('record-late', 'tenant-1', 9, '2026-11-17T08:30:00Z'),
            self::submitting('record-other-tenant', 'tenant-2', 10, '2026-11-17T08:10:00Z'),
            self::submitting('record-early', 'tenant-1', 8, '2026-11-17T08:00:00Z'),
        );

        $report = $this->reconcile($world, null);

        static::assertSame(['record-early', 'record-other-tenant', 'record-late'], array_map(static fn(BatchItem $item): string => $item->subject, $report->items()));
        static::assertSame(3, $report->count(ItemOutcome::ManualReview));
        static::assertSame(
            ['begin', 'save:record-early', 'append:urssaf.tdae.declaration.outcome_unknown', 'commit', 'publish:DeclarationOutcomeUnknown'],
            \array_slice($world->journal->entries(), 0, 5),
        );
        static::assertCount(3, array_keys($world->journal->entries(), 'commit', true));
    }

    public function testASubmissionIsStaleExactlyAfterTheConfiguredDelay(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(self::submitting('record-1', 'tenant-1', 10, '2026-11-17T08:45:00Z'));

        $world->clock->moveTo('2026-11-17T08:59:59Z');
        static::assertSame(0, $this->reconcile($world, null)->total());

        $world->clock->moveTo('2026-11-17T09:00:00Z');
        static::assertSame(1, $this->reconcile($world, null)->total());
    }

    public function testTheDelayComesFromThePolicy(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withStaleSubmissionAfter(new DateInterval('PT1H')));
        $world->records->add(self::submitting('record-1', 'tenant-1', 10, '2026-11-17T08:30:00Z'));

        static::assertSame(0, $this->reconcile($world, null)->total());
    }

    public function testOnlySubmittingRecordsAreReconciled(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        static::assertSame(0, $this->reconcile($world, null)->total());
        static::assertSame([], $world->journal->entries());
    }

    public function testTheLimitIsAppliedAndCappedByThePolicy(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withPageSizes(1, 2));
        $world->records->add(
            self::submitting('record-1', 'tenant-1', 7, '2026-11-17T08:00:00Z'),
            self::submitting('record-2', 'tenant-1', 8, '2026-11-17T08:01:00Z'),
            self::submitting('record-3', 'tenant-1', 9, '2026-11-17T08:02:00Z'),
        );

        static::assertSame(['record-1'], array_map(static fn(BatchItem $item): string => $item->subject, $this->reconcile($world, null)->items()));
        static::assertSame(['record-2', 'record-3'], array_map(static fn(BatchItem $item): string => $item->subject, $this->reconcile($world, 50)->items()));
    }

    public function testARecordAnotherProcessChangedIsSkippedAndTheRunGoesOn(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(
            self::submitting('record-1', 'tenant-1', 9, '2026-11-17T08:00:00Z'),
            self::submitting('record-2', 'tenant-1', 10, '2026-11-17T08:01:00Z'),
        );
        $world->beforeNextSaveCommit(static function () use ($world): void {
            $other = $world->records->get(DeclarationRecordId::fromString('record-1'));
            $other->markDeclared(DeclarationWorld::accepted(), Instant::fromIso8601(DeclarationWorld::NOW));
            $world->records->inner()->save($other);
        });

        $report = $this->reconcile($world, null);

        static::assertEquals(
            [
                new BatchItem('record-1', ItemOutcome::NotClaimed, ['declaration_record.concurrent_modification']),
                new BatchItem('record-2', ItemOutcome::ManualReview, ['stale_submission']),
            ],
            $report->items(),
        );
        static::assertSame(DeclarationState::Declared, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
        static::assertSame(DeclarationState::OutcomeUnknown, $world->records->get(DeclarationRecordId::fromString('record-2'))->state());
    }

    public function testAnotherViolationIsRethrown(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(self::submitting('record-1', 'tenant-1', 10, '2026-11-17T08:00:00Z'));
        $failure = InvariantViolation::because('storage.broken', 'The storage is broken.');
        $world->beforeNextSave(static function () use ($failure): never {
            throw $failure;
        });

        try {
            $this->reconcile($world, null);
            static::fail('Expected the violation.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(DeclarationState::Submitting, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
    }

    private function reconcile(DeclarationWorld $world, ?int $limit): BatchReport
    {
        return (new ReconcileStaleDeclarationsHandler($world->records, $world->clock, $world->unitOfWork, $world->policy))(new ReconcileStaleDeclarations($limit));
    }

    private static function submitting(string $id, string $tenant, int $month, string $attemptAt): DeclarationRecord
    {
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            DeclarationWorld::tenant($tenant),
            DeclarationWorld::siret(),
            DeclarationWorld::period($month),
            DeclarationFingerprint::of(DeclarationWorld::tenant($tenant), DeclarationWorld::siret(), DeclarationWorld::period($month), DeclarationWorld::turnover()),
            null,
            Instant::fromIso8601(DeclarationWorld::RECORDED_AT),
        );
        $record->startSubmission(Instant::fromIso8601($attemptAt));
        $record->releaseEvents();

        return $record;
    }
}
