<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;

/**
 * Reconciles the payments (every tenant) left submitting for at least the policy "staleSubmissionAfter" (oldest first):
 * the process that sent them died or hung, so URSSAF may hold them. URSSAF offers no lookup (ADR 0003): each one becomes
 * outcome_unknown (stale submission), in a transaction of its own, for manual review ({@see ItemOutcome::ManualReview}).
 * Nothing is sent to URSSAF. A record another process changed since it was read (it answered after all, or an operator
 * resolved it: "payment_record.concurrent_modification") is skipped and reported as {@see ItemOutcome::NotClaimed} with
 * that code: it is not stale any more.
 */
final readonly class ReconcileStalePaymentsHandler
{
    public const STALE_SUBMISSION = 'stale_submission';

    public function __construct(
        private PaymentRecordRepository $records,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    public function __invoke(ReconcileStalePayments $command): BatchReport
    {
        $olderThan = Instant::fromDateTime($this->clock->now()->sub($this->policy->staleSubmissionAfter()));
        $items = [];
        foreach ($this->records->findStaleSubmissions($olderThan, $this->policy->pageSize($command->limit)) as $record) {
            $items[] = $this->reconcile($record);
        }

        return BatchReport::of(...$items);
    }

    private function reconcile(PaymentRecord $record): BatchItem
    {
        try {
            $this->unitOfWork->run(function (RecordedEvents $events) use ($record): void {
                $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], Instant::fromDateTime($this->clock->now()));
                $this->records->save($record);
                $events->record(...$record->releaseEvents());
            });
        } catch (InvariantViolation $violation) {
            if ($violation->violationCode() !== PaymentRecordRepository::CONCURRENT_MODIFICATION) {
                throw $violation;
            }

            return new BatchItem($record->id()->value(), ItemOutcome::NotClaimed, [$violation->violationCode()]);
        }

        return new BatchItem($record->id()->value(), ItemOutcome::ManualReview, [self::STALE_SUBMISSION]);
    }
}
