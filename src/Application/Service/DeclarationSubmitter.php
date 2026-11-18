<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use Closure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\DeclarationRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use Throwable;

/**
 * Claims a pending declaration record, sends the declaration (050) and settles the answer (ADR 0003, ADR 0009).
 *
 * The claim is atomic (compare-and-set pending -> submitting): a concurrent caller gets {@see ItemOutcome::NotClaimed}.
 * The URSSAF call is made outside any transaction. Every answer is then settled in a transaction of its own against the
 * record **as re-read when the answer arrives**, the claim being identified by the attempt number it took (settlement
 * table of ADR 0009):
 * - accepted (200 of 050): recorded as declared on every surviving state but declared (late acceptance: reconciled
 *   meanwhile, resolved as not received, requeued, claimed again, rejected, or a record that was superseded: it is then
 *   flagged); on a declared record the first result is kept. It is never discarded: a record that was erased, a save that
 *   fails for another reason or conflicts that outlast {@see self::MAX_RECORDING_ATTEMPTS} attempts are a
 *   {@see DeclarationRecordingFailed} (the call is made, it is never resent);
 * - any other answer only settles its own claim (state submitting, same attempt): on a record it no longer owns it changes
 *   nothing and the caller gets the re-entry outcome of the current state ({@see ItemOutcome::NotClaimed},
 *   {@see ItemOutcome::Replayed}), without any exception. On an owned record, by the column of the table
 *   ({@see SubmissionFailures}):
 *   - 400 ({@see UrssafRequestRejected}): rejected, returned;
 *   - 401 / 403: invalid credentials, returned;
 *   - unknown outcome (5xx, transport error or unreadable answer, {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown}):
 *     outcome unknown, returned, never resent automatically;
 *   - retryable that may have reached URSSAF, or that provably did not (429, token endpoint, local rate limit): error,
 *     then pending again while attempts remain ({@see RetryableUrssafFailure} thrown after the commit) or
 *     {@see ItemOutcome::Exhausted} returned. Method 050 is resent only because the HTTP gateway raised the "may have
 *     reached" failure for a method listed in `retry_safe_writes`;
 *   - local refusal (the credentials were not found, nothing was sent): error, then pending again or exhausted, rethrown
 *     unchanged;
 *   - any other failure (a bug, a foreign gateway): outcome unknown (it may have been sent), rethrown unchanged.
 * Messages of port exceptions carry no personal data and a foreign exception's message is never stored (only its class).
 *
 * One private method per outcome of the settlement table, hence the method count and the complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class DeclarationSubmitter
{
    public const MAX_RECORDING_ATTEMPTS = 3;

    public function __construct(
        private DeclarationRecordRepository $records,
        private TurnoverDeclarer $declarer,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws Throwable {@see RetryableUrssafFailure} when the record is back to pending after a retryable failure
     *                   (committed), {@see DeclarationRecordingFailed} when URSSAF accepted the declaration but the result
     *                   could not be recorded, or a local refusal or an unexpected failure of the call, rethrown unchanged
     *                   after its outcome was committed
     */
    public function submit(DeclarationRecordId $id, TenantId $tenantId, Siret $siret, TurnoverDeclaration $declaration): DeclarationSubmission
    {
        if (!$this->records->claimForSubmission($id, Instant::fromDateTime($this->clock->now()))) {
            return new DeclarationSubmission(
                DeclarationView::fromRecord($this->records->get($id)),
                ItemOutcome::NotClaimed,
            );
        }
        $claimed = $this->records->get($id);
        try {
            $result = $this->declarer->declareTurnover($tenantId, $siret, $declaration);
        } catch (Throwable $failure) {
            return $this->settleFailure($claimed, $failure);
        }

        return $this->declared($claimed, $result);
    }

    /**
     * An accepted 050 is never discarded: it is recorded on whichever state the record has when the answer arrives (see
     * {@see self::settle()}).
     */
    private function declared(DeclarationRecord $claimed, DeclarationResult $result): DeclarationSubmission
    {
        return $this->settle(
            $claimed,
            static function (DeclarationRecord $record, Instant $now) use ($result): ItemOutcome {
                $record->markDeclared($result, $now);

                return ItemOutcome::Declared;
            },
            $result,
        );
    }

    /**
     * @throws Throwable {@see RetryableUrssafFailure}, or the failure itself when it is a local refusal or an unexpected
     *                   failure
     */
    private function settleFailure(DeclarationRecord $claimed, Throwable $failure): DeclarationSubmission
    {
        return match (SubmissionFailures::classify($failure, true)) {
            SubmissionFailureKind::Rejected => $this->rejected($claimed, $failure),
            SubmissionFailureKind::InvalidCredentials => $this->invalidCredentials($claimed),
            SubmissionFailureKind::OutcomeUnknown => $this->unknown($claimed, $failure),
            SubmissionFailureKind::Resendable, SubmissionFailureKind::NotSent => $this->retryLater($claimed, $failure),
            SubmissionFailureKind::LocalRefusal => $this->rethrown($this->requeue($claimed, $failure), $failure),
            SubmissionFailureKind::Unexpected => $this->rethrown($this->unknown($claimed, $failure), $failure),
        };
    }

    /**
     * The failure is thrown once it was settled; a submission that was not the claim's own (not claimed, replayed) is
     * returned as it is.
     *
     * @throws Throwable
     */
    private function rethrown(DeclarationSubmission $submission, Throwable $failure): DeclarationSubmission
    {
        return self::settled($submission) ? throw $failure : $submission;
    }

    /**
     * Error, then pending again while attempts remain: {@see RetryableUrssafFailure} once committed. Exhausted, or a record
     * the claim no longer owns, is returned.
     *
     * @throws RetryableUrssafFailure
     */
    private function retryLater(DeclarationRecord $claimed, Throwable $failure): DeclarationSubmission
    {
        $submission = $this->requeue($claimed, $failure);
        if ($submission->outcome !== ItemOutcome::RetryLater) {
            return $submission;
        }
        /** @var RetryableException $failure SubmissionFailures only classifies a retryable failure as resendable or not sent */
        throw RetryableUrssafFailure::causedBy($failure);
    }

    private function invalidCredentials(DeclarationRecord $claimed): DeclarationSubmission
    {
        return $this->settle($claimed, static function (DeclarationRecord $record, Instant $now): ItemOutcome {
            $record->markInvalidCredentials($now);

            return ItemOutcome::InvalidCredentials;
        }, null);
    }

    private function unknown(DeclarationRecord $claimed, Throwable $failure): DeclarationSubmission
    {
        return $this->settle($claimed, static function (DeclarationRecord $record, Instant $now) use ($failure): ItemOutcome {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, UrssafErrorTranslator::failureErrors($failure), $now);

            return ItemOutcome::OutcomeUnknown;
        }, null);
    }

    private function rejected(DeclarationRecord $claimed, Throwable $failure): DeclarationSubmission
    {
        return $this->settle($claimed, static function (DeclarationRecord $record, Instant $now) use ($failure): ItemOutcome {
            /** @var UrssafRequestRejected $failure SubmissionFailures classifies only a rejection as rejected */
            $record->markRejected(UrssafErrorTranslator::rejectionErrors($failure), $now);

            return ItemOutcome::Rejected;
        }, null);
    }

    /**
     * submitting -> error, then pending again while attempts remain. {@see ItemOutcome::Exhausted} when they do not (the
     * record is left in error), {@see ItemOutcome::RetryLater} when it was requeued.
     */
    private function requeue(DeclarationRecord $claimed, Throwable $failure): DeclarationSubmission
    {
        return $this->settle($claimed, function (DeclarationRecord $record, Instant $now) use ($failure): ItemOutcome {
            $record->markFailed(UrssafErrorTranslator::failureErrors($failure), $now);
            $maxAttempts = $this->policy->maxSubmissionAttempts();
            if (!$record->canRetry($maxAttempts)) {
                return ItemOutcome::Exhausted;
            }
            $record->retry($now, $maxAttempts);

            return ItemOutcome::RetryLater;
        }, null);
    }

    /**
     * True when the answer changed the record (the claim was still its own, or the answer was accepted).
     */
    private static function settled(DeclarationSubmission $submission): bool
    {
        return $submission->outcome !== ItemOutcome::NotClaimed && $submission->outcome !== ItemOutcome::Replayed;
    }

    /**
     * Settles the answer of a call against the record as it is stored now, in one transaction (save, events), and
     * describes the record with the outcome. A conflict (another writer saved in between) re-reads and settles again, at
     * most {@see self::MAX_RECORDING_ATTEMPTS} times; the last conflict, or any other failure of the recording, is
     * rethrown unchanged. When the answer is accepted ($accepted) a failure of the recording is instead a
     * {@see DeclarationRecordingFailed}, the call being made and never resent.
     *
     * @param Closure(DeclarationRecord, Instant): ItemOutcome $transition
     * @param-immediately-invoked-callable $transition
     */
    private function settle(DeclarationRecord $claimed, Closure $transition, ?DeclarationResult $accepted): DeclarationSubmission
    {
        for ($attempt = 1; $attempt < self::MAX_RECORDING_ATTEMPTS; ++$attempt) {
            try {
                return $this->settleOnce($claimed, $transition, $accepted);
            } catch (Throwable $failure) {
                if (!self::isConflict($failure)) {
                    throw self::unrecorded($claimed, $accepted, $attempt, $failure);
                }
            }
        }

        try {
            return $this->settleOnce($claimed, $transition, $accepted);
        } catch (Throwable $failure) {
            throw self::unrecorded($claimed, $accepted, self::MAX_RECORDING_ATTEMPTS, $failure);
        }
    }

    private static function isConflict(Throwable $failure): bool
    {
        return $failure instanceof InvariantViolation
            && $failure->violationCode() === DeclarationRecordRepository::CONCURRENT_MODIFICATION;
    }

    /**
     * What the caller gets when the settlement failed: the failure itself, except for an accepted answer, which must never
     * be lost silently: the call is made and never resent.
     */
    private static function unrecorded(
        DeclarationRecord $claimed,
        ?DeclarationResult $accepted,
        int $attempts,
        Throwable $failure,
    ): Throwable {
        return $accepted instanceof DeclarationResult
            ? DeclarationRecordingFailed::after(
                $claimed->id(),
                $accepted,
                $attempts,
                $failure instanceof DomainException ? $failure->violationCode() : $failure::class,
            )
            : $failure;
    }

    /**
     * @param Closure(DeclarationRecord, Instant): ItemOutcome $transition
     */
    private function settleOnce(DeclarationRecord $claimed, Closure $transition, ?DeclarationResult $accepted): DeclarationSubmission
    {
        try {
            $record = $this->records->get($claimed->id());
        } catch (RecordNotFound $erased) {
            // Erased (ForgetAccount): an accepted result has nowhere to go, any other answer has nothing to settle.
            if ($accepted instanceof DeclarationResult) {
                throw $erased;
            }

            return new DeclarationSubmission(DeclarationView::fromRecord($claimed), ItemOutcome::NotClaimed);
        }
        if ($accepted instanceof DeclarationResult && $record->state() === DeclarationState::Declared) {
            // First result kept.
            return new DeclarationSubmission(DeclarationView::fromRecord($record), ItemOutcome::Declared);
        }
        if (!$accepted instanceof DeclarationResult && !self::owns($claimed, $record)) {
            return new DeclarationSubmission(DeclarationView::fromRecord($record), self::reentry($record->state()));
        }
        $outcome = $this->unitOfWork->run(function (RecordedEvents $events) use ($record, $transition): ItemOutcome {
            $outcome = $transition($record, Instant::fromDateTime($this->clock->now()));
            $this->records->save($record);
            $events->record(...$record->releaseEvents());

            return $outcome;
        });

        return new DeclarationSubmission(DeclarationView::fromRecord($record), $outcome);
    }

    /**
     * The claim is identified by the attempt number it took: it owns the record while it is submitting that attempt.
     */
    private static function owns(DeclarationRecord $claimed, DeclarationRecord $current): bool
    {
        return $current->state() === DeclarationState::Submitting && $current->attempts() === $claimed->attempts();
    }

    /**
     * What DeclareTurnover would answer to the same declaration: in flight or waiting (not claimed), or already settled
     * (replayed).
     */
    private static function reentry(DeclarationState $state): ItemOutcome
    {
        return match ($state) {
            DeclarationState::Pending, DeclarationState::Submitting, DeclarationState::Error => ItemOutcome::NotClaimed,
            DeclarationState::Declared,
            DeclarationState::Rejected,
            DeclarationState::OutcomeUnknown,
            DeclarationState::InvalidCredentials => ItemOutcome::Replayed,
        };
    }
}
